<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Hypervel\Routing\Route;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DocBlocks\DocBlockTag;
use Ipsocode\Scribe\Extracting\FindsFormRequestForMethod;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use ReflectionFunctionAbstract;

abstract class TagStrategyWithFormRequestFallback extends Strategy
{
    use FindsFormRequestForMethod;

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $this->endpointData = $endpointData;

        return $this->getParametersFromDocBlockInFormRequestOrMethod($endpointData->route, $endpointData->method);
    }

    public function getParametersFromDocBlockInFormRequestOrMethod(Route $route, ReflectionFunctionAbstract $method): array
    {
        $classTags = RouteDocBlocker::getDocBlocksFromRoute($route)['class']?->getTags() ?: [];
        // Tags on the method's FormRequest win when it has any for this strategy.
        if ($formRequestClass = $this->getFormRequestReflectionClass($method)) {
            $formRequestDocBlock = RouteDocBlocker::forClass($formRequestClass);
            $parametersFromFormRequest = $this->getFromTags($formRequestDocBlock->getTags(), $classTags);

            if (count($parametersFromFormRequest)) {
                return $parametersFromFormRequest;
            }
        }

        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($route)['method'];

        return $this->getFromTags($methodDocBlock->getTags(), $classTags);
    }

    /**
     * @param DocBlockTag[] $tagsOnMethod
     * @param DocBlockTag[] $tagsOnClass
     */
    abstract public function getFromTags(array $tagsOnMethod, array $tagsOnClass = []): array;
}
