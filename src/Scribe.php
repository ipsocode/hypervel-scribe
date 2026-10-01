<?php

declare(strict_types=1);

namespace Ipsocode\Scribe;

use Closure;
use Hypervel\Routing\Route;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Tools\Globals;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Symfony\Component\HttpFoundation\Request;

class Scribe
{
    public const VERSION = '0.1.0';

    /**
     * Runs a callback just before a response call is made, after the environment
     * is configured and the transaction started.
     *
     * @param callable(Request, ExtractedEndpointData): mixed $callable
     */
    public static function beforeResponseCall(callable $callable)
    {
        Globals::$__beforeResponseCall = $callable;
    }

    /**
     * Runs a callback just after a response call, with the response, so it can be modified.
     *
     * @param callable(Request, ExtractedEndpointData, mixed): mixed $callable
     */
    public static function afterResponseCall(callable $callable)
    {
        Globals::$__afterResponseCall = $callable;
    }

    /**
     * Runs a callback when the generate command starts, after its config is loaded.
     *
     * @param callable(GenerateDocumentation): mixed $callable
     */
    public static function bootstrap(callable $callable)
    {
        Globals::$__bootstrap = $callable;
    }

    /**
     * Runs a callback once the docs are generated. It receives the absolute output
     * paths, keyed `postman`, `openapi`, `html`, `blade` and `assets` (`js`, `css`,
     * `images`); an output that was not written is null. See docs/hooks.md.
     *
     * @param callable(array): mixed $callable
     */
    public static function afterGenerating(callable $callable)
    {
        Globals::$__afterGenerating = $callable;
    }

    /**
     * Sets how the FormRequest strategies instantiate form requests. The callback
     * receives the form request class name, the route being processed and the
     * controller method.
     *
     * @param ?callable(string,Route,ReflectionFunctionAbstract): mixed $callable
     */
    public static function instantiateFormRequestUsing(?callable $callable)
    {
        Globals::$__instantiateFormRequestUsing = $callable;
    }

    /**
     * Sets how an endpoint's URL is normalized; the default turns `users/{user}/projects/{project}`
     * into `users/{user_id}/projects/{id}`. The callback receives the route's URI, the route, the
     * method and class reflections (either may be null) and the default normalizer.
     *
     * @param ?callable(string,Route,?ReflectionFunctionAbstract,?ReflectionClass,Closure): string $callable
     */
    public static function normalizeEndpointUrlUsing(?callable $callable)
    {
        Globals::$__normalizeEndpointUrlUsing = $callable;
    }

    /**
     * Runs a callback after all extraction strategies have run for a route, so it
     * can modify the extracted endpoint data before it is saved.
     *
     * @param callable(ExtractedEndpointData): void $callable
     */
    public static function afterExtracting(callable $callable)
    {
        Globals::$__afterExtracting = $callable;
    }

    /**
     * Sets how the `databaseFirst` source picks its example model; the default query has no
     * ORDER BY, so its row depends on storage order. The callback receives the model class, the
     * relations and the withCount list, and returns the model, or null to fall through to the next
     * source. It runs while Extractor::getRouteBeingProcessed() reports the current route, so the
     * row can differ per endpoint.
     *
     * @param ?callable(class-string, string[], string[]): ?object $callable
     */
    public static function resolveExampleModelUsing(?callable $callable)
    {
        Globals::$__resolveExampleModelUsing = $callable;
    }
}
