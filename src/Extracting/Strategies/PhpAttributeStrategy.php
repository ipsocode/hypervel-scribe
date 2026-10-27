<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\FindsFormRequestForMethod;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunctionAbstract;

/**
 * @template T of object
 */
abstract class PhpAttributeStrategy extends Strategy
{
    use FindsFormRequestForMethod;
    use ParamHelpers;

    /**
     * @var string[]
     */
    protected static array $attributeNames = [];

    /**
     * @param array<string, mixed> $routeRules
     * @return array<int|string, mixed>
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): array
    {
        $this->endpointData = $endpointData;
        [$attributesOnMethod, $attributesOnFormRequest, $attributesOnController]
            = $this->getAttributes($endpointData->method, $endpointData->controller);

        return $this->extractFromAttributes($endpointData, $attributesOnMethod, $attributesOnFormRequest, $attributesOnController);
    }

    /**
     * @param null|ReflectionClass<object> $class
     * @return array{array<T>, array<T>, array<T>}
     */
    protected function getAttributes(ReflectionFunctionAbstract $method, ?ReflectionClass $class = null): array
    {
        /** @var array<int, T> $attributesOnMethod */
        $attributesOnMethod = collect(static::$attributeNames)
            ->flatMap(fn (string $name) => $method->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF))
            ->map(fn (ReflectionAttribute $a) => $a->newInstance())->all();

        // Attributes on the method's FormRequest count too.
        if ($formRequestClass = $this->getFormRequestReflectionClass($method)) {
            /** @var array<int, T> $attributesOnFormRequest */
            $attributesOnFormRequest = collect(static::$attributeNames)
                ->flatMap(fn (string $name) => $formRequestClass->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF))
                ->map(fn (ReflectionAttribute $a) => $a->newInstance())->all();
        }

        if ($class) {
            /** @var array<int, T> $attributesOnController */
            $attributesOnController = collect(static::$attributeNames)
                ->flatMap(fn (string $name) => $class->getAttributes($name, ReflectionAttribute::IS_INSTANCEOF))
                ->map(fn (ReflectionAttribute $a) => $a->newInstance())->all();
        }

        return [$attributesOnMethod, $attributesOnFormRequest ?? [], $attributesOnController ?? []];
    }

    /**
     * @param array<T> $attributesOnMethod
     * @param array<T> $attributesOnFormRequest
     * @param array<T> $attributesOnController
     * @return null|array<int|string, mixed>
     */
    abstract protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array;
}
