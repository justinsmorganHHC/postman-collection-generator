<?php

namespace PostmanGeneratorBundle\Generator;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Inflector\Inflector;
use Dunglas\ApiBundle\Api\Operation\OperationInterface;
use Dunglas\ApiBundle\Api\ResourceInterface;
use Dunglas\ApiBundle\Mapping\ClassMetadataFactoryInterface;
use Dunglas\ApiBundle\Mapping\Loader\AttributesLoader;
use PostmanGeneratorBundle\Faker\Guesser\Guesser;
use PostmanGeneratorBundle\Model\Request;
use PostmanGeneratorBundle\RequestParser\RequestParserFactory;
use Ramsey\Uuid\Uuid;

class RequestGenerator implements GeneratorInterface
{
    /**
     * @var AuthenticationGenerator
     */
    private $authenticationGenerator;

    /**
     * @var ClassMetadataFactoryInterface
     */
    private $classMetadataFactory;

    /**
     * @var string
     */
    private $authentication;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var Guesser
     */
    private $guesser;

    /**
     * @var AttributesLoader
     */
    private $attributesLoader;

    /**
     * @var RequestParserFactory
     */
    private $requestParserFactory;

    /**
     * @param ClassMetadataFactoryInterface $classMetadataFactory
     * @param AttributesLoader              $attributesLoader
     * @param AuthenticationGenerator       $authenticationGenerator
     * @param Guesser                       $guesser
     * @param RequestParserFactory          $requestParserFactory
     * @param string                        $baseUrl
     * @param string                        $authentication
     */
    public function __construct(
        ClassMetadataFactoryInterface $classMetadataFactory,
        AttributesLoader $attributesLoader,
        AuthenticationGenerator $authenticationGenerator,
        Guesser $guesser,
        RequestParserFactory $requestParserFactory,
        $baseUrl,
        $authentication = null
    ) {
        $this->classMetadataFactory = $classMetadataFactory;
        $this->attributesLoader = $attributesLoader;
        $this->authenticationGenerator = $authenticationGenerator;
        $this->guesser = $guesser;
        $this->requestParserFactory = $requestParserFactory;
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
        $annotationReader = new AnnotationReader();

        foreach ($operations as $operation) {
            $route = $operation->getRoute();
            foreach ($route->getMethods() as $method) {
                $isCollection = 'DunglasApiBundle:Resource:cget' === $route->getDefault('_controller');
                $name = $this->generateDefaultName($method, $resource->getShortName(), $isCollection);
                if (isset($operation->getContext()['hydra:title'])) {
                    $name = $operation->getContext()['hydra:title'];
                }

                $request = new Request();
                $request->setId((string) Uuid::uuid4());
                $request->setUrl($route->getPath());
                $request->setMethod($method);
                $request->setName($name);

                // Authentication
                if (null !== $this->authentication) {
                    $this->authenticationGenerator->get($this->authentication)->generate($request);
                }

                // Manage request data & ContentType header
                if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    $request->addHeader('Content-Type', 'application/json');
                    $request->setDataMode(Request::DATA_MODE_RAW);

                    $rawModeData = [];
                    $classMetadata = $this->classMetadataFactory->getMetadataFor(
                        $resource->getEntityClass(),
                        $resource->getNormalizationGroups(),
                        $resource->getDenormalizationGroups(),
                        $resource->getValidationGroups()
                    );
                    foreach ($classMetadata->getAttributes() as $attributeMetadata) {
                        $groups = $annotationReader->getPropertyAnnotation(
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

                $this->requestParserFactory->parse($request);
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function generateUrl($url)
    {
        return rtrim($this->baseUrl, '/').str_ireplace('{id}', 1, $url);
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