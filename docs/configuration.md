# Configuration

Everything is set in `config/scribe.php`, which `php artisan vendor:publish
--tag=scribe-config` copies from [`config/scribe.php`](../config/scribe.php).
The keys below follow the file's order, with their defaults.

## Page and API details

| Key | Default | What it does |
|---|---|---|
| `title` | `config('app.name') . ' API Documentation'` | The page's `<title>`, the Postman collection's name and the OpenAPI `info.title`. |
| `description` | `''` | A short description of the API, shown in the introduction and written to the Postman collection and the OpenAPI `info.description`. |
| `intro_text` | A paragraph about the example panel | Markdown or HTML placed in the introduction after `description`. PHP strips the closing heredoc marker's indentation from every line, so keep the text level with the marker: four spaces more and Markdown renders it as code. |
| `base_url` | `config('app.url')` | The URL shown in the docs and used in the example requests, the Postman `baseUrl` variable and the OpenAPI `servers` entry. |

## Routes

`routes` is a list of rules. A route is documented when one rule matches it:

```php
'routes' => [
    [
        'match' => [
            'prefixes' => ['api/*'],
            'domains' => ['*'],
        ],
        'include' => [
            // 'users.index', 'POST /new', '/auth/*'
        ],
        'exclude' => [
            // 'GET /health', 'admin.*'
        ],
    ],
],
```

| Key | Default | What it does |
|---|---|---|
| `match.prefixes` | `['api/*']` | Path patterns, without a leading slash. `*` matches anything. |
| `match.domains` | `['*']` | Domain patterns. |
| `include` | `[]` | Routes to document even if `match` does not cover them. |
| `exclude` | `[]` | Routes to leave out even if `match` covers them. |

`include` and `exclude` take a route name (`users.*`), a path with or without the
leading slash (`/auth/*`), or a method and path (`GET /health`), each with `*`
wildcards. The package's own `scribe` routes are always excluded, and so is
`telescope/*` when Hypervel Telescope is installed.

## Output

