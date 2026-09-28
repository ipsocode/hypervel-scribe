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
    public const VERSION = '5.11.0';

    /**
     * Specify a callback that will be executed just before a response call is made
     * (after configuring the environment and starting a transaction).
     *
     * @param callable(Request, ExtractedEndpointData): mixed $callable
     */
    public static function beforeResponseCall(callable $callable)
    {
        Globals::$__beforeResponseCall = $callable;
    }

    /**
     * Specify a callback that will be executed just after a response call is done
     * (allowing to modify the response).
     *
     * @param callable(Request, ExtractedEndpointData, mixed): mixed $callable
     */
    public static function afterResponseCall(callable $callable)
    {
        Globals::$__afterResponseCall = $callable;
    }

    /**
     * Specify a callback that will be executed just before the generate command is executed.
     *
     * @param callable(GenerateDocumentation): mixed $callable
     */
    public static function bootstrap(callable $callable)
    {
        Globals::$__bootstrap = $callable;
    }

    /**
     * Specify a callback that will be executed when Scribe is done generating your docs.
     * This callback will receive a map of all the output paths generated, that looks like this:
     * [
     *   'postman' => '/absolute/path/to/postman/collection',
     *   'openapi' => '/absolute/path/to/openapi/spec',
     *    // If you're using `hypervel` type, `html` will be null, and vice versa for `blade`.
     *   'html' => '/absolute/path/to/index.html/',
     *   'blade' => '/absolute/path/to/blade/view',
     *    // These are paths to asset folders
     *   'assets' => [
     *     'js' => '/path/to/js/assets/folder',
     *     'css' => '/path/to/css/assets/folder',
     *     'images' => '/path/to/images/assets/folder',
     *   ]
     * ].
     *
     * If you disabled `postman` or `openapi`, their values will be null.
     *
     * @param callable(array): mixed $callable
     */
    public static function afterGenerating(callable $callable)
    {
        Globals::$__afterGenerating = $callable;
    }

    /**
     * Specify a callback that will be used by all FormRequest strategies
     * to instantiate Form Requests. his callback takes the name of the form request class,
     * the current Hypervel route being processed, and the controller method.
     *
     * @param ?callable(string,Route,ReflectionFunctionAbstract): mixed $callable
     */
    public static function instantiateFormRequestUsing(?callable $callable)
    {
        Globals::$__instantiateFormRequestUsing = $callable;
    }

    /**
     * Specify a callback that will be called when instantiating an `ExtractedEndpointData` object
     * in order to normalize the URL. The default normalization tries to convert URL parameters from
     * Hypervel resource-style (`users/{user}/projects/{project}`)
     * to a general style (`users/{user_id}/projects/{id}`).
     * The callback will be passed the default Hypervel URL, the route object, the controller method
     * and class, and finally the default normalizer — call it to keep the stock behaviour.
     *
     * Signature matches the call site in Ipsocode\Camel\Extraction\ExtractedEndpointData, which
     * passes five arguments and may pass null for the method and controller reflections.
     *
     * @param ?callable(string,Route,?ReflectionFunctionAbstract,?ReflectionClass,Closure): string $callable
     */
    public static function normalizeEndpointUrlUsing(?callable $callable)
    {
        Globals::$__normalizeEndpointUrlUsing = $callable;
    }

    /**
     * Specify a callback that will be executed after all extraction strategies have run for a route.
     * This allows you to modify the extracted endpoint data before it is saved.
     *
     * @param callable(ExtractedEndpointData): void $callable
     */
    public static function afterExtracting(callable $callable)
    {
        Globals::$__afterExtracting = $callable;
    }

    /**
     * Take over how the `databaseFirst` strategy picks its example model.
     *
     * Without a callback that strategy is `$type::with($relations)->first()`, which has no ORDER BY
     * and so leaves the row choice to storage order — fine for a scratch database, not fine when the
     * generated spec is committed and diffed. It also has no way to say WHICH row should stand for
     * an endpoint, and it drops `$withCount` entirely.
     *
     * The callback receives the model class, the relations to eager-load and the withCount list, and
     * returns the model to use (or null to fall through to the next configured strategy). It is
     * invoked while Ipsocode\Scribe\Extracting\Extractor::getRouteBeingProcessed() still reports
     * the current route, so an implementation can vary the row per endpoint.
     *
     * @param ?callable(class-string, string[], string[]): ?object $callable
     */
    public static function resolveExampleModelUsing(?callable $callable)
    {
        Globals::$__resolveExampleModelUsing = $callable;
    }
}
