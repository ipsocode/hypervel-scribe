# Strategies

Extraction runs in seven stages for each route. Each stage runs its list of
strategies, in order, from `strategies` in `config/scribe.php`. A strategy is a
class extending
[`Ipsocode\Scribe\Extracting\Strategies\Strategy`](../src/Extracting/Strategies/Strategy.php)
that looks at the endpoint and returns what it found.

## Stages and default lists

The default lists are constants on
[`Ipsocode\Scribe\Config\Defaults`](../src/Config/Defaults.php). Class names
below are relative to `Ipsocode\Scribe\Extracting\Strategies`.

| Stage | Constant | Strategies |
|---|---|---|
| `metadata` | `METADATA_STRATEGIES` | `Metadata\GetFromDocBlocks`, `Metadata\GetFromMetadataAttributes` |
| `headers` | `HEADERS_STRATEGIES` | `Headers\GetFromHeaderAttribute`, `Headers\GetFromHeaderTag` |
| `urlParameters` | `URL_PARAMETERS_STRATEGIES` | `UrlParameters\GetFromLaravelAPI` (the route and its type hints), `UrlParameters\GetFromUrlParamAttribute`, `UrlParameters\GetFromUrlParamTag` |
| `queryParameters` | `QUERY_PARAMETERS_STRATEGIES` | `QueryParameters\GetFromFormRequest`, `QueryParameters\GetFromInlineValidator`, `QueryParameters\GetFromQueryParamAttribute`, `QueryParameters\GetFromQueryParamTag` |
| `bodyParameters` | `BODY_PARAMETERS_STRATEGIES` | `BodyParameters\GetFromFormRequest`, `BodyParameters\GetFromInlineValidator`, `BodyParameters\GetFromBodyParamAttribute`, `BodyParameters\GetFromBodyParamTag` |
| `responses` | `RESPONSES_STRATEGIES` | `Responses\UseResponseAttributes`, `Responses\UseTransformerTags`, `Responses\UseApiResourceTags`, `Responses\UseResponseTag`, `Responses\UseResponseFileTag`, `Responses\ResponseCalls` |
| `responseFields` | `RESPONSE_FIELDS_STRATEGIES` | `ResponseFields\GetFromResponseFieldAttribute`, `ResponseFields\GetFromResponseFieldTag` |

[Documenting endpoints](documenting-endpoints.md) describes what each of them
reads.

Results from later strategies in a stage are combined with earlier ones:

- Parameters and response fields are keyed by name, and a later strategy's
  values replace an earlier one's field by field.
- Headers are keyed by name; a later non-empty value replaces an earlier one.
- Metadata keys are replaced, except that an empty or `null` value does not
  replace one already set.
- Responses are all kept, then sorted by status.

## Changing a stage's list

The `strategies` block is replaced whole when you name it in your config, so
every stage has to be listed. Build each list from `Defaults` and adjust it with
the helpers in [`src/Config/helpers.php`](../src/Config/helpers.php):

```php
use Ipsocode\Scribe\Config\Defaults;
use Ipsocode\Scribe\Extracting\Strategies;

use function Ipsocode\Scribe\Config\configureStrategy;
use function Ipsocode\Scribe\Config\removeStrategies;

'strategies' => [
    'metadata' => [...Defaults::METADATA_STRATEGIES],
    'headers' => [...Defaults::HEADERS_STRATEGIES],
    'urlParameters' => [...Defaults::URL_PARAMETERS_STRATEGIES],
    'queryParameters' => [...Defaults::QUERY_PARAMETERS_STRATEGIES],
    'bodyParameters' => removeStrategies(
        Defaults::BODY_PARAMETERS_STRATEGIES,
        [Strategies\BodyParameters\GetFromInlineValidator::class],
    ),
    'responses' => configureStrategy(
        Defaults::RESPONSES_STRATEGIES,
        Strategies\Responses\ResponseCalls::withSettings(only: ['GET *']),
    ),
    'responseFields' => [...Defaults::RESPONSE_FIELDS_STRATEGIES],
],
```

- `removeStrategies(array $list, array $classNames)` returns the list without
  those strategies.
- `configureStrategy(array $list, array $tuple)` replaces the strategy named in
  the tuple, keeping its position, or appends the tuple when the list does not
  have it.

## Settings

An entry in a list is either a class name or a tuple of class name and settings:

```php
[Strategies\Responses\ResponseCalls::class, ['only' => ['GET *'], 'config' => ['app.debug' => false]]]
```

