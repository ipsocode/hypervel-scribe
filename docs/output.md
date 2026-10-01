# Output

`scribe:generate` writes an HTML page, a Postman collection and an OpenAPI spec.
This page covers how they look and what shapes them. For where each file goes,
see [what gets written](getting-started.md#what-gets-written).

## Output types

| `type` | The page | Served by |
|---|---|---|
| `hypervel` | A Blade view rendered by a bundled theme | the application, through the [docs routes](getting-started.md#the-docs-routes) |
| `static` | `index.html` rendered by a bundled theme | the web server, from `static.output_path` (`public/docs`) |
| `external_hypervel` | A Blade view that loads the OpenAPI spec into a client-side viewer | the application, through the docs routes |
| `external_static` | An `index.html` that loads the spec into a client-side viewer | the web server, from `static.output_path` |

The `hypervel` types keep the collection and the spec on the `local` disk, out
of the browser's reach, and serve them through the `scribe.postman` and
`scribe.openapi` routes while `hypervel.add_routes` is on. The `static` types
write them next to `index.html`.

## Themes

For `hypervel` and `static`, `theme` picks the page's layout:

- `default`: a three-column page with the example requests and responses beside
  each endpoint.
- `elements`: a layout in the style of Stoplight Elements.

Both render the endpoints themselves, and their CSS, JS and images are written
with the page.

## External viewers

For `external_hypervel` and `external_static`, `theme` names a viewer that renders
the OpenAPI spec in the browser, loading its own assets from a CDN:

| `theme` | Viewer | Options |
|---|---|---|
| `scalar` | Scalar | `external.scalar_config`, passed as Scalar's configuration object with the spec's URL added as `url` |
| `elements` | Stoplight Elements | `external.html_attributes`, added to `<elements-api>` |
| `rapidoc` | RapiDoc | `external.html_attributes`, added to `<rapi-doc>` |

```php
'type' => 'external_static',
'theme' => 'rapidoc',
'external' => [
    'html_attributes' => [
        'theme' => 'dark',
    ],
],
```

Attributes in `html_attributes` come before the view's own, so they take
precedence. Elements and RapiDoc also get `logo`, and hide their own request
tester when `try_it_out.enabled` is `false`.

Everything the viewer shows comes from the spec, so `example_languages`,
`groups.order` and `last_updated` do not apply. The spec is written whether or
not `openapi.enabled` is set. A `theme` with no view under `external/` is
rejected when the page is written.

## Example languages

`example_languages` lists the languages of the example requests shown for each
endpoint. The bundled ones are `bash`, `javascript`, `php` and `python`, each a
view in
[`resources/views/partials/example-requests`](../resources/views/partials/example-requests).

To add one, create
`resources/views/vendor/scribe/partials/example-requests/<language>.md.blade.php`
and add `<language>` to `example_languages`. The view is rendered with Blade and
then Markdown, so it holds a fenced code block. It receives `$endpoint`, an
`Ipsocode\Camel\Output\OutputEndpointData`, and `$baseUrl`:

````blade
```http
{{ $endpoint->httpMethods[0] }} {{ rtrim($baseUrl, '/') }}/{{ ltrim($endpoint->boundUri, '/') }}
@foreach($endpoint->headers as $header => $value)
{{ $header }}: {{ $value }}
@endforeach
```
````

`$endpoint->cleanQueryParameters`, `$endpoint->cleanBodyParameters` and
`$endpoint->fileParameters` hold the example values. The bundled views show how
they are used.

## Introduction, authentication and appendix

The page starts with `.scribe/intro.md` and `.scribe/auth.md`, and ends with
`.scribe/append.md` when it exists:

| File | Written by | Content |
|---|---|---|
| `intro.md` | `scribe:generate` | `description`, the base URL and `intro_text` |
| `auth.md` | `scribe:generate` | How to authenticate, from the `auth` settings, and `auth.extra_info` |
| `append.md` | you | Anything to show after the endpoints |

`scribe:generate` rewrites `intro.md` and `auth.md` on every run unless you have
edited them since it last wrote them, which it tracks in `.scribe/.filehashes`.
An edited file is skipped with a warning. `--force` overwrites it. The files are
only written during extraction, so `--no-extraction` leaves them as they are.

Level-one and level-two headings in these files appear in the page's table of
contents. The views they are rendered from are in
[`resources/views/markdown`](../resources/views/markdown).

## Last updated

`last_updated` is the text shown as the page's last-updated line. Two tokens are
replaced in it when the docs are generated:

| Token | Becomes |
|---|---|
| `{date:<format>}` | The current date, formatted by PHP's `date()`: `{date:F j, Y}` |
| `{git:short}`, `{git:long}` | The current Git commit, short or full, from `git rev-parse` |

```php
'last_updated' => 'Last updated: {date:F j, Y} ({git:short})',
```

## Logo

`logo` is used as the `src` of the logo image, so it must be a URL or path the
browser can load. `false` shows no logo.

```php
'logo' => '../img/logo.png', // static type, with the page in public/docs
'logo' => 'img/logo.png',    // hypervel type
```

## Try It Out

With `try_it_out.enabled`, each endpoint on a themed page gets a button for
sending the request from the browser, to `try_it_out.base_url` or `base_url`.
The API has to allow the docs' origin with CORS. For cookie-based
authentication, `try_it_out.use_csrf` fetches `try_it_out.csrf_url` first and
sends the `XSRF-TOKEN` cookie back as an `X-XSRF-TOKEN` header.

## OpenAPI spec

`openapi.version` is `'3.0.3'` or `'3.1.0'`. Each group becomes a tag. When
`auth.enabled` is on, the spec declares a security scheme from `auth.in` and
`auth.name` (for `bearer`, `basic`, `header` and `query`) and applies it to every
endpoint except the unauthenticated ones.

Operation IDs are the endpoint's title in camel case (`List countries` becomes
`listCountries`), or the method and URI when it has no title.

### Optional route segments

OpenAPI cannot mark a path segment optional, so a route with one is written as one
path item per URI it serves. `countries/list/{id?}` becomes `/countries/list`
and `/countries/list/{id}`, each with only the parameters its own path names.
The shorter path's operation ID names what it leaves out, so it stays unique:
`listCountriesWithoutId`. The full path keeps `listCountries`.

### Overrides and generators

`openapi.overrides` sets values on the spec's root by dotted key:

```php
'overrides' => [
    'info.version' => '2.0.0',
],
```

`openapi.generators` adds classes that extend
[`Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator`](../src/Writing/OpenApiSpecGenerators/OpenApiGenerator.php).
They run after the built-in generators, and each can change the root document
(`root()`), each operation (`pathItem()`) and each path's parameters
(`pathParameters()`):

```php
namespace App\Docs;

use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

class MarkInternalOperations extends OpenApiGenerator
{
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $pathItem['x-internal'] = str_starts_with($endpoint->uri, 'api/internal');

        return $pathItem;
    }
}
```

```php
'openapi' => [
    'generators' => [
        \App\Docs\MarkInternalOperations::class,
    ],
],
```

## Postman collection

The collection uses Postman's v2.1.0 format, with one folder per group and the
base URL as a `baseUrl` variable. `postman.overrides` sets values on it by dotted
key, like `openapi.overrides`.

## Publishing views

The views live under the `scribe::` namespace. A copy in
`resources/views/vendor/scribe/` takes precedence, and the publish tags copy one
part at a time:

| Tag | Views |
|---|---|
| `scribe-views` | all of them |
| `scribe-themes` | `themes/default`, `themes/elements` |
| `scribe-examples` | `partials/example-requests` |
| `scribe-markdown` | `markdown/intro`, `markdown/auth` |
| `scribe-external` | `external/scalar`, `external/elements`, `external/rapidoc` |

```sh
php artisan vendor:publish --tag=scribe-themes
```

Regenerate the docs after editing a view: the page is rendered from them when
`scribe:generate` runs.

## Translations

The page's fixed text (headings, labels, the authentication instructions) comes
from [`lang/en/scribe.php`](../lang/en/scribe.php), through Hypervel's
translator, under the `scribe::scribe.` prefix.

To override strings or add a locale, create
`lang/vendor/scribe/<locale>/scribe.php`, or publish the English file there with:

```sh
php artisan vendor:publish --tag=scribe-translations
```

The file is merged over the package's, so it only needs the keys it changes:

```php
// lang/vendor/scribe/en/scribe.php
return [
    'headings' => [
        'auth' => 'Authentication',
    ],
];
```

The application's locale picks the file, and a key missing from it falls back
to `en`.
