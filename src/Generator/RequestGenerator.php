<?php

namespace PostmanGeneratorBundle\Generator;

use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Inflector\Inflector;
use Dunglas\ApiBundle\Api\Operation\OperationInterface;
use Dunglas\ApiBundle\Api\ResourceInterface;
use Dunglas\ApiBundle\Mapping\ClassMetadataFactoryInterface;
use Dunglas\ApiBundle\Mapping\Loader\AttributesLoader;
use PostmanGeneratorBundle\Faker\Guesser\Guesser;
use PostmanGeneratorBundle\Model\Request;
use PostmanGeneratorBundle\Model\Test;
use PostmanGeneratorBundle\RequestParser\RequestParserChain;
use Ramsey\Uuid\Uuid;

class RequestGenerator implements GeneratorInterface
{
    /**
     * @var ClassMetadataFactoryInterface
     */
    private $classMetadataFactory;

    /**
     * @var AttributesLoader
     */
    private $attributesLoader;

    /**
     * @var AuthenticationGenerator
     */
    private $authenticationGenerator;

    /**
     * @var Guesser
     */
    private $guesser;

    /**
     * @var RequestParserChain
     */
    private $requestParser;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var string
     */
    private $authentication;

    /**
     * @param ClassMetadataFactoryInterface $classMetadataFactory
     * @param AttributesLoader              $attributesLoader
     * @param AuthenticationGenerator       $authenticationGenerator
     * @param Guesser                       $guesser
     * @param RequestParserChain            $requestParser
     * @param Reader                        $reader
     * @param string                        $baseUrl
     * @param string                        $authentication
     */
    public function __construct(
        ClassMetadataFactoryInterface $classMetadataFactory,
        AttributesLoader $attributesLoader,
        AuthenticationGenerator $authenticationGenerator,
        Guesser $guesser,
        RequestParserChain $requestParser,
        Reader $reader,
        $baseUrl,
        $authentication = null
    ) {
        $this->classMetadataFactory = $classMetadataFactory;
        $this->attributesLoader = $attributesLoader;
        $this->authenticationGenerator = $authenticationGenerator;
        $this->guesser = $guesser;
        $this->requestParser = $requestParser;
        $this->reader = $reader;
        $this->baseUrl = $baseUrl;
        $this->authentication = $authentication;
    }

    /**
     * {@inheritdoc}
     *
     * @return Request[]
     */
    public function generate(ResourceInterface $resource = null)
    {
        /** @var OperationInterface[] $operations */
        $operations = array_merge($resource->getCollectionOperations(), $resource->getItemOperations());
        $requests = [];
        $classMetadata = $this->classMetadataFactory->getMetadataFor(
            $resource->getEntityClass(),
            $resource->getNormalizationGroups(),
            $resource->getDenormalizationGroups(),
            $resource->getValidationGroups()
        );

        foreach ($operations as $operation) {
            $route = $operation->getRoute();
            foreach ($route->getMethods() as $method) {
                // @todo Move default name generation to dedicated RequestParser
                $isCollection = 'DunglasApiBundle:Resource:cget' === $route->getDefault('_controller');
                $name = $this->generateDefaultName($method, $resource->getShortName(), $isCollection);
                if (isset($operation->getContext()['hydra:title'])) {
                    $name = $operation->getContext()['hydra:title'];
                }

                $request = new Request();
                $request->setId((string) Uuid::uuid4());
                $request->setUrl($this->baseUrl.$route->getPath());
                $request->setMethod($method);
                $request->setName($name);

                // Authentication
                if (null !== $this->authentication) {
                    $this->authenticationGenerator->get($this->authentication)->generate($request);
                }

                // Manage request data & ContentType header
                // @todo Move to dedicated RequestParser
                if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    $request->addHeader('Content-Type', 'application/json');
                    $request->setDataMode(Request::DATA_MODE_RAW);

                    $rawModeData = [];
                    foreach ($classMetadata->getAttributes() as $attributeMetadata) {
                        $groups = $this->reader->getPropertyAnnotation(
                            $classMetadata->getReflectionClass()->getProperty($attributeMetadata->getName()),
                            'Symfony\Component\Serializer\Annotation\Groups'
                        );
                        if (
                            $attributeMetadata->isIdentifier()
                            || !$attributeMetadata->isReadable()
                            || !count(array_intersect($groups ? $groups->getGroups() : [], $resource->getDenormalizationGroups() ?: []))
                        ) {
                            continue;
                        }

                        $rawModeData[$attributeMetadata->getName()] = $this->guesser->guess($attributeMetadata);
                    }
                    $request->setRawModeData($rawModeData);
                }

                // Add tests
                // @todo Move to dedicated RequestParser
                switch ($method) {
                    case 'POST':
                        $request->addTest(new Test('Successful POST request', 'responseCode.code === 201 || responseCode.code === 202'));
                        $request->addTest(new Test('Content-Type is correct', 'postman.getResponseHeader("Content-Type") === "application/ld+json"'));
                        break;
                    case 'PUT':
                    case 'PATCH':
                    case 'GET':
                        $request->addTest(new Test(sprintf('Successful %s request', $method), 'responseCode.code === 200'));
                        $request->addTest(new Test('Content-Type is correct', 'postman.getResponseHeader("Content-Type") === "application/ld+json"'));
                        break;
                    case 'DELETE':
                        $request->addTest(new Test('Successful DELETE request', 'responseCode.code === 204'));
                        break;
                }

                $this->requestParser->parse($request);
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param string $method
     * @param string $name
     * @param bool   $isCollection
     *
     * @return string
     */
    private function generateDefaultName($method, $name, $isCollection = true)
    {
        switch ($method) {
            case 'POST':
                return sprintf('Create %s', Inflector::camelize($name));
            case 'PUT':
            case 'PATCH':
                return sprintf('Update %s', Inflector::camelize($name));
            case 'DELETE':
                return sprintf('Delete %s', Inflector::camelize($name));
            case 'GET':
                if ($isCollection) {
                    return sprintf('Get %s list', Inflector::pluralize(Inflector::camelize($name)));
                }

                return sprintf('Get %s', Inflector::camelize($name));
        }
    }
}
