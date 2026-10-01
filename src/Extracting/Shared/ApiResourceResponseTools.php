<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use Exception;
use Hypervel\Context\RequestContext;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Arr;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils as e;
use Ipsocode\Scribe\Tools\Utils;

class ApiResourceResponseTools
{
    public static function fetch(
        string $apiResourceClass,
        bool $isCollection,
        ?callable $modelInstantiator,
        ExtractedEndpointData $endpointData,
        array $pagination,
        array $additionalData,
        array $calls = [],
    ) {
        $resource = static::getApiResourceOrCollectionInstance(
            $apiResourceClass,
            $isCollection,
            $modelInstantiator,
            $pagination,
            $additionalData,
            $calls
        );
        $response = static::callApiResourceAndGetResponse($resource, $endpointData);

        return $response->getContent();
    }

    public static function callApiResourceAndGetResponse(JsonResource $resource, ExtractedEndpointData $endpointData): JsonResponse
    {
        $uri = Utils::getUrlWithBoundParameters($endpointData->route->uri(), $endpointData->cleanUrlParameters);
        $method = $endpointData->route->methods()[0];
        $request = Request::create($uri, $method);
        $request->headers->add(['Accept' => 'application/json']);
        // Resource code that calls $request->route() gets the endpoint's route.
        $request->setRouteResolver(fn () => $endpointData->route);

        // Container bindings are worker-global, not coroutine-scoped, so binding
        // the fake request there would leak it to every coroutine on the worker.
        // RequestContext is the coroutine-scoped equivalent (see ResponseCalls).
        $previousRequest = RequestContext::getOrNull();
        RequestContext::set($request);

        try {
            return $resource->toResponse($request);
        } finally {
            if ($previousRequest === null) {
                RequestContext::forget();
            } else {
                RequestContext::set($previousRequest);
            }
        }
    }

    public static function getApiResourceOrCollectionInstance(
        string $apiResourceClass,
        bool $isCollection,
        ?callable $modelInstantiator,
        array $paginationStrategy = [],
        array $additionalData = [],
        array $calls = [],
    ): JsonResource {
        // $modelInstantiator is null when no model could be named or inferred; the
        // resource then wraps an empty array.
        $modelInstance = is_callable($modelInstantiator) ? $modelInstantiator() : [];

        try {
            $resource = new $apiResourceClass($modelInstance);
        } catch (Exception) {
            // A ResourceCollection may throw when given anything but a collection
            $resource = new $apiResourceClass(collect([$modelInstance]));
        }

        if ($isCollection) {
            // A collection is either a JsonResource built with `::collection()` or a
            // ResourceCollection built with `new`.
            // Without a $modelInstantiator, the second item repeats the first.
            $models = [$modelInstance, is_callable($modelInstantiator) ? $modelInstantiator() : $modelInstance];
            // Pagination takes three forms:
            // [15] : ::paginate(15)
            // [15, 'simple'] : ::simplePaginate(15)
            // [15, 'cursor'] : ::cursorPaginate(15)
            if (count($paginationStrategy) === 1) {
                $perPage = $paginationStrategy[0];
                $paginator = new LengthAwarePaginator(
                    // LengthAwarePaginator expects only the current page's items
                    collect($models)->slice(0, $perPage),
                    count($models),
                    $perPage
                );
                $list = $paginator;
            } elseif (count($paginationStrategy) === 2 && $paginationStrategy[1] === 'simple') {
                $perPage = $paginationStrategy[0];
                $paginator = new Paginator($models, $perPage);
                $list = $paginator;
            } elseif (count($paginationStrategy) === 2 && $paginationStrategy[1] === 'cursor') {
                $perPage = $paginationStrategy[0];
                $paginator = new CursorPaginator($models, $perPage);
                $list = $paginator;
            } else {
                $list = collect($models);
            }

            /** @var JsonResource $resource */
            $resource = $resource instanceof ResourceCollection
                ? new $apiResourceClass($list) : $apiResourceClass::collection($list);
        }

        // Call any parameterless fluent methods (e.g. ->withDetails()) so the example
        // renders the resource variant the controller returns. A method the resource
        // doesn't have is skipped rather than failing the run.
        foreach ($calls as $method) {
            if (method_exists($resource, $method)) {
                $resource = $resource->{$method}();
            }
        }

        return $resource->additional($additionalData);
    }

    /**
     * The model class named by the API resource's `@mixin` tag, if that class exists.
     */
    public static function tryToInferApiResourceModel(string $apiResourceClass): ?string
    {
        $docBlock = RouteDocBlocker::forClass($apiResourceClass);

        /** @var null|Tag $mixinTag */
        $mixinTag = Arr::first(Utils::filterDocBlockTags($docBlock->getTags(), 'mixin'));
        if (empty($mixinTag) || empty($modelClass = mb_trim($mixinTag->getContent()))) {
            return null;
        }

        if (class_exists($modelClass)) {
            return $modelClass;
        }

        return null;
    }
}