| Key | Default | What it does |
|---|---|---|
| `type` | `'hypervel'` | `hypervel`, `static`, `external_hypervel` or `external_static`. Any other value is rejected. See [output types](output.md#output-types). |
| `theme` | `'default'` | `default` or `elements` for the `hypervel` and `static` types; `scalar`, `elements` or `rapidoc` for the `external_*` types. |
| `static.output_path` | `'public/docs'` | Where the `static` types write the page, its assets, the spec and the collection. |
| `hypervel.add_routes` | `true` | Register the [docs routes](getting-started.md#the-docs-routes) for the `hypervel` and `external_hypervel` types. |
| `hypervel.docs_url` | `'/docs'` | The page's URL. The collection and spec are served at `<docs_url>.postman` and `<docs_url>.openapi`. |
| `hypervel.assets_directory` | `null` | Directory under `public/` for the page's CSS, JS and images. `null` means `vendor/scribe`. |
| `hypervel.middleware` | `[]` | Middleware for the docs routes. |
| `external.html_attributes` | `[]` | Attributes added to the Elements or RapiDoc element. They take precedence over the view's own. |
| `external.scalar_config` | `[]` | Scalar's configuration object. The spec's URL is added as `url`. |

## Try It Out

| Key | Default | What it does |
|---|---|---|
| `try_it_out.enabled` | `true` | Add a button that sends a request from the browser. The API has to allow it with CORS. |
| `try_it_out.base_url` | `null` | The URL requests go to. `null` means `base_url`. |
| `try_it_out.use_csrf` | `false` | Fetch `csrf_url` before each request and send the `XSRF-TOKEN` cookie's value as an `X-XSRF-TOKEN` header. |
| `try_it_out.csrf_url` | `'/sanctum/csrf-cookie'` | The URL fetched when `use_csrf` is on. |

## Authentication

| Key | Default | What it does |
|---|---|---|
| `auth.enabled` | `false` | Set to `true` if any endpoint is authenticated. |
| `auth.default` | `false` | Whether endpoints are authenticated unless marked otherwise. Set `enabled` too. Mark single endpoints with `@authenticated` or `@unauthenticated`. |
| `auth.in` | `AuthIn::BEARER->value` | Where the credential goes: `bearer`, `basic`, `header`, `query`, `body` or `query_or_body` (the cases of `Ipsocode\Scribe\Config\AuthIn`). |
| `auth.name` | `'key'` | The parameter or header name. `bearer` and `basic` always use `Authorization`. |
| `auth.use_value` | `env('SCRIBE_AUTH_KEY')` | The credential sent with response calls. It never appears in the docs. When empty, a random value is used. |
| `auth.placeholder` | `'{YOUR_AUTH_KEY}'` | The value shown in example requests. `null` shows a random value. |
| `auth.extra_info` | A sentence about the dashboard | Markdown or HTML added to the authentication section and to the OpenAPI security scheme's description. |

## Examples, Postman and OpenAPI

| Key | Default | What it does |
|---|---|---|
| `example_languages` | `['bash', 'javascript']` | Languages for the example requests: `bash`, `javascript`, `php`, `python`, or one you add. Not used by the `external_*` types. See [example languages](output.md#example-languages). |
| `postman.enabled` | `true` | Write a Postman v2.1.0 collection. |
| `postman.overrides` | `[]` | Values set on the collection by dotted key, e.g. `'info.version' => '2.0.0'`. |
| `openapi.enabled` | `true` | Write an OpenAPI spec. The `external_*` types write it regardless. |
| `openapi.version` | `'3.0.3'` | `'3.0.3'` or `'3.1.0'`. |
| `openapi.overrides` | `[]` | Values merged into the spec's root by dotted key, e.g. `'info.version' => '2.0.0'`. |
| `openapi.generators` | `[]` | Extra generator classes extending `Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`. See [overrides and generators](output.md#overrides-and-generators). |

## Groups, logo and last updated

| Key | Default | What it does |
|---|---|---|
| `groups.default` | `'Endpoints'` | The group for endpoints that name none. |
| `groups.order` | `[]` | The order of groups, subgroups and endpoints. Empty sorts groups by name and keeps endpoints in route order. Not used by the `external_*` types. |
| `logo` | `false` | The `src` of a logo image, or `false` for none. |
| `last_updated` | `'Last updated: {date:F j, Y}'` | The "last updated" text. See [last updated](output.md#last-updated). Not used by the `external_*` types. |

`groups.order` lists group names. A group name used as a key holds the order of
its subgroups and endpoints, and a subgroup name used as a key holds the order of
its endpoints. Endpoints are written as `METHOD /uri`. `*` stands for every
group not listed, so groups after it go last:

```php
'order' => [
    'Getting started',
    'Posts' => [
        'GET /api/posts',
        'Moderation' => [
            'POST /api/posts/{id}/pin',
        ],
    ],
    '*',
    'Admin',
],
```

## Example values

| Key | Default | What it does |
|---|---|---|
| `examples.faker_seed` | `1234` | Seed for generated example values, so each run produces the same ones. `null` for different values every run. See [hooks](hooks.md#faker_seed). |
| `examples.models_source` | `['factoryCreate', 'factoryMake', 'databaseFirst']` | How example models for API resource and transformer responses are obtained, tried in order. See [example models](hooks.md#example-models). |

## Strategies

`strategies` maps each of the seven extraction stages to its list of strategies.
The published file builds each list from `Ipsocode\Scribe\Config\Defaults`, adds
static `Content-Type` and `Accept` headers, and limits response calls to `GET`
routes with `app.debug` off:

```php
'responses' => configureStrategy(
    Defaults::RESPONSES_STRATEGIES,
    Strategies\Responses\ResponseCalls::withSettings(
        only: ['GET *'],
        config: [
            'app.debug' => false,
        ]
    )
),
```

See [strategies](strategies.md).

## Database and Fractal

| Key | Default | What it does |
|---|---|---|
| `database_connections_to_transact` | `[config('database.default')]` | Connections on which a transaction is begun before, and rolled back after, building API resource and transformer responses and making response calls. Every connection listed must support transactions. A response call runs in a coroutine of its own and may not use the same connection; see [response calls](strategies.md#response-calls). |
| `fractal.serializer` | `null` | A `league/fractal` serializer class for transformer responses. |

## Keys not in the published file

| Key | Default | What it does |
|---|---|---|
| `routeMatcher` | `Ipsocode\Scribe\Matching\RouteMatcher::class` | The class that picks the routes to document. It must implement `Ipsocode\Scribe\Matching\RouteMatcherInterface`. |

## A partial config file

`config/scribe.php` does not have to be complete. The service provider merges
the package's defaults underneath it:

- A top-level key you leave out takes the package's value.
- The option arrays `auth`, `examples`, `external`, `fractal`, `groups`,
  `hypervel`, `openapi`, `postman`, `static` and `try_it_out` merge one level
  deep. Naming `auth.enabled` alone keeps `auth.in` and the rest of `auth`. A key
  you do name replaces the package's value for that key whole, so naming
  `openapi.overrides` replaces that array.
- `routes` is replaced whole. Its entries are positional, so your list is the
  list.
- `strategies` is replaced whole, including the stages you do not name. A stage
  you leave out extracts nothing. Spell out all seven, using `Defaults` for the
  ones you do not change and [`configureStrategy()` or
  `removeStrategies()`](strategies.md#changing-a-stages-list) for the ones you
  do.

```php
return [
    'type' => 'static',
    'auth' => [
        'enabled' => true, // auth.in, auth.name and the rest keep their defaults
    ],
];
```

The merge applies to the `scribe` config only. A file used through
`--config=<name>` is read as written, with no defaults underneath. The reasons
behind these rules are in [config merging](design/config-merging.md).

## Several configurations

`scribe:generate --config=scribe_admin` reads `config/scribe_admin.php` and
names its output after it: `resources/views/scribe_admin/`, `public/vendor/scribe_admin/`,
`scribe_admin/` on the `local` disk and `.scribe_admin/`. The docs routes serve
the `scribe` output only, so route another configuration's output yourself or use
a `static` type for it.

## Comparing with the defaults

```sh
php artisan scribe:config-diff
php artisan scribe:config-diff --config=scribe_admin
```

`scribe:config-diff` prints one `key => value` line for each setting that
differs from the package's defaults, or `SAME AS DEFAULT CONFIG` when none does.
It is short enough to paste into a bug report and shows what a partial file
resolved to.

- `description`, `intro_text`, `auth.extra_info`, `routes`, `groups` and
  `example_languages` are never reported.
- `examples.models_source` and each `strategies` stage are compared as unordered
  lists and reported as `added ...: removed ...`.

The command is implemented by [`DiffConfig`](../src/Commands/DiffConfig.php) and
[`ConfigDiffer`](../src/Tools/ConfigDiffer.php).
