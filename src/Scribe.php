<?php

declare(strict_types=1);

namespace Ipsocode\Scribe;

use Closure;
use Composer\InstalledVersions;
use Hypervel\Routing\Route;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Tools\Globals;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Symfony\Component\HttpFoundation\Request;

class Scribe
{
    /**
     * @deprecated hard-coded and never bumped; use {@see Scribe::version()}
     */
    public const VERSION = '0.1.0';

    /**
     * The installed package version, as Composer reports it, or `dev` when the
     * package is not installed through Composer or runs from a branch.
     */
    public static function version(): string
    {
        return static::resolveVersion(
            InstalledVersions::isInstalled('ipsocode/hypervel-scribe')
                ? InstalledVersions::getPrettyVersion('ipsocode/hypervel-scribe')
                : null
        );
    }

    /**
     * Maps a Composer pretty version to the one Scribe reports.
     */
    public static function resolveVersion(?string $prettyVersion): string
    {
        if ($prettyVersion === null || $prettyVersion === '' || str_starts_with($prettyVersion, 'dev-')) {
            return 'dev';
        }

        return $prettyVersion;
    }

    /**
     * Runs a callback just before a response call is made, after the environment
     * is configured and the transaction started.
     *
     * @param callable(Request, ExtractedEndpointData): mixed $callable
     */
    public static function beforeResponseCall(callable $callable): void
    {
        Globals::$__beforeResponseCall = Closure::fromCallable($callable);
    }

    /**
     * Runs a callback just after a response call, with the response, so it can be modified.
     *
     * @param callable(Request, ExtractedEndpointData, mixed): mixed $callable
     */
    public static function afterResponseCall(callable $callable): void
    {
        Globals::$__afterResponseCall = Closure::fromCallable($callable);
    }

    /**
     * Runs a callback when the generate command starts, after its config is loaded.
     *
     * @param callable(GenerateDocumentation): mixed $callable
     */
    public static function bootstrap(callable $callable): void
    {
        Globals::$__bootstrap = Closure::fromCallable($callable);
    }

    /**
     * Runs a callback once the docs are generated. It receives the absolute output
     * paths, keyed `postman`, `openapi`, `html`, `blade` and `assets` (`js`, `css`,
     * `images`); an output that was not written is null. See src/docs/hooks.md.
     *
     * @param callable(array<string, mixed>): mixed $callable
     */
    public static function afterGenerating(callable $callable): void
    {
        Globals::$__afterGenerating = Closure::fromCallable($callable);
    }

    /**
     * Sets how the FormRequest strategies instantiate form requests. The callback
     * receives the form request class name, the route being processed and the
     * controller method.
     *
     * @param ?callable(string,Route,ReflectionFunctionAbstract): mixed $callable
     */
    public static function instantiateFormRequestUsing(?callable $callable): void
    {
        Globals::$__instantiateFormRequestUsing = $callable === null ? null : Closure::fromCallable($callable);
    }

    /**
     * Sets how an endpoint's URL is normalized; the default turns `users/{user}/projects/{project}`
     * into `users/{user_id}/projects/{id}`. The callback receives the route's URI, the route, the
     * method and class reflections (either may be null) and the default normalizer.
     *
     * @param ?callable(string,Route,?ReflectionFunctionAbstract,?ReflectionClass<object>,Closure): string $callable
     */
    public static function normalizeEndpointUrlUsing(?callable $callable): void
    {
        Globals::$__normalizeEndpointUrlUsing = $callable === null ? null : Closure::fromCallable($callable);
    }

    /**
     * Runs a callback after all extraction strategies have run for a route, so it
     * can modify the extracted endpoint data before it is saved.
     *
     * @param callable(ExtractedEndpointData): void $callable
     */
    public static function afterExtracting(callable $callable): void
    {
        Globals::$__afterExtracting = Closure::fromCallable($callable);
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
    public static function resolveExampleModelUsing(?callable $callable): void
    {
        Globals::$__resolveExampleModelUsing = $callable === null ? null : Closure::fromCallable($callable);
    }
}
