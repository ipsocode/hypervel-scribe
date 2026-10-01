<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Shared;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Routing\Route;
use Hypervel\Support\Str;
use ReflectionEnum;
use ReflectionException;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

class UrlParamsNormalizer
{
    /**
     * @var array<string, array<string, Model>> keyed by declaring class and method name
     */
    private static array $typeHintedEloquentModels = [];

    /**
     * Renames route-model-bound URL parameters after the field they bind to, which
     * reads better to API consumers: `/posts/{post}` becomes `/posts/{id}`, and
     * `/users/{user}/posts/{post}` becomes `/users/{user_id}/posts/{id}`.
     */
    public static function normalizeParameterNamesInRouteUri(Route $route, ReflectionFunctionAbstract $method): string
    {
        $params = [];
        $uri = $route->uri;
        preg_match_all('#\{(\w+?)}#', $uri, $params);

        $resourceRouteNames = ['.index', '.show', '.update', '.destroy', '.store'];

        $typeHintedEloquentModels = self::getTypeHintedEloquentModels($method);
        $routeName = $route->action['as'] ?? '';
        if (Str::endsWith($routeName, $resourceRouteNames)) {
            // Resource routes can be nested, eg users.posts.show
            $pluralResources = explode('.', $routeName);
            array_pop($pluralResources); // Remove the name of the action (eg `show`)

            $alreadyFoundResourceParam = false;
            foreach (array_reverse($pluralResources) as $pluralResource) {
                $singularResource = Str::singular($pluralResource);

                // Resource routes name the parameter with underscores
                // (`cool-things/{cool_thing}`), so both spellings are matched
                $singularResourceParam = str_replace('-', '_', $singularResource);

                $urlPatternsToSearchFor = [
                    "{$pluralResource}/{{$singularResourceParam}}",
                    "{$pluralResource}/{{$singularResource}}",
                    "{$pluralResource}/{{$singularResourceParam}?}",
                    "{$pluralResource}/{{$singularResource}?}",
                ];

                $binding = self::getRouteKeyForUrlParam(
                    $route,
                    $singularResource,
                    $typeHintedEloquentModels,
                    'id'
                );

                if (! $alreadyFoundResourceParam) {
                    // The last resource param becomes `params/{id}` (or whatever field it's bound to)
                    $replaceWith = [
                        "{$pluralResource}/{{$binding}}",
                        "{$pluralResource}/{{$binding}}",
                        "{$pluralResource}/{{$binding}?}",
                        "{$pluralResource}/{{$binding}?}",
                    ];
                    $alreadyFoundResourceParam = true;
                } else {
                    // Earlier resource params become `params/{<param>_<field>}`, eg `users/{user_id}`
                    $replaceWith = [
                        "{$pluralResource}/{{$singularResourceParam}_{$binding}}",
                        "{$pluralResource}/{{$singularResource}_{$binding}}",
                        "{$pluralResource}/{{$singularResourceParam}_{$binding}?}",
                        "{$pluralResource}/{{$singularResource}_{$binding}?}",
                    ];
                }
                $uri = str_replace($urlPatternsToSearchFor, $replaceWith, $uri);
            }
        }

        foreach ($params[1] as $param) {
            // Any other bound parameter becomes `{<param>_<field>}`.
            if ($binding = self::getRouteKeyForUrlParam($route, $param, $typeHintedEloquentModels)) {
                $urlPatternsToSearchFor = ["{{$param}}", "{{$param}?}"];
                $replaceWith = ["{{$param}_{$binding}}", "{{$param}_{$binding}?}"];
                $uri = str_replace($urlPatternsToSearchFor, $replaceWith, $uri);
            }
        }

        return $uri;
    }

