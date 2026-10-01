<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\UrlParameters;

use Error;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Throwable;

class GetFromLaravelAPI extends Strategy
{
    use ParamHelpers;

    /**
     * The route key each model's first row gives as an example, by model class
     * and key name: one query per model per run instead of one per URL
     * parameter per route. Null is kept too, for an empty or missing table.
     *
     * @var array<string, mixed>
     */
    private static array $exampleRouteKeys = [];

    public static function flushState(): void
    {
        self::$exampleRouteKeys = [];
    }

    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $parameters = [];

        $path = $endpointData->uri;
        preg_match_all('/\{(.*?)\}/', $path, $matches);

        foreach ($matches[1] as $match) {
            $isOptional = Str::endsWith($match, '?');
            $name = mb_rtrim($match, '?');

            $parameters[$name] = [
                'name' => $name,
                'description' => $this->inferUrlParamDescription($endpointData->uri, $name),
                'required' => ! $isOptional,
            ];
        }

        $parameters = $this->inferBetterTypesAndExamplesForEloquentUrlParameters($parameters, $endpointData);
        $parameters = $this->inferBetterTypesAndExamplesForEnumUrlParameters($parameters, $endpointData);

        return $this->setTypesAndExamplesForOthers($parameters, $endpointData);
    }

    protected function inferUrlParamDescription(string $url, string $paramName): string
    {
        // /users/{id} and /anything/{user_id} both give "The ID of the user."
        $strategies = collect(['id', 'slug'])->map(function ($name) {
            $friendlyName = $name === 'id' ? 'ID' : $name;

            return function ($url, $paramName) use ($name, $friendlyName) {
                if ($paramName === $name) {
                    $thing = $this->getNameOfUrlThing($url, $paramName);

                    return "The {$friendlyName} of the {$thing}.";
                }
                if (Str::is("*_{$name}", $paramName)) {
                    $thing = str_replace(['_', '-'], ' ', str_replace("_{$name}", '', $paramName));

                    return "The {$friendlyName} of the {$thing}.";
                }
            };
        })->toArray();

        // /categories/{category} gives "The category."
        $strategies[] = function ($url, $paramName) {
            $thing = $this->getNameOfUrlThing($url, $paramName);
            if ($thing === $paramName) {
                return "The {$thing}.";
            }
        };

        foreach ($strategies as $strategy) {
            if ($inferred = $strategy($url, $paramName)) {
                return $inferred;
            }
        }

        return '';
    }

    protected function inferBetterTypesAndExamplesForEloquentUrlParameters(array $parameters, ExtractedEndpointData $endpointData): array
    {
        // Eloquent models linked to a URL parameter, by parameter name.
        $modelInstances = [];

        // First, models type-hinted on the method: with /users/{id} and
        // (User $user), {id} takes the type of User's key.
        $typeHintedEloquentModels = UrlParamsNormalizer::getTypeHintedEloquentModels($endpointData->method);
        foreach ($typeHintedEloquentModels as $argumentName => $modelInstance) {
            $routeKey = $modelInstance->getRouteKeyName();

            // In the normalized URI, argument $user may be {user}, {user_<routeKey>}
            // or {<routeKey>}.
            if (isset($parameters[$argumentName])) {
                $paramName = $argumentName;
            } elseif (isset($parameters["{$argumentName}_{$routeKey}"])) {
                $paramName = "{$argumentName}_{$routeKey}";
            } elseif (isset($parameters[$routeKey])) {
                $paramName = $routeKey;
            } else {
                continue;
            }

            $modelInstances[$paramName] = $modelInstance;
        }

        // Then the model the URI names for each parameter without a type yet
        // (so far, every one); a match replaces a type-hinted model.
        foreach ($parameters as $name => $data) {
            if (isset($data['type'])) {
                continue;
            }

            // /things/{id} looks for a Thing model.
            $urlThing = $this->getNameOfUrlThing($endpointData->uri, $name);
            if ($urlThing && ($modelInstance = $this->findModelFromUrlThing($urlThing))) {
                $modelInstances[$name] = $modelInstance;
            }
        }

        foreach ($modelInstances as $paramName => $modelInstance) {
            // A route key that is the primary key takes the key's type; any
            // other route key is a string.
            $routeKey = $modelInstance->getRouteKeyName();
            $type = $modelInstance->getKeyName() === $routeKey
                ? static::normalizeTypeName($modelInstance->getKeyType()) : 'string';

            $parameters[$paramName]['type'] = $type;
            $parameters[$paramName]['example'] = $this->exampleRouteKey($modelInstance, $routeKey);
        }

        return $parameters;
    }

    /**
     * The route key of the model's first row, or null when there is no row to
     * read (an empty table, or no table at all).
     */
    protected function exampleRouteKey(Model $modelInstance, string $routeKey): mixed
    {
        $cacheKey = $modelInstance::class . '::' . $routeKey;

        if (! array_key_exists($cacheKey, self::$exampleRouteKeys)) {
            try {
                self::$exampleRouteKeys[$cacheKey] = $modelInstance->newQuery()->first()?->{$routeKey};
            } catch (Throwable) {
                self::$exampleRouteKeys[$cacheKey] = null;
            }
        }

        return self::$exampleRouteKeys[$cacheKey];
    }

    protected function inferBetterTypesAndExamplesForEnumUrlParameters(array $parameters, ExtractedEndpointData $endpointData): array
    {
        $typeHintedEnums = UrlParamsNormalizer::getTypeHintedEnums($endpointData->method);
        foreach ($typeHintedEnums as $argumentName => $enum) {
            // getBackingType() is a ReflectionNamedType (or null, for a pure
            // enum), and this file is strict-typed, so it has to be cast rather
            // than left to PHP to coerce through __toString().
            $parameters[$argumentName]['type'] = static::normalizeTypeName((string) $enum->getBackingType());

            try {
                $parameters[$argumentName]['example'] = $enum->getCases()[0]->getBackingValue();
            } catch (Throwable) {
                $parameters[$argumentName]['example'] = null;
            }
        }

        return $parameters;
    }

    protected function setTypesAndExamplesForOthers(array $parameters, ExtractedEndpointData $endpointData): array
    {
        foreach ($parameters as $name => $parameter) {
            if (empty($parameter['type'])) {
                $parameters[$name]['type'] = 'string';
            }

            if (($parameter['example'] ?? null) === null) {
                // A `where()` constraint on the parameter shapes its example.
                $parameterRegex = $endpointData->route->wheres[$name] ?? null;
                $parameters[$name]['example'] = $parameterRegex
                    ? $this->castToType($this->getFaker()->regexify($parameterRegex), $parameters[$name]['type'])
                    : $this->generateDummyValue($parameters[$name]['type'], hints: ['name' => $name]);
            }
        }

        return $parameters;
    }

    /**
     * The singular "thing" named by the path segment before $paramName, e.g.:
     * - /<whatever>/things/{paramName} -> "thing"
     * - animals/cats/{id} -> "cat"
     * - users/{user_id}/contracts -> "user"
     *
     * @param null|string $alternateParamName a second name to try when $paramName isn't in the URL
     */
    protected function getNameOfUrlThing(string $url, string $paramName, ?string $alternateParamName = null): ?string
    {
        // $paramName has already had its optional marker taken off, so the URI
        // has to lose its own before "{$paramName}" can be found in it.
        $parts = explode('/', str_replace('?}', '}', $url));
        // A single segment, like "{thing}", has nothing before the parameter.
        if (count($parts) === 1) {
            return null;
        }

        $paramIndex = array_search("{{$paramName}}", $parts);

        if ($paramIndex === false) {
            $paramIndex = array_search("{{$alternateParamName}}", $parts);
        }

        if ($paramIndex === false || $paramIndex === 0) {
            return null;
        }

        $things = $parts[$paramIndex - 1];

        // "side_projects" becomes "side project".
        return str_replace(['_', '-'], ' ', Str::singular($things));
    }

    /**
     * The model a URL "thing" names, like Cat for the "cat" in /cats/{id}, if any.
     */
    protected function findModelFromUrlThing(string $urlThing): ?Model
    {
        $className = str_replace(['-', '_', ' '], '', Str::title($urlThing));
        $rootNamespace = app()->getNamespace();

        if (class_exists($class = "{$rootNamespace}Models\\" . $className, autoload: false)
            // Or directly under the root namespace, for apps without a Models directory.
            || class_exists($class = $rootNamespace . $className, autoload: false)) {
            try {
                $instance = new $class;
            } catch (Error) { // An enum or another class that can't be instantiated.
                return null;
            }

            return $instance instanceof Model ? $instance : null;
        }

        return null;
    }
}