`Strategy::wrapWithSettings(only: [...], except: [...], otherSettings: [...])`
builds the tuple. Some strategies have a `withSettings()` that names their own
settings as arguments.

Every strategy accepts `only` and `except`. With `only`, the strategy runs for
matching routes only; with `except`, for every route but those. Each pattern is a
route name (`users.*`), a path with or without the leading slash (`api/posts/*`,
`/api/posts/*`), or a method and path (`GET *`, `POST /safe/*`), and `*` matches
anything.

## Static data

`StaticData` returns its `data` setting as it is, which suits fixed headers:

```php
'headers' => [
    ...Defaults::HEADERS_STRATEGIES,
    Strategies\StaticData::withSettings(data: [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ]),
],
```

The name `'static_data'` can stand in for the class, with the data alone or with
`data` and `only` or `except`:

```php
['static_data', ['X-Api-Version' => 'v2']],
['static_data', ['data' => ['X-Api-Version' => 'v2'], 'only' => ['GET *']]],
```

## Response calls

`ResponseCalls` builds a request from the extracted parameters, dispatches it
through the application's HTTP kernel and records the response. It does nothing
when an earlier strategy already found a `2xx` response.

| `withSettings()` argument | What it does |
|---|---|
| `only`, `except` | Routes to call, or not to call. |
| `config` | Config values set for the call and restored after it, e.g. `['app.debug' => false]`. |
| `queryParams` | Query parameters added to every call. |
| `bodyParams` | Body parameters added to every call. |
| `fileParams` | Files added to every call: parameter name to file path. |
| `cookies` | Cookies sent with every call. |
| `timeout` | Seconds to wait for the call before abandoning it. Default `60.0`. |

Each call runs in a coroutine of its own, so the authenticated user and session
it leaves behind end with it. A transaction is begun on each connection in
`database_connections_to_transact` before the call and rolled back after it, in
the strategy's own coroutine. Hypervel does not copy database connections into a
child coroutine, so the call can run its queries on another pooled connection,
outside that transaction: do not count on the rollback to undo what the call
writes, and keep response calls to routes that only read (the published config
limits them to `GET`). With `auth.enabled`, authenticated endpoints get
`auth.use_value` as their credential, or a random one. An exception in the endpoint is rendered by the
application's exception handler and recorded like any other response; a call
that throws past the kernel or runs out of time prints a warning and records
nothing.
[`Scribe::beforeResponseCall()` and `Scribe::afterResponseCall()`](hooks.md)
run around it. [Response calls](design/response-calls.md) describes the call in
detail.

## Writing a strategy

```sh
php artisan scribe:strategy AddApiVersionHeader
```

[`scribe:strategy`](../src/Commands/MakeStrategy.php) writes
`app/Docs/Strategies/AddApiVersionHeader.php`, in the `App\Docs\Strategies`
namespace, from
[`stubs/strategy.stub`](../src/Commands/stubs/strategy.stub). `--force`
overwrites an existing file. The class implements `__invoke()`, which receives
the endpoint so far and the strategy's settings, and returns an array for its
stage, or `null` to add nothing:

```php
namespace App\Docs\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;

class AddApiVersionHeader extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        return ['X-Api-Version' => $settings['version'] ?? 'v1'];
    }
}
```

What to return, by stage:

| Stage | Shape |
|---|---|
| `metadata` | Any of `groupName`, `groupDescription`, `subgroup`, `subgroupDescription`, `title`, `description`, `authenticated`, `deprecated`. |
| `headers` | `['Header-Name' => 'value']` |
| `urlParameters`, `queryParameters`, `bodyParameters` | `['name' => ['type' => 'string', 'description' => '...', 'required' => true, 'example' => '...']]` |
| `responses` | A list of `['status' => 200, 'content' => '...', 'description' => '...']` |
| `responseFields` | `['name' => ['type' => 'string', 'description' => '...']]` |

`$endpointData` holds the route (`route`), the controller and method reflections
(`controller`, `method`), the URI and methods, and everything the earlier stages
extracted. `$this->config` is the Scribe config, read with
`$this->config->get('auth.enabled')`. The stub uses the
`Ipsocode\Scribe\Extracting\ParamHelpers` trait, which generates example values
and casts values to a type.

Register the class in the stage it belongs to, with settings if it has any:

```php
'headers' => [
    ...Defaults::HEADERS_STRATEGIES,
    AddApiVersionHeader::wrapWithSettings(only: ['api/v2/*'], otherSettings: ['version' => 'v2']),
],
```