    /**
     * The action's type-hinted arguments that are Eloquent models, as
     * [<variable_name> => $instance].
     */
    public static function getTypeHintedEloquentModels(ReflectionFunctionAbstract $method): array
    {
        // Asked two or three times per route (URL normalisation, the URL
        // parameter strategy, and each API-resource response attribute), and
        // each ask instantiates every typed argument. The instances are only
        // read, for their class and route key, so one set per method serves
        // them all. A closure has no name to key on and is rare enough to
        // recompute.
        $key = $method instanceof ReflectionMethod ? $method->class . '::' . $method->getName() : null;
        if ($key !== null && isset(self::$typeHintedEloquentModels[$key])) {
            return self::$typeHintedEloquentModels[$key];
        }

        $arguments = [];
        foreach ($method->getParameters() as $argument) {
            if (($instance = self::instantiateMethodArgument($argument)) && $instance instanceof Model) {
                $arguments[$argument->getName()] = $instance;
            }
        }

        if ($key !== null) {
            self::$typeHintedEloquentModels[$key] = $arguments;
        }

        return $arguments;
    }

    /**
     * The action's type-hinted arguments that are enums, as
     * [<variable_name> => ReflectionEnum].
     */
    public static function getTypeHintedEnums(ReflectionFunctionAbstract $method): array
    {
        $arguments = [];
        foreach ($method->getParameters() as $argument) {
            $argumentType = $argument->getType();
            if (! $argumentType instanceof ReflectionNamedType) {
                continue;
            }

            try {
                $reflectionEnum = new ReflectionEnum($argumentType->getName());
                $arguments[$argument->getName()] = $reflectionEnum;
            } catch (ReflectionException) {
                continue;
            }
        }

        return $arguments;
    }

    /**
     * The field a model-bound URL parameter (`/posts/{post}` with `show(Post $post)`)
     * is resolved by: the inline key in `/posts/{post:slug}`, else the model's
     * getRouteKeyName(), else $default. Bindings customised at runtime are not seen.
     *
     * @param string $paramName The name of the URL parameter
     * @param array<string, Model> $typeHintedEloquentModels
     * @param null|string $default Default field to use
     */
    protected static function getRouteKeyForUrlParam(
        Route $route,
        string $paramName,
        array $typeHintedEloquentModels = [],
        ?string $default = null,
    ): ?string {
        if ($binding = self::getInlineRouteKey($route, $paramName)) {
            return $binding;
        }

        return self::getRouteKeyFromModel($paramName, $typeHintedEloquentModels) ?: $default;
    }

    /**
     * The `slug` in /posts/{post:slug}.
     */
    protected static function getInlineRouteKey(Route $route, string $paramName): ?string
    {
        return $route->bindingFieldFor($paramName);
    }

    /**
     * The getRouteKeyName() of the model argument named like the URL parameter
     * (`/posts/{post}` -> `show(Post $post)`), or null if there is none.
     *
     * @param Model[] $typeHintedEloquentModels
     */
    protected static function getRouteKeyFromModel(string $paramName, array $typeHintedEloquentModels): ?string
    {
        // Argument names are camelCase (eg `$userAddress` in `show(BigThing $userAddress)`)
        $paramName = Str::camel($paramName);

        if (array_key_exists($paramName, $typeHintedEloquentModels)) {
            $argumentInstance = $typeHintedEloquentModels[$paramName];

            return $argumentInstance->getRouteKeyName();
        }

        return null;
    }

    /**
     * Instantiates a controller argument from its type hint (`$post` in
     * `show(Post $post)`); an interface is resolved from the container. Returns
     * null when that can't be done safely: no type, a builtin or compound type,
     * a class whose constructor needs arguments, or an interface the container
     * can't resolve.
     */
    protected static function instantiateMethodArgument(ReflectionParameter $argument): ?object
    {
        $argumentType = $argument->getType();
        // No type hint, or a union or intersection type
        if (! $argumentType instanceof ReflectionNamedType) {
            return null;
        }

        $argumentClassName = $argumentType->getName();
        if (class_exists($argumentClassName)) {
            try {
                return new $argumentClassName;
            } catch (Throwable $e) {
                return null;
            }
        }

        if (interface_exists($argumentClassName)) {
            try {
                return app($argumentClassName);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public static function flushState(): void
    {
        self::$typeHintedEloquentModels = [];
    }
}
