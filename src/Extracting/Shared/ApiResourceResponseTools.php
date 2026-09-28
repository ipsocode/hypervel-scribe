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
        // Set the route properly, so it works for users who have code that checks for the route.
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
        // If the API Resource uses an empty $resource (e.g. an empty array), the $modelInstantiator will be null
        // See https://github.com/knuckleswtf/scribe/issues/652
        $modelInstance = is_callable($modelInstantiator) ? $modelInstantiator() : [];

        try {
            $resource = new $apiResourceClass($modelInstance);
        } catch (Exception) {
            // If it is a ResourceCollection class, it might throw an error
            // when trying to instantiate with something other than a collection
            $resource = new $apiResourceClass(collect([$modelInstance]));
        }

        if ($isCollection) {
            // Collections can either use the regular JsonResource class (via `::collection()`,
            // or a ResourceCollection (via `new`)
            // See https://laravel.com/docs/5.8/eloquent-resources
            // $modelInstantiator is null when no model could be named or
            // inferred; the guard above already fell back to an empty resource,
            // and calling it here anyway threw an Error — which is not an
            // Exception, so it escaped the extractor's catch and took the whole
            // generation run down.
            $models = [$modelInstance, is_callable($modelInstantiator) ? $modelInstantiator() : $modelInstance];
            // Pagination can be in two forms:
            // [15] : means ::paginate(15)
            // [15, 'simple'] : means ::simplePaginate(15)
            if (count($paginationStrategy) === 1) {
                $perPage = $paginationStrategy[0];
                $paginator = new LengthAwarePaginator(
                    // For some reason, the LengthAware paginator needs only first page items to work correctly
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

        // Invoke any parameterless fluent methods (e.g. ->withDetails()) so the example
        // renders the same resource variant the controller returns at runtime. Guarded
        // by method_exists so an unknown/typo'd method is a silent no-op rather than a
        // fatal during generation.
        foreach ($calls as $method) {
            if (method_exists($resource, $method)) {
                $resource = $resource->{$method}();
            }
        }

        return $resource->additional($additionalData);
    }

    /**
     * Check if the ApiResource class has an `@mixin` docblock, and fetch the model from there.
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
