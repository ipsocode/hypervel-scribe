<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Hypervel\Database\Eloquent\Model;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Attributes\Response;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Ipsocode\Scribe\Attributes\ResponseFromFile;
use Ipsocode\Scribe\Attributes\ResponseFromTransformer;
use Ipsocode\Scribe\Extracting\DatabaseTransactionHelpers;
use Ipsocode\Scribe\Extracting\InstantiatesExampleModels;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Extracting\Shared\ApiResourceResponseTools;
use Ipsocode\Scribe\Extracting\Shared\TransformerResponseTools;
use Ipsocode\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Ipsocode\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use ReflectionClass;

/**
 * @extends PhpAttributeStrategy<Response|ResponseFromApiResource|ResponseFromFile|ResponseFromTransformer>
 */
class UseResponseAttributes extends PhpAttributeStrategy
{
    use DatabaseTransactionHelpers;
    use InstantiatesExampleModels;
    use ParamHelpers;

    protected static array $attributeNames = [
        Response::class,
        ResponseFromFile::class,
        ResponseFromApiResource::class,
        ResponseFromTransformer::class,
    ];

    /**
     * Example models for single (non-collection) API resources, keyed by
     * uri|model|states. Reused across a path's operations so the {id} url param —
     * which the OpenAPI writer takes from one operation only — matches the id in
     * every operation's response body. Process-scoped (scribe:generate is its own
     * CLI process); never used at runtime.
     *
     * @var array<string, null|Model|object>
     */
    protected static array $singleResourceModels = [];

    /**
     * Flush worker-lifetime static state: the cached single-resource models
     * (retain Eloquent model instances, and connection refs through them, for
     * the worker's life) and the inherited faker-seeded guard. Overrides
     * InstantiatesExampleModels::flushState() so both statics this class
     * owns are reset in one call.
     */
    public static function flushState(): void
    {
        self::$singleResourceModels = [];
        self::$factoryFakerSeeded = false;
    }

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $responses = [];
        foreach ([...$attributesOnController, ...$attributesOnFormRequest, ...$attributesOnMethod] as $attributeInstance) {
            // @phpstan-ignore match.unhandled (static::$attributeNames fetches only these four response attributes)
            $responses[] = match (true) {
                $attributeInstance instanceof Response => $attributeInstance->toArray(),
                $attributeInstance instanceof ResponseFromFile => $attributeInstance->toArray(),
                $attributeInstance instanceof ResponseFromApiResource => $this->getApiResourceResponse($attributeInstance),
                $attributeInstance instanceof ResponseFromTransformer => $this->getTransformerResponse($attributeInstance),
            };
        }

