<?php

declare(strict_types=1);

namespace AsyncAws\CodeGenerator\Generator;

use AsyncAws\CodeGenerator\File\FileWriter;
use AsyncAws\Core\Exception\InvalidArgument;
use AsyncAws\Core\Result;
use AsyncAws\Core\StreamableBody;
use AsyncAws\Core\XmlBuilder;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\Parameter;
use Nette\PhpGenerator\PhpNamespace;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Generate API client methods and result classes.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ApiGenerator
{
    /**
     * @var FileWriter
     */
    private $fileWriter;

    /**
     * All public classes take a definition as first parameter.
     *
     * @var ServiceDefinition
     */
    private $definition;

    public function __construct(string $srcDirectory)
    {
        $this->fileWriter = new FileWriter($srcDirectory);
    }

    /**
     * Update the API client with a new function call.
     */
    public function generateOperation(ServiceDefinition $definition, string $operationName, string $service, string $baseNamespace): void
    {
        $this->definition = $definition;
        $operation = $definition->getOperation($operationName);
        $inputShape = $definition->getShape($operation['input']['shape']) ?? [];

        $inputClassName = $operation['input']['shape'];
        $this->generateInputClass($service, $apiVersion = $definition->getApiVersion(), $operationName, $baseNamespace . '\\Input', $inputClassName, true);

        $namespace = ClassFactory::fromExistingClass(\sprintf('%s\\%sClient', $baseNamespace, $service));
        $safeClassName = $this->safeClassName($inputClassName);
        $namespace->addUse($baseNamespace . '\\Input\\' . $safeClassName);
        $classes = $namespace->getClasses();
        $class = $classes[\array_key_first($classes)];
        if (null !== $prefix = $definition->getEndpointPrefix()) {
            if (!$class->hasMethod('getServiceCode')) {
                $class->addMethod('getServiceCode')
                    ->setReturnType('string')
                    ->setVisibility(ClassType::VISIBILITY_PROTECTED)
                ;
            }
            $class->getMethod('getServiceCode')
                ->setBody("return '$prefix';");
        }
        if (null !== $signatureVersion = $definition->getSignatureVersion()) {
            if (!$class->hasMethod('getSignatureVersion')) {
                $class->addMethod('getSignatureVersion')
                    ->setReturnType('string')
                    ->setVisibility(ClassType::VISIBILITY_PROTECTED)
                ;
            }
            $class->getMethod('getSignatureVersion')
                ->setBody("return '$signatureVersion';");
        }

        $class->removeMethod(\lcfirst($operation['name']));
        $method = $class->addMethod(\lcfirst($operation['name']));
        if (null !== $documentation = $definition->getOperationDocumentation($operationName)) {
            $method->addComment($this->parseDocumentation($documentation));
        }

        if (isset($operation['documentationUrl'])) {
            $method->addComment('@see ' . $operation['documentationUrl']);
        } elseif (null !== $prefix) {
            $method->addComment('@see https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-' . $prefix . '-' . $apiVersion . '.html#' . \strtolower($operation['name']));
        }

        $method->addComment('@param array{');
        $this->addMethodComment($method, $inputShape, $baseNamespace . '\\Input');
        $method->addComment('}|' . $safeClassName . ' $input');
        $operationMethodParameter = $method->addParameter('input');
        if (empty($this->definition->getShape($inputClassName)['required'])) {
            $operationMethodParameter->setDefaultValue([]);
        }

        if (isset($operation['output'])) {
            $outputClass = \sprintf('%s\\Result\\%s', $baseNamespace, $this->safeClassName($operation['output']['shape']));
            $method->setReturnType($outputClass);
            $namespace->addUse($outputClass);
            $namespace->addUse(XmlBuilder::class);
        } else {
            $method->setReturnType(Result::class);
            $namespace->addUse(Result::class);
        }

        // Generate method body
        $this->setMethodBody($inputShape, $method, $operation, $inputClassName);

        $this->fileWriter->write($namespace);
    }

    /**
     * Generate classes for the output. Ie, the result of the API call.
     */
    public function generateResultClass(ServiceDefinition $definition, string $operationName, string $baseNamespace, string $className, bool $root, bool $useTrait)
    {
        $this->definition = $definition;
        $this->doGenerateResultClass($baseNamespace, $className, $root, $useTrait, $operationName);
    }

    public function generateOutputTrait(ServiceDefinition $definition, string $operationName, string $baseNamespace, string $className)
    {
        $this->definition = $definition;
        $traitName = $className . 'Trait';

        $namespace = new PhpNamespace($baseNamespace);
        $trait = $namespace->addTrait($traitName);

        $namespace->addUse(ResponseInterface::class);
        $namespace->addUse(HttpClientInterface::class);

        $isNew = !trait_exists($baseNamespace . '\\' . $traitName);
        $this->resultClassPopulateResult($operationName, $className, $isNew, $namespace, $trait);

        $this->fileWriter->write($namespace);
    }

    private function parseDocumentation(string $documentation, bool $singleLine = false): string
    {
        $s = \strtr($documentation, ['> <' => '><']);
        $s = explode("\n", trim(\strtr($s, [
            '<p>' => '',
            '</p>' => "\n",
        ])))[0];

        $s = \strtr($s, [
            '<code>' => '`',
            '</code>' => '`',
            '<i>' => '*',
            '</i>' => '*',
            '<b>' => '**',
            '</b>' => '**',
        ]);

        \preg_match_all('/<a href="([^"]*)">/', $s, $matches);
        $s = \preg_replace('/<a href="[^"]*">([^<]*)<\/a>/', '$1', $s);

        $s = \strtr($s, [
            '<a>' => '',
            '</a>' => '',
        ]);

        if (false !== \strpos($s, '<')) {
            throw new \InvalidArgumentException('remaining HTML code in documentation: ' . $s);
        }

        if (!$singleLine) {
            $s = wordwrap($s, 117);
            $s .= "\n";
            foreach ($matches[1] as $link) {
                $s .= "\n@see $link";
            }
        }

        return $s;
    }

    private function doGenerateResultClass(string $baseNamespace, string $className, bool $root = false, ?bool $useTrait = null, ?string $operationName = null): void
    {
        $inputShape = $this->definition->getShape($className);

        $namespace = new PhpNamespace($baseNamespace);
        $class = $namespace->addClass($this->safeClassName($className));

        if ($root) {
            $namespace->addUse(Result::class);
            $class->addExtend(Result::class);

            $traitName = $baseNamespace . '\\' . $className . 'Trait';
            if ($useTrait) {
                $class->addTrait($traitName);
            } else {
                $namespace->addUse(ResponseInterface::class);
                $namespace->addUse(HttpClientInterface::class);
                $this->resultClassPopulateResult($operationName, $className, false, $namespace, $class);
                $this->fileWriter->delete($traitName);
            }
        } else {
            // Named constructor
            $this->resultClassAddNamedConstructor($baseNamespace, $inputShape, $class);
        }

        $this->resultClassAddProperties($baseNamespace, $root, $inputShape, $className, $class, $namespace);
        // should be called After Properties injection
        if ($operationName && null !== $pagination = $this->definition->getOperationPagination($operationName)) {
            $this->generateOutputPagination($pagination, $className, $baseNamespace, $class);
        }

        $this->fileWriter->write($namespace);
    }

    private function generateOutputPagination(array $pagination, string $className, string $baseNamespace, ClassType $class)
    {
        if (empty($pagination['result_key'])) {
            throw new \RuntimeException('This is not implemented yet');
        }

        $class->addImplement(\IteratorAggregate::class);
        $iteratorBody = '';
        $iteratorTypes = [];
        foreach ((array) $pagination['result_key'] as $resultKey) {
            $iteratorBody .= strtr('yield from $this->PROPERTY_NAME;
            ', [
                'PROPERTY_NAME' => $resultKey,
            ]);
            $resultShapeName = $this->definition->getShape($className)['members'][$resultKey]['shape'];
            $resultShape = $this->definition->getShape($resultShapeName);

            if ('list' !== $resultShape['type']) {
                throw new \RuntimeException('Cannot generate a pagination for a non-iterable result');
            }

            $listShape = $this->definition->getShape($resultShape['member']['shape']);
            if ('structure' !== $listShape['type']) {
                $iteratorTypes[] = $iteratorType = $this->toPhpType($listShape['type']);
            } else {
                $iteratorTypes[] = $iteratorType = $this->safeClassName($resultShape['member']['shape']);
            }

            $getter = 'get' . $resultKey;
            if (!$class->hasMethod($getter)) {
                throw new \RuntimeException(sprintf('Unable to find the method "%s" in "%s"', $getter, $className));
            }
            $getterBody = strtr('
                $this->initialize();

                if ($currentPageOnly) {
                    return $this->PROPERTY_NAME;
                }
                while (true) {
                    yield from $this->PROPERTY_NAME;

                    // TODO load next results
                    break;
                }
            ', [
                'PROPERTY_NAME' => $resultKey,
            ]);
            $method = $class->getMethod($getter);
            $method
                ->setParameters([(new Parameter('currentPageOnly'))->setType('bool')->setDefaultValue(false)])
                ->setReturnType('iterable')
                ->setComment('@param bool $currentPageOnly When true, iterates over items of the current page. Otherwise also fetch items in the next pages.')
                ->addComment("@return iterable<$iteratorType>")
                ->setBody($getterBody);
        }

        $iteratorType = implode('|', $iteratorTypes);

        $iteratorBody = strtr('
            $this->initialize();

            while (true) {
                ITERATE_PROPERTIES_CODE

                // TODO load next results
                break;
            }
        ', [
            'ITERATE_PROPERTIES_CODE' => $iteratorBody,
        ]);

        $class->removeMethod('getIterator');
        $class->addMethod('getIterator')
            ->setReturnType(\Traversable::class)
            ->addComment('Iterates over ' . implode(' then ', (array) $pagination['result_key']))
            ->addComment("@return \Traversable<$iteratorType>")
            ->setBody($iteratorBody)
        ;
    }

    /**
     * Generate classes for the input.
     */
    private function generateInputClass(string $service, string $apiVersion, string $operationName, string $baseNamespace, string $className, bool $root = false)
    {
        $operation = $this->definition->getOperation($operationName);
        $shapes = $this->definition->getShapes();
        $documentations = $this->definition->getShapesDocumentation();
        $inputShape = $shapes[$className] ?? [];
        $members = $inputShape['members'];

        $namespace = new PhpNamespace($baseNamespace);
        $class = $namespace->addClass($this->safeClassName($className));
        // Add named constructor
        $class->addMethod('create')->setStatic(true)->setReturnType('self')->setBody(
            <<<PHP
return \$input instanceof self ? \$input : new self(\$input);
PHP
        )->addParameter('input');
        $constructor = $class->addMethod('__construct');

        if ($root) {
            if (isset($operation['documentationUrl'])) {
                $constructor->addComment('@see ' . $operation['documentationUrl']);
            }
        }

        $constructor->addComment('@param array{');
        $this->addMethodComment($constructor, $inputShape, $baseNamespace);
        $constructor->addComment('} $input');
        $inputParameter = $constructor->addParameter('input')->setType('array');
        if (empty($inputShape['required'])) {
            $inputParameter->setDefaultValue([]);
        }
        $constructorBody = '';
        $requiredProperties = [];

        foreach ($members as $name => $data) {
            $parameterType = $data['shape'];
            $memberShape = $shapes[$parameterType];
            $nullable = true;
            if ('structure' === $memberShape['type']) {
                $this->generateInputClass($service, $apiVersion, $operationName, $baseNamespace, $parameterType);
                $returnType = $baseNamespace . '\\' . $parameterType;
                $constructorBody .= sprintf('$this->%s = isset($input["%s"]) ? %s::create($input["%s"]) : null;' . "\n", $name, $name, $this->safeClassName($parameterType), $name);
            } elseif ('list' === $memberShape['type']) {
                $listItemShapeName = $memberShape['member']['shape'];
                $listItemShape = $this->definition->getShape($listItemShapeName);
                $nullable = false;

                // Is this a list of objects?
                if ('structure' === $listItemShape['type']) {
                    $this->generateInputClass($service, $apiVersion, $operationName, $baseNamespace, $listItemShapeName);
                    $parameterType = $listItemShapeName . '[]';
                    $returnType = $baseNamespace . '\\' . $listItemShapeName;
                    $constructorBody .= sprintf('$this->%s = array_map(function($item) { return %s::create($item); }, $input["%s"] ?? []);' . "\n", $name, $this->safeClassName(
                        $listItemShapeName
                    ), $name);
                } else {
                    // It is a scalar, like a string
                    $parameterType = $listItemShape['type'] . '[]';
                    $constructorBody .= sprintf('$this->%s = $input["%s"] ?? [];' . "\n", $name, $name);
                }
            } elseif ($data['streaming'] ?? false) {
                $parameterType = 'string|resource|\Closure';
                $returnType = null;
            } else {
                $returnType = $parameterType = $this->toPhpType($memberShape['type']);
                if ('\DateTimeImmutable' !== $parameterType) {
                    $constructorBody .= sprintf('$this->%s = $input["%s"] ?? null;' . "\n", $name, $name);
                } else {
                    $constructorBody .= sprintf('$this->%s = !isset($input["%s"]) ? null : ($input["%s"] instanceof \DateTimeInterface ? $input["%s"] : new \DateTimeImmutable($input["%s"]));' . "\n", $name, $name, $name, $name, $name);
                    $parameterType = $returnType = '\DateTimeInterface';
                }
            }

            $property = $class->addProperty($name)->setPrivate();
            if (null !== $propertyDocumentation = $this->definition->getParameterDocumentation($className, $name, $data['shape'])) {
                $property->addComment($this->parseDocumentation($propertyDocumentation));
            }

            if (\in_array($name, $shapes[$className]['required'] ?? [])) {
                $requiredProperties[] = $name;
                $property->addComment('@required');
            }
            $property->addComment('@var ' . $parameterType . ($nullable ? '|null' : ''));

            $returnType = '[]' === substr($parameterType, -2) ? 'array' : $returnType;

            $class->addMethod('get' . $name)
                ->setReturnType($returnType)
                ->setReturnNullable($nullable)
                ->setBody(
                    <<<PHP
return \$this->{$name};
PHP
                );

            $class->addMethod('set' . $name)
                ->setReturnType('self')
                ->setBody(
                    <<<PHP
\$this->{$name} = \$value;
return \$this;
PHP
                )
                ->addParameter('value')->setType($returnType)->setNullable($nullable)
            ;
        }

        $constructor->setBody($constructorBody);
        if ($root) {
            $this->inputClassRequestGetters($inputShape, $class, $operationName, $apiVersion);
        }

        // Add validate()
        $namespace->addUse(InvalidArgument::class);
        $validateBody = '';

        if (!empty($requiredProperties)) {
            $requiredArray = '\'' . implode("', '", $requiredProperties) . '\'';

            $validateBody = <<<PHP
foreach ([$requiredArray] as \$name) {
    if (null === \$this->\$name) {
        throw new InvalidArgument(sprintf('Missing parameter "%s" when validating the "%s". The value cannot be null.', \$name, __CLASS__));
    }
}

PHP;
        }

        foreach ($members as $name => $data) {
            $memberShape = $shapes[$data['shape']];
            $type = $memberShape['type'] ?? null;
            if ('structure' === $type) {
                $validateBody .= 'if ($this->' . $name . ') $this->' . $name . '->validate();' . "\n";
            } elseif ('list' === $type) {
                $listItemShapeName = $memberShape['member']['shape'];
                // is the list item an object?
                $type = $this->definition->getShape($listItemShapeName)['type'];
                if ('structure' === $type) {
                    $validateBody .= 'foreach ($this->' . $name . ' as $item) $item->validate();' . "\n";
                }
            }
        }

        $class->addMethod('validate')->setPublic()->setReturnType('void')->setBody(empty($validateBody) ? '// There are no required properties' : $validateBody);

        $this->fileWriter->write($namespace);
    }

    private function inputClassRequestGetters(array $inputShape, ClassType $class, string $operation, string $apiVersion): void
    {
        foreach (['header' => '$headers', 'querystring' => '$query', 'payload' => '$payload'] as $requestPart => $varName) {
            $body[$requestPart] = $varName . ' = [];' . "\n";
            if ('payload' === $requestPart) {
                $body[$requestPart] = $varName . " = ['Action' => '$operation', 'Version' => '$apiVersion'];\n";
            }
            foreach ($inputShape['members'] as $name => $data) {
                // If location is not specified, it will go in the request body.
                $location = $data['location'] ?? 'payload';
                if ($location === $requestPart) {
                    $body[$requestPart] .= 'if ($this->' . $name . ' !== null) ' . $varName . '["' . ($data['locationName'] ?? $name) . '"] = $this->' . $name . ';' . "\n";
                }
            }

            $body[$requestPart] .= 'return ' . $varName . ';' . "\n";
        }

        $class->addMethod('requestHeaders')->setReturnType('array')->setBody($body['header']);
        $class->addMethod('requestQuery')->setReturnType('array')->setBody($body['querystring']);
        $class->addMethod('requestBody')->setReturnType('array')->setBody($body['payload']);

        foreach ($inputShape['members'] as $name => $data) {
            if ('uri' === ($data['location'] ?? null)) {
                if (!isset($body['uri'])) {
                    $body['uri'] = '$uri = [];' . "\n";
                }
                $body['uri'] .= <<<PHP
\$uri['{$data['locationName']}'] = \$this->$name ?? '';

PHP;
            }
        }

        $requestUri = $this->definition->getOperation($operation)['http']['requestUri'];
        $body['uri'] = $body['uri'] ?? '';
        $body['uri'] .= 'return "' . str_replace(['{', '+}', '}'], ['{$uri[\'', '}', '\']}'], $requestUri) . '";';

        $class->addMethod('requestUri')->setReturnType('string')->setBody($body['uri']);
    }

    private function parseXmlResponse(string $currentInput, ?string $memberName, array $memberData)
    {
        if (!empty($memberData['xmlAttribute'])) {
            $input = $currentInput . '[' . var_export($memberData['locationName'], true) . ']';
        } elseif (isset($memberData['locationName'])) {
            $input = $currentInput . '->' . $memberData['locationName'];
        } elseif ($memberName) {
            $input = $currentInput . '->' . $memberName;
        } else {
            $input = $currentInput;
        }

        $shapeName = $memberData['shape'];
        $shape = $this->definition->getShape($shapeName);
        switch ($shape['type']) {
            case 'list':
                return $this->parseXmlResponseList($shapeName, $input);
            case 'structure':
                return $this->parseXmlResponseStructure($shapeName, $input);
            case 'map':
                return $this->parseXmlResponseMap($shapeName, $input);
            case 'string':
            case 'boolean':
            case 'integer':
            case 'long':
            case 'timestamp':
            case 'blob':
                return $this->parseXmlResponseScalar($shapeName, $input);
            default:
                throw new \RuntimeException(sprintf('Type %s is not yet implemented', $shape['type']));
        }
    }

    private function parseXmlResponseRoot(string $shapeName): string
    {
        $shape = $this->definition->getShape($shapeName);
        $properties = [];

        foreach ($shape['members'] as $memberName => $memberData) {
            if (\in_array(($memberData['location'] ?? null), ['header', 'headers'])) {
                continue;
            }

            $properties[] = strtr('$this->PROPERTY_NAME = PROPERTY_ACCESSOR;', [
                'PROPERTY_NAME' => $memberName,
                'PROPERTY_ACCESSOR' => $this->parseXmlResponse('$data', $memberName, $memberData),
            ]);
        }

        return implode("\n", $properties);
    }

    private function parseXmlResponseStructure(string $shapeName, string $input): string
    {
        $shape = $this->definition->getShape($shapeName);

        $properties = [];
        foreach ($shape['members'] as $memberName => $memberData) {
            $properties[] = strtr('PROPERTY_NAME => PROPERTY_ACCESSOR,', [
                'PROPERTY_NAME' => var_export($memberName, true),
                'PROPERTY_ACCESSOR' => $this->parseXmlResponse($input, $memberName, $memberData),
            ]);
        }

        return strtr('new CLASS_NAME([
            PROPERTIES
        ])', [
            'CLASS_NAME' => $this->safeClassName($shapeName),
            'PROPERTIES' => implode("\n", $properties),
        ]);
    }

    private function parseXmlResponseScalar(string $shapeName, string $input): string
    {
        $shape = $this->definition->getShape($shapeName);

        return strtr('$this->xmlValueOrNull(PROPERTY_ACCESSOR, PROPERTY_TYPE)', [
            'PROPERTY_ACCESSOR' => $input,
            'PROPERTY_TYPE' => \var_export($this->toPhpType($shape['type']), true),
        ]);
    }

    private function parseXmlResponseList(string $shapeName, string $input): string
    {
        $shape = $this->definition->getShape($shapeName);

        return strtr('(function(\SimpleXMLElement $xml): array {
            $items = [];
            foreach ($xml as $item) {
               $items[] = LIST_ACCESSOR;
            }

            return $items;
        })(INPUT)', [
            'LIST_ACCESSOR' => $this->parseXmlResponse('$item', null, ['shape' => $shape['member']['shape']]),
            'INPUT' => $input,
        ]);
    }

    private function parseXmlResponseMap(string $shapeName, string $input): string
    {
        $shape = $this->definition->getShape($shapeName);
        if (!isset($shape['key']['locationName'])) {
            throw new \RuntimeException('This is not implemented yet');
        }

        return strtr('(function(\SimpleXMLElement $xml): array {
            $items = [];
            foreach ($xml as $item) {
               $items[$item->MAP_KEY->__toString()] = MAP_ACCESSOR;
            }

            return $items;
        })(INPUT)', [
            'MAP_KEY' => $shape['key']['locationName'],
            'MAP_ACCESSOR' => $this->parseXmlResponse('$item', null, $shape['value']),
            'INPUT' => $input,
        ]);
    }

    private function toPhpType(?string $parameterType): string
    {
        if ('boolean' === $parameterType) {
            $parameterType = 'bool';
        } elseif (\in_array($parameterType, ['integer'])) {
            $parameterType = 'int';
        } elseif (\in_array($parameterType, ['blob', 'long'])) {
            $parameterType = 'string';
        } elseif (\in_array($parameterType, ['map', 'list'])) {
            $parameterType = 'array';
        } elseif (\in_array($parameterType, ['timestamp'])) {
            $parameterType = '\DateTimeImmutable';
        }

        return $parameterType;
    }

    /**
     * Make sure we dont use a class name like Trait or Object.
     */
    private function safeClassName(string $name): string
    {
        if (\in_array($name, ['Object', 'Class', 'Trait'])) {
            return 'Aws' . $name;
        }

        return $name;
    }

    /**
     * Pick only the config from $shapes we are interested in.
     */
    private function buildXmlConfig(string $shapeName): array
    {
        $shape = $this->definition->getShape($shapeName);
        if (!\in_array($shape['type'] ?? 'structure', ['structure', 'list'])) {
            $xml[$shapeName]['type'] = $shape['type'];

            return $xml;
        }

        $xml[$shapeName] = $shape;
        $members = [];
        if (isset($shape['members'])) {
            $members = $shape['members'];
        } elseif (isset($shape['member'])) {
            $members = [$shape['member']];
        }

        foreach ($members as $name => $data) {
            $xml = array_merge($xml, $this->buildXmlConfig($data['shape'] ?? $name));
        }

        return $xml;
    }

    /**
     * This is will produce the same result as `var_export` but on only one line.
     */
    private function printArray(array $data): string
    {
        $output = '[';
        foreach ($data as $name => $value) {
            $output .= sprintf('%s => %s,', (\is_int($name) ? $name : '"' . $name . '"'), \is_array($value) ? $this->printArray($value) : ("'" . $value . "'"));
        }
        $output .= ']';

        return $output;
    }

    private function addMethodComment(Method $method, array $inputShape, string $baseNamespace): void
    {
        foreach ($inputShape['members'] as $name => $data) {
            $nullable = !\in_array($name, $inputShape['required'] ?? []);
            $param = $this->definition->getShape($data['shape'])['type'];
            if ('structure' === $param) {
                $param = '\\' . $baseNamespace . '\\' . $name . '|array';
            } elseif ('list' === $param) {
                $listItemShapeName = $this->definition->getShape($data['shape'])['member']['shape'];

                // is the list item an object?
                $type = $this->definition->getShape($listItemShapeName)['type'];
                if ('structure' === $type) {
                    $param = '\\' . $baseNamespace . '\\' . $listItemShapeName . '[]';
                } else {
                    $param = $this->toPhpType($type) . '[]';
                }
            } elseif ($data['streaming'] ?? false) {
                $param = 'string|resource|\Closure';
            } elseif ('timestamp' === $param) {
                $param = '\DateTimeInterface|string';
            } else {
                $param = $this->toPhpType($param);
            }

            $method->addComment(sprintf('  %s%s: %s,', $name, $nullable ? '?' : '', $param));
        }
    }

    private function setMethodBody(array $inputShape, Method $method, array $operation, $inputClassName): void
    {
        $safeInputClassName = $this->safeClassName($inputClassName);
        $body = <<<PHP
\$input = $safeInputClassName::create(\$input);
\$input->validate();

PHP;

        if (isset($inputShape['payload'])) {
            $data = $inputShape['members'][$inputShape['payload']];
            if ($data['streaming'] ?? false) {
                $body .= '$payload = $input->get' . $inputShape['payload'] . '() ?? "";';
            } else {
                // Build XML
                $xml = $this->buildXmlConfig($data['shape']);
                $xml['_root'] = [
                    'type' => $data['shape'],
                    'xmlName' => $data['locationName'],
                    'uri' => $data['xmlNamespace']['uri'] ?? '',
                ];

                $body .= '$xmlConfig = ' . $this->printArray($xml) . ";\n";
                $body .= '$payload = (new XmlBuilder($input->requestBody(), $xmlConfig))->getXml();' . "\n";
            }
            $payloadVariable = '$payload';
        } else {
            // This is a normal body application/x-www-form-urlencoded
            $payloadVariable = '$input->requestBody()';
        }

        $param = '';
        if ($this->operationRequiresHttpClient($operation['name'])) {
            $param = ', $this->httpClient';
        }

        if (isset($operation['output'])) {
            $return = "return new {$this->safeClassName($operation['output']['shape'])}(\$response$param);";
        } else {
            $return = "return new Result(\$response$param);";
        }

        $method->setBody(
            $body .
            <<<PHP

\$response = \$this->getResponse(
    '{$operation['http']['method']}',
    $payloadVariable,
    \$input->requestHeaders(),
    \$this->getEndpoint(\$input->requestUri(), \$input->requestQuery())
);

$return
PHP
        );
    }

    private function operationRequiresHttpClient(string $operationName): bool
    {
        $operation = $this->definition->getOperation($operationName);

        if (!isset($operation['output'])) {
            return false;
        }
        // Check if output has streamable body
        $outputShape = $this->definition->getShape($operation['output']['shape']);
        $payload = $outputShape['payload'] ?? null;
        if (null !== $payload && ($outputShape['members'][$payload]['streaming'] ?? false)) {
            return true;
        }

        // TODO check if pagination is supported

        return false;
    }

    private function resultClassAddNamedConstructor(string $baseNamespace, array $inputShape, ClassType $class): void
    {
        $class->addMethod('create')->setStatic(true)->setReturnType('self')->setBody(
            <<<PHP
return \$input instanceof self ? \$input : new self(\$input);
PHP
        )->addParameter('input');

        // We need a constructor
        $constructor = $class->addMethod('__construct');
        $constructor->addComment('@param array{');
        $this->addMethodComment($constructor, $inputShape, $baseNamespace);
        $constructor->addComment('} $input');
        $constructor->addParameter('input')->setType('array')->setDefaultValue([]);

        $constructorBody = '';
        foreach ($inputShape['members'] as $name => $data) {
            $parameterType = $data['shape'];
            $memberShape = $this->definition->getShape($parameterType);
            if ('structure' === $memberShape['type']) {
                $this->doGenerateResultClass($baseNamespace, $parameterType);
                $constructorBody .= sprintf('$this->%s = isset($input["%s"]) ? %s::create($input["%s"]) : null;' . "\n", $name, $name, $this->safeClassName($parameterType), $name);
            } elseif ('list' === $memberShape['type']) {
                // Check if this is a list of objects
                $listItemShapeName = $memberShape['member']['shape'];
                $type = $this->definition->getShape($listItemShapeName)['type'];
                if ('structure' === $type) {
                    // todo this is needed in Input but useless in Result
                    $this->doGenerateResultClass($baseNamespace, $listItemShapeName);
                    $constructorBody .= sprintf('$this->%s = array_map(function($item) { return %s::create($item); }, $input["%s"] ?? []);' . "\n", $name, $this->safeClassName($listItemShapeName), $name);
                } else {
                    $constructorBody .= sprintf('$this->%s = $input["%s"] ?? [];' . "\n", $name, $name);
                }
            } elseif ('map' === $memberShape['type']) {
                // Check if this is a list of objects
                $listItemShapeName = $memberShape['value']['shape'];
                $type = $this->definition->getShape($listItemShapeName)['type'];
                if ('structure' === $type) {
                    // todo this is needed in Input but useless in Result
                    $this->doGenerateResultClass($baseNamespace, $listItemShapeName);
                    $constructorBody .= sprintf('$this->%s = array_map(function($item) { return %s::create($item); }, $input["%s"] ?? []);' . "\n", $name, $this->safeClassName($listItemShapeName), $name);
                } else {
                    $constructorBody .= sprintf('$this->%s = $input["%s"] ?? [];' . "\n", $name, $name);
                }
            } else {
                $constructorBody .= sprintf('$this->%s = $input["%s"] ?? null;' . "\n", $name, $name);
            }
        }
        $constructor->setBody($constructorBody);
    }

    /**
     * Add properties and getters.
     */
    private function resultClassAddProperties(string $baseNamespace, bool $root, ?array $inputShape, string $className, ClassType $class, PhpNamespace $namespace): void
    {
        $members = $inputShape['members'];
        foreach ($members as $name => $data) {
            $nullable = $returnType = null;
            $property = $class->addProperty($name)->setPrivate();
            if (null !== $propertyDocumentation = $this->definition->getParameterDocumentation($className, $name, $data['shape'])) {
                $property->addComment($this->parseDocumentation($propertyDocumentation));
            }

            $parameterType = $members[$name]['shape'];
            $memberShape = $this->definition->getShape($parameterType);

            if ('structure' === $memberShape['type']) {
                $this->doGenerateResultClass($baseNamespace, $parameterType);
                $parameterType = $baseNamespace . '\\' . $this->safeClassName($parameterType);
            } elseif ('map' === $memberShape['type']) {
                $mapKeyShape = $this->definition->getShape($memberShape['key']['shape']);

                if ('string' !== $mapKeyShape['type']) {
                    throw new \RuntimeException('Complex maps are not supported');
                }
                $parameterType = 'array';
                $nullable = false;
            } elseif ('list' === $memberShape['type']) {
                $parameterType = 'array';
                $nullable = false;
                $property->setValue([]);

                // Check if this is a list of objects
                $listItemShapeName = $memberShape['member']['shape'];
                $type = $this->definition->getShape($listItemShapeName)['type'];
                if ('structure' === $type) {
                    $this->doGenerateResultClass($baseNamespace, $listItemShapeName);
                    $returnType = $this->safeClassName($listItemShapeName);
                } else {
                    $returnType = $this->toPhpType($type);
                }
            } elseif ($data['streaming'] ?? false) {
                $parameterType = StreamableBody::class;
                $namespace->addUse(StreamableBody::class);
                $nullable = false;
            } else {
                $parameterType = $this->toPhpType($memberShape['type']);
            }

            $callInitialize = '';
            if ($root) {
                $callInitialize = <<<PHP
\$this->initialize();
PHP;
            }

            $method = $class->addMethod('get' . $name)
                ->setReturnType($parameterType)
                ->setBody(
                    <<<PHP
$callInitialize
return \$this->{$name};
PHP
                );

            $nullable = $nullable ?? !\in_array($name, $inputShape['required'] ?? []);
            if ($returnType) {
                $method->addComment('@return ' . $returnType . ('array' === $parameterType ? '[]' : ''));
            }
            $method->setReturnNullable($nullable);
        }
    }

    private function resultClassPopulateResult(string $operationName, string $className, bool $isNew, PhpNamespace $namespace, ClassType $class): void
    {
        $shape = $this->definition->getShape($className);

        // Parse headers
        $nonHeaders = [];
        $body = '';
        foreach ($shape['members'] as $name => $member) {
            if (($member['location'] ?? null) !== 'header') {
                $nonHeaders[$name] = $member;

                continue;
            }

            $locationName = strtolower($member['locationName'] ?? $name);
            $memberShape = $this->definition->getShape($member['shape']);
            if ('timestamp' === $memberShape['type']) {
                $body .= "\$this->$name = isset(\$headers['{$locationName}'][0]) ? new \DateTimeImmutable(\$headers['{$locationName}'][0]) : null;\n";
            } else {
                if (null !== $constant = $this->getFilterConstantFromType($memberShape['type'])) {
                    // Convert to proper type
                    $body .= "\$this->$name = isset(\$headers['{$locationName}'][0]) ? filter_var(\$headers['{$locationName}'][0], {$constant}) : null;\n";
                } else {
                    $body .= "\$this->$name = \$headers['{$locationName}'][0] ?? null;\n";
                }
            }
        }

        foreach ($nonHeaders as $name => $member) {
            if (($member['location'] ?? null) !== 'headers') {
                continue;
            }
            unset($nonHeaders[$name]);

            $locationName = strtolower($member['locationName'] ?? $name);
            $length = \strlen($locationName);
            $body .= <<<PHP
\$this->$name = [];
foreach (\$headers as \$name => \$value) {
    if (substr(\$name, 0, {$length}) === '{$locationName}') {
        \$this->{$name}[\$name] = \$value[0];
    }
}

PHP;
        }

        $comment = '';
        if ($isNew) {
            $comment = "// TODO Verify correctness\n";
        }

        // Prepend with $headers = ...
        if (!empty($body)) {
            $body = <<<PHP
\$headers = \$response->getHeaders(false);

$comment
PHP
                . $body;
        }

        $body .= "\n";
        $xmlParser = '';
        if (isset($shape['payload'])) {
            $name = $shape['payload'];
            $member = $shape['members'][$name];
            if (true === ($member['streaming'] ?? false)) {
                // Make sure we can stream this.
                $namespace->addUse(StreamableBody::class);
                $body .= strtr('
                    if (null !== $httpClient) {
                        $this->PROPERTY_NAME = new StreamableBody($httpClient->stream($response));
                    } else {
                        $this->PROPERTY_NAME = $response->getContent(false);
                    }
                ', ['PROPERTY_NAME' => $name]);
            } else {
                $xmlParser = $this->parseXmlResponseRoot($className);
            }
        } else {
            $xmlParser = $this->parseXmlResponseRoot($className);
        }

        if (!empty($xmlParser)) {
            $body .= "\$data = new \SimpleXMLElement(\$response->getContent(false));";
            $wrapper = $this->definition->getOperation($operationName)['output']['resultWrapper'] ?? null;
            if (null !== $wrapper) {
                $body .= "\$data = \$data->$wrapper;\n";
            }
            $body .= "\n" . $xmlParser;
        }

        $method = $class->addMethod('populateResult')
            ->setReturnType('void')
            ->setProtected()
            ->setBody($body);
        $method->addParameter('response')->setType(ResponseInterface::class);
        $method->addParameter('httpClient')->setType(HttpClientInterface::class)->setNullable(true);
    }

    private function getFilterConstantFromType(string $type): ?string
    {
        switch ($type) {
            case 'integer':
                return 'FILTER_VALIDATE_INT';
            case 'boolean':
                return 'FILTER_VALIDATE_BOOLEAN';
            case 'string':
            default:
                return null;
        }
    }
}