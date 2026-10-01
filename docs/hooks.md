# Hooks

[`Ipsocode\Scribe\Scribe`](../src/Scribe.php) has static methods that register
callbacks into extraction and writing. Register them once, in a service
provider's `boot()`. Each method holds one callback, and calling it again
replaces the previous one. `scribe:generate` keeps them from one run to the
next; [`Testing\TestState`](queue-and-testing.md#testing-an-application-that-uses-the-package)
clears them between tests.

```php
namespace App\Providers;

use Hypervel\Support\ServiceProvider;
use Ipsocode\Scribe\Scribe;

class DocsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Scribe::class)) {
            return; // the package is a dev dependency and is not installed here
        }

        Scribe::afterGenerating(function (array $paths) {
            // ...
        });
    }
}
```

| Hook | Runs |
|---|---|
| [`bootstrap`](#bootstrap) | When `scribe:generate` has read its options and config, before extraction |
| [`instantiateFormRequestUsing`](#instantiateformrequestusing) | When a FormRequest is built to read its rules |
| [`normalizeEndpointUrlUsing`](#normalizeendpointurlusing) | When an endpoint's URI is worked out |
| [`resolveExampleModelUsing`](#resolveexamplemodelusing) | When the `databaseFirst` source fetches an example model |
| [`beforeResponseCall`](#beforeresponsecall) | Just before a response call |
| [`afterResponseCall`](#afterresponsecall) | Just after a response call |
| [`afterExtracting`](#afterextracting) | After every strategy has run for a route |
| [`afterGenerating`](#aftergenerating) | After every file is written |

## bootstrap

```php
use Ipsocode\Scribe\Commands\GenerateDocumentation;

Scribe::bootstrap(function (GenerateDocumentation $command) {
    // $command->getDocConfig(), $command->isForcing(), $command->shouldExtract()
});
```

Receives the running command, after it has loaded the config and parsed
`--force` and `--no-extraction`. The return value is ignored.

## instantiateFormRequestUsing

```php
use App\Models\User;
use Hypervel\Routing\Route;
use ReflectionFunctionAbstract;

Scribe::instantiateFormRequestUsing(function (string $className, Route $route, ReflectionFunctionAbstract $method) {
    $request = new $className;
    $request->setUserResolver(fn () => User::query()->first()); // for rules() that read $this->user()

    return $request;
});
```

Builds the FormRequest whose rules are read, instead of `new $className`.
Receives the class name, the route and the controller method, and returns the
instance. The route is bound to it afterwards. `null` restores the default.

## normalizeEndpointUrlUsing

```php
use Closure;
use Hypervel\Routing\Route;
use ReflectionClass;
use ReflectionFunctionAbstract;

Scribe::normalizeEndpointUrlUsing(
    function (string $uri, Route $route, ?ReflectionFunctionAbstract $method, ?ReflectionClass $controller, Closure $default): string {
        return str_starts_with($uri, 'api/legacy') ? $uri : $default();
    }
);
```

Returns the URI the endpoint is documented under. Receives the route's URI as
registered, the route, the controller method and class, and the default
normalizer, which renames `{post}` style parameters to say what they hold (see
[parameters](documenting-endpoints.md#parameters)). Return `$uri` to keep the
URI as registered. `null` restores the default.

## resolveExampleModelUsing

```php
Scribe::resolveExampleModelUsing(
    fn (string $type, array $relations, array $withCount) => $type::with($relations)
        ->withCount($withCount)
        ->orderBy('id')
        ->first()
);
```

Replaces the query behind the `databaseFirst` [example model source](#example-models).
Receives the model class and the relations and counts to load, and returns the
model, or `null` to fall through to the next source. It runs while
`Ipsocode\Scribe\Extracting\Extractor::getRouteBeingProcessed()` returns the
current route, so the row can differ per endpoint. `null` restores the default.

## beforeResponseCall

```php
use Hypervel\Http\Request;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;

Scribe::beforeResponseCall(function (Request $request, ExtractedEndpointData $endpointData) {
    $request->headers->set('X-Docs-Run', '1');
});
```

Runs after the transaction has started and the strategy's `config` overrides
are applied, just before the request is dispatched. Changes to `$request` are
sent. The return value is ignored.

## afterResponseCall

```php
use Symfony\Component\HttpFoundation\Response;

Scribe::afterResponseCall(function (Request $request, ExtractedEndpointData $endpointData, Response $response) {
    $response->headers->remove('Set-Cookie');
});
```

Runs once the response is back, before its status, content and headers are
recorded, so changes to `$response` are what gets documented. The return value
is ignored.

## afterExtracting

```php
Scribe::afterExtracting(function (ExtractedEndpointData $endpointData) {
    $endpointData->headers['X-Api-Version'] = 'v2';
});
```

Runs for each route after all seven stages, before the endpoint is written to
`.scribe/endpoints/`. Change the object to change what is documented. The return
value is ignored.

## afterGenerating

```php
Scribe::afterGenerating(function (array $paths) {
    copy($paths['openapi'], base_path('openapi.yaml'));
});
```

Runs after the page, the collection and the spec are written. Receives the
absolute paths of what was written:

```php
[
    'postman' => '/path/to/collection.json',
    'openapi' => '/path/to/openapi.yaml',
    'html' => '/path/to/index.html',        // static types
    'blade' => '/path/to/index.blade.php',  // hypervel types
    'assets' => [
        'js' => '/path/to/js',
        'css' => '/path/to/css',
        'images' => '/path/to/images',
    ],
]
```

An entry is `null` when that file was not written: the collection or the spec
when disabled, `html` or `blade` depending on the type, and the assets for the
`external_*` types.

## Example models

API resource and transformer responses are rendered from an example model.
`examples.models_source` lists the ways to get one, tried in order until one
returns a model:

| Source | How |
|---|---|
| `factoryCreate` | The model's factory, `create()`d inside the `database_connections_to_transact` transaction, then refreshed with the relations loaded |
| `factoryCreateQuietly` | The same, with model events disabled |
| `factoryMake` | The model's factory, `make()`d, not saved |
| `databaseFirst` | The first row in the table, or [`resolveExampleModelUsing`](#resolveexamplemodelusing) |

```php
'examples' => [
    'models_source' => ['databaseFirst', 'factoryMake'],
],
```

The default is `['factoryCreate', 'factoryMake', 'databaseFirst']`. A source that
throws is reported as a warning and the next one is tried. When none returns a
model, an empty instance of the class is used. Factory states and relations come
from the response's tag or attribute (`states=`, `with=`, `factoryStates:`,
`with:`). The logic is in
[`InstantiatesExampleModels`](../src/Extracting/InstantiatesExampleModels.php).

## faker_seed

`examples.faker_seed` (default `1234`) makes generated values repeat from run to
run. It seeds the Faker generator that example parameter values come from, and,
once per run, the Faker instances model factories use. Set it to `null` for
different values every run.