        return $responses;
    }

    protected function getApiResourceResponse(ResponseFromApiResource $attributeInstance)
    {
        $modelToBeTransformed = $attributeInstance->modelToBeTransformed();
        if (empty($modelToBeTransformed)) {
            c::warn(
                <<<'WARN'
                    Couldn't detect an Eloquent API resource model from your ResponseFromApiResource.
                    Either specify a model using the `model:` parameter, or add an `@mixin` annotation in your resource's docblock.
                    WARN
            );
            $modelInstantiator = null;
        } else {
            $modelInstantiator = fn () => $this->exampleModelForApiResource($modelToBeTransformed, $attributeInstance);
        }

        $pagination = [];
        if ($attributeInstance->paginate) {
            $pagination = [$attributeInstance->paginate];
        } elseif ($attributeInstance->simplePaginate) {
            $pagination = [$attributeInstance->simplePaginate, 'simple'];
        } elseif ($attributeInstance->cursorPaginate) {
            $pagination = [$attributeInstance->cursorPaginate, 'cursor'];
        }

        $this->startDbTransaction();
        $content = ApiResourceResponseTools::fetch(
            $attributeInstance->name,
            $attributeInstance->isCollection(),
            $modelInstantiator,
            $this->endpointData,
            $pagination,
            $attributeInstance->additional,
            $attributeInstance->call,
        );
        $this->endDbTransaction();

        return [
            'status' => $attributeInstance->status,
            'description' => $attributeInstance->description,
            'content' => $content,
        ];
    }

    /**
     * Build the example model for an API-resource response. A single resource is
     * cached per (uri, model, states) and reused across the path's operations, and
     * its route key is written onto the bound URL parameter — so the {id} in the
     * path matches the id in every operation's response body. Collections always get
     * a fresh sequence so their rows stay distinct (and don't trip unique indexes).
     */
    protected function exampleModelForApiResource(string $type, ResponseFromApiResource $attribute): mixed
    {
        if ($attribute->isCollection()) {
            return $this->instantiateExampleModel($type, $attribute->factoryStates, $attribute->with, null, $attribute->withCount);
        }

        $cacheKey = $this->endpointData->uri . '|' . $type . '|' . implode(',', $attribute->factoryStates);
        $model = self::$singleResourceModels[$cacheKey]
            ??= $this->instantiateExampleModel($type, $attribute->factoryStates, $attribute->with, null, $attribute->withCount);

        if ($model instanceof Model) {
            $this->syncBoundUrlParameterToModel($model);
        }

        return $model;
    }

    /**
     * Point the URL parameter bound to this model at the model's route key, so the
     * path example (e.g. /calibrations/{id}) and the response body show the same id.
     * No-op when no url parameter is type-hinted to the model (e.g. store routes).
     */
    protected function syncBoundUrlParameterToModel(Model $model): void
    {
        $routeKey = $model->getRouteKeyName();
        $example = $model->getRouteKey();

        // Preferred: a url parameter type-hinted to this exact model (route-model
        // binding). Mirror the param-name resolution in GetFromLaravelAPI (argument
        // name, {argument}_{routeKey}, or the bare routeKey).
        foreach (UrlParamsNormalizer::getTypeHintedEloquentModels($this->endpointData->method) as $argumentName => $boundInstance) {
            if ($boundInstance::class !== $model::class) {
                continue;
            }

            foreach ([$argumentName, "{$argumentName}_{$routeKey}", $routeKey] as $paramName) {
                if ($this->setUrlParameterExample($paramName, $example)) {
                    return;
                }
            }
        }

        // Fallback for resources addressed by a scalar id (controllers that take an
        // `$id` instead of binding the model): the resource's own id is the LAST
        // {param} in the (normalized) uri — e.g. /roles/{id}, or {bank} in
        // /billing/{billing}/banks/{bank}. Target the uri template, not the last
        // array key, since scalar params can leave a stray extra entry.
        if (preg_match_all('/\{(\w+?)\??}/', $this->endpointData->uri, $matches) && $matches[1] !== []) {
            $this->setUrlParameterExample(end($matches[1]), $example);
        }
    }

    /**
     * Set a url parameter's example (and its clean value) if the parameter exists.
     */
    protected function setUrlParameterExample(string $paramName, mixed $example): bool
    {
        if (! isset($this->endpointData->urlParameters[$paramName])) {
            return false;
        }

        $this->endpointData->urlParameters[$paramName]['example'] = $example;
        $this->endpointData->cleanUrlParameters[$paramName] = $example;

        return true;
    }

    protected function getTransformerResponse(ResponseFromTransformer $attributeInstance)
    {
        $modelInstantiator = fn () => $this->instantiateExampleModel(
            $attributeInstance->model,
            $attributeInstance->factoryStates,
            $attributeInstance->with,
            (new ReflectionClass($attributeInstance->name))->getMethod('transform')
        );

        $pagination = $attributeInstance->paginate ? [
            'perPage' => $attributeInstance->paginate[1] ?? null, 'adapter' => $attributeInstance->paginate[0],
        ] : [];
        $this->startDbTransaction();
        $content = TransformerResponseTools::fetch(
            $attributeInstance->name,
            $attributeInstance->collection,
            $modelInstantiator,
            $pagination,
            $attributeInstance->resourceKey,
            $this->config->get('fractal.serializer'),
        );
        $this->endDbTransaction();

        return [
            'status' => $attributeInstance->status,
            'description' => $attributeInstance->description,
            'content' => $content,
        ];
    }
}
