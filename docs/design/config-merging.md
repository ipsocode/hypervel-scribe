# Config merging

An application does not have to publish all of `config/scribe.php`. It can
write a file that names only what it changes, and
[`ScribeServiceProvider`](../../src/ScribeServiceProvider.php) merges the
package's defaults underneath it:

```php
$this->mergeConfigFrom(__DIR__ . '/../config/scribe.php', 'scribe');
```

For what each key does, see [configuration](../configuration.md).

## Why mergeConfigFrom() alone is not enough

On its own, `mergeConfigFrom()` is an `array_merge()` at the top level: any key
the application names replaces the package's whole value. A partial file that
names only `auth.enabled` would lose `auth.in`, `auth.name`,
`auth.placeholder` and the rest of the `auth` block.

That is not harmless. The extractor, the HTML writer, the Postman collection
writer and the OpenAPI security generator all branch on `auth.in`, so a null
`auth.in` produces silently wrong documentation rather than an error.

## Option arrays merge by key

The provider overrides Hypervel's `mergeableOptions()` hook to list the option
arrays, whose keys are names:

```php
protected function mergeableOptions(string $name): array
{
    return [
        'auth',
        'examples',
        'external',
        'fractal',
        'groups',
        'hypervel',
        'openapi',
        'postman',
        'static',
        'try_it_out',
    ];
}
```

For each of these, the application's entries replace the package's entries of
the same name, and the package's other entries stay. The merge goes one level
into the option array: a nested array inside it, such as `openapi.overrides` or
`hypervel.middleware`, is replaced whole when the application names it.

Every other key is replaced whole when the application names it, including the
plain lists `database_connections_to_transact` and `example_languages`.

## Why routes and strategies are replaced whole

- **`routes`** is a positional list. Merging it by key would graft the
  application's first entry over the package's first entry and leave the
  package's remaining entries behind. Replacing the list is the only reading
  that keeps what the application wrote.
- **`strategies`** is keyed by stage name, so it could merge by key. It is left
  out on purpose: the block is one unit. An application that names one stage
  replaces the whole block and inherits no defaults for the other six, and each
  stage it does not name extracts nothing.

[`ScribeConsumerConfigTest`](../../tests/Feature/ScribeConsumerConfigTest.php)
pins this against a partial config
([fixture](../../tests/Feature/Fixtures/config/scribe.php)). It also checks that
every option array in `config/scribe.php` except `strategies` is in
`mergeableOptions()`, so a new option array added to the config file without
being added there fails the suite.

## Writing a partial config

Name only the keys you change. Inside an option array, name only the entries
you change:

```php
// config/scribe.php
return [
    'title' => 'Acme API',

    'auth' => [
        'enabled' => true, // auth.in, auth.name and the rest keep their defaults
    ],
];
```

If you name `strategies`, name all seven stages. Build each list from
`Ipsocode\Scribe\Config\Defaults` and adjust one stage with `configureStrategy()`
or `removeStrategies()` rather than retyping it:

```php
use Ipsocode\Scribe\Config\Defaults;
use Ipsocode\Scribe\Extracting\Strategies;

use function Ipsocode\Scribe\Config\removeStrategies;

'strategies' => [
    'metadata' => [...Defaults::METADATA_STRATEGIES],
    'headers' => [
        ...Defaults::HEADERS_STRATEGIES,
        Strategies\StaticData::withSettings(data: [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]),
    ],
    'urlParameters' => [...Defaults::URL_PARAMETERS_STRATEGIES],
    'queryParameters' => [...Defaults::QUERY_PARAMETERS_STRATEGIES],
    'bodyParameters' => [...Defaults::BODY_PARAMETERS_STRATEGIES],
    'responses' => removeStrategies(
        Defaults::RESPONSES_STRATEGIES,
        [Strategies\Responses\ResponseCalls::class],
    ),
    'responseFields' => [...Defaults::RESPONSE_FIELDS_STRATEGIES],
],
```

The merge applies to the `scribe` config key only. A second config file passed
with `--config` (for example `config/scribe_admin.php`) is read as written, so
it has to be complete.

To see what a partial file resolved to, print how it differs from the package's
defaults:

```sh
php artisan scribe:config-diff
```
