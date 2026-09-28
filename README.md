# hypervel-scribe

Generate API documentation from your [Hypervel](https://github.com/hypervel/components)
routes: a browsable HTML page, an OpenAPI 3 spec and a Postman collection. A port
of [`knuckleswtf/scribe`](https://github.com/knuckleswtf/scribe).

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.**
>
> It is built for Hypervel 0.4, which has no release yet: 0.4 exists only as the
> `0.4.x-dev` branch of [`hypervel/components`](https://github.com/hypervel/components),
> and this package is developed and tested against that moving branch. Until 0.4
> ships, anything here can change without a deprecation period — the API, the
> configuration and the generated output included. Use it to evaluate or to build
> against Hypervel 0.4, and pin the version you tested.

```php
use Ipsocode\Scribe\Attributes\BodyParam;
use Ipsocode\Scribe\Attributes\Group;

#[Group('Posts')]
#[BodyParam('title', 'string', 'The headline.', example: 'Hello, world')]
public function store(StorePostRequest $request): PostResource
```

```sh
php artisan scribe:generate   # then open /docs
```

## What this is

Scribe reads an application the way someone reading its code would. It
enumerates the registered routes and runs a set of extraction strategies against
each one — docblocks, PHP attributes, FormRequest and inline validation rules,
URL, query and body parameters, headers, and responses, including a live call to
the endpoint — then writes what it found as documentation.

This is Scribe 5.11 on Hypervel's Laravel-compatible subsystems
(`Hypervel\Routing`, `Hypervel\Console`, `Hypervel\Validation`,
`Hypervel\Foundation\Http\FormRequest`, `Hypervel\Filesystem`, `Hypervel\View`).
The attributes, docblock tags, strategies and config keys are upstream's, so
[Scribe's documentation](https://scribe.knuckles.wtf/laravel/) mostly carries
over. [Differences from `knuckleswtf/scribe`](#differences-from-knuckleswtfscribe)
lists where it does not.

`Scribe::VERSION`, which `php artisan about` shows and the theme's asset file
names carry, is the upstream release this port tracks, not this package's
version.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev`. The
  package requires `hypervel/console`, `hypervel/contracts`, `hypervel/database`,
  `hypervel/filesystem`, `hypervel/foundation`, `hypervel/http`,
  `hypervel/queue`, `hypervel/routing`, `hypervel/support` and
  `hypervel/validation` `^0.4`; `hypervel/components` provides all of them.

## Installation

The package is not on Packagist, so add this repository to your application's
Composer repositories first:

```sh
composer config repositories.hypervel-scribe vcs https://github.com/ipsocode/hypervel-scribe
composer require ipsocode/hypervel-scribe
php artisan vendor:publish --tag=scribe-config
```

Hypervel 0.4 is only available as a dev branch, so your application's
`composer.json` must already allow it: `"minimum-stability": "dev"` together
with `"prefer-stable": true`. Tags are not re-tested as `0.4.x-dev` moves on,
and neither is `main` between changes: each change is tested against the
`0.4.x-dev` of its day before it merges. To pick up changes as they land,
require `ipsocode/hypervel-scribe:dev-main` instead. Each release's notes,
breaking changes first, are on the
[Releases](https://github.com/ipsocode/hypervel-scribe/releases) page.

The service provider (`Ipsocode\Scribe\ScribeServiceProvider`) and the `Scribe`
alias are discovered through the package's `extra.hypervel` block, so there is
nothing to register.

With the default `hypervel` output type the application serves the docs itself,
through routes this package registers, so install it wherever the docs are
served. The `static` types write plain files under `public/`, and a `--dev`
install where you generate them is enough.

The theme's views publish in groups, so you can override one piece without
taking a copy of the rest: `scribe-views` (all of them), `scribe-themes`,
`scribe-examples` (the example-request partials), `scribe-markdown` and
`scribe-external` (the client-side viewer pages). `scribe-translations`
publishes the language files to `lang/vendor/scribe`.

## Generating the docs

```sh
php artisan scribe:generate
```

For the default `type: hypervel` that writes:

- `resources/views/scribe/index.blade.php` — the documentation page, as a Blade
  view.
- `public/vendor/scribe/` — its CSS, JS and images (`hypervel.assets_directory`
  moves this).
- `storage/app/scribe/openapi.yaml`
- `storage/app/scribe/collection.json`
- Intermediate per-group endpoint YAML under `.scribe/`, alongside
  `.scribe/intro.md` and `.scribe/auth.md` — the two files you are meant to
  edit. Scribe rewrites them each run unless you have changed them, and
  `--force` overrides that.

The package serves those through three routes of its own, named `scribe`,
`scribe.postman` and `scribe.openapi`:

| URL             | Serves                                   |
|-----------------|------------------------------------------|
| `/docs`         | `resources/views/scribe/index.blade.php` |
| `/docs.postman` | `storage/app/scribe/collection.json`     |
| `/docs.openapi` | `storage/app/scribe/openapi.yaml`        |

Move them with `hypervel.docs_url`, put them behind auth with
`hypervel.middleware`, or turn them off with `hypervel.add_routes` and route the
docs yourself. The generated page links the collection and the spec by route
name, so with `add_routes` off it keeps the relative links for you to repoint.

With `type: static`, all of it lands in `public/docs/` instead
(`static.output_path`), with `index.html` in place of the Blade view.

The `external_static` and `external_hypervel` types write the same files, but
the page is a shell that renders the OpenAPI spec in the browser through
Scalar, Stoplight Elements or RapiDoc — set `theme` to `scalar`, `elements` or
`rapidoc`, pass viewer options through `external.html_attributes` (Elements and
RapiDoc) or `external.scalar_config` (Scalar), and note that the endpoints come
entirely from the spec, so `example_languages`, `groups.order` and
`last_updated` do not apply.

A route with an optional segment — `countries/list/{id?}` — goes into the
OpenAPI spec as one path item per URI it actually serves (`/countries/list` and
`/countries/list/{id}`), each taking only the parameters its own template names.
Operation IDs have to stay unique across the two, so the shorter URIs name what
they leave out (`listCountriesWithoutId`); the URI as registered keeps the ID it
would otherwise have.

### From a queue or a running worker

`scribe:generate` resets the caches it keeps between endpoints on the way in and
on the way out, so it is safe to run in-process from a long-lived worker —
`Artisan::call('scribe:generate')` from a queued job, a scheduled task or a
request — and to run more than once in the same worker. To regenerate on deploy
or on a schedule without a terminal, dispatch the job the package ships:

```php
use Ipsocode\Scribe\Jobs\RegenerateDocumentation;

RegenerateDocumentation::dispatch();
RegenerateDocumentation::dispatch(config: 'scribe_admin', force: true);
```

Its arguments are the command's options: `config`, `force`, `noExtraction` and
`scribeDir`. It differs from `Artisan::queue('scribe:generate')` in two ways:

- It fails when the command exits non-zero. That covers a run that stopped
  early and a run that skipped routes it could not document, so the problem
  shows up with your other failed jobs rather than as a success.
- Only one regeneration runs at a time. The lock uses the default cache store,
  so it holds across workers when that store is shared (Redis, database). A
  dispatch that finds it taken goes back on the queue for another try a minute
  later, for up to an hour. The `sync`, `coroutine` and `defer` drivers cannot
  put a job back, so there an overlapping dispatch is skipped.

## Configuration

Configure which routes are documented, strategies, auth, and OpenAPI/Postman
options in `config/scribe.php`. User hooks are available on the `Scribe` class
(`Scribe::afterGenerating(...)`, `Scribe::normalizeEndpointUrlUsing(...)`, etc.).

Your file does not have to be complete: the provider merges the package's
defaults underneath it, and the nested *option* arrays (`auth`, `examples`,
`external`, `fractal`, `groups`, `hypervel`, `openapi`, `postman`, `static`,
`try_it_out`) merge key by key, so naming `auth.enabled` alone keeps `auth.in`
and the rest of that block.

The two *lists* are the exception, and they are replaced wholesale:

| Key | If your config names it |
|---|---|
| `routes` | your list replaces the default one entirely — entries are positional, so merging them by key would graft your first entry over the package's and leave the remainder behind |
| `strategies` | **the whole block is replaced, including the stages you did not name**, and each unnamed stage then extracts nothing. Copy all seven stages across — the published file already spells them out in terms of `Config\Defaults`, and `configureStrategy()` / `removeStrategies()` are there to adjust one stage's list without retyping it |

`php artisan scribe:config-diff` prints how your config differs from the
defaults — short enough to paste into a bug report, and the quickest way to see
what a partial file actually resolved to.

## Custom strategies

`php artisan scribe:strategy AddCustomHeader` scaffolds one into
`app/Docs/Strategies/`, extending `Extracting\Strategies\Strategy`. Register it
under the stage it belongs to in `config/scribe.php`'s `strategies` array —
noting the replacement rule above.

## Testing an application that uses it

Scribe keeps caches and the `Scribe::` hooks in worker-lifetime statics, which
would otherwise carry from one test into the next. `Testing\TestState` resets
them after every test. It is registered through `extra.hypervel.test-state` in
the package's `composer.json`, so an application's suite picks it up
automatically, provided the framework's PHPUnit extension is registered in
`phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

## Differences from `knuckleswtf/scribe`

| | `knuckleswtf/scribe` | This package |
|---|---|---|
| Framework | Laravel 9.21 to 13 | Hypervel 0.4 |
| Output types | `laravel`, `external_laravel`, `static`, `external_static` | `hypervel`, `external_hypervel`, `static`, `external_static`; a config naming `laravel` or `external_laravel` is rejected with the new name |
| Serving options | `laravel.*` | `hypervel.*`, with the same keys |
| A partial `config/scribe.php` | Merged one level deep: a nested array you name replaces the package's | Option arrays merge key by key; `routes` and `strategies` are replaced whole |
| Response calls | Dispatched through the HTTP kernel | The same, each in a coroutine of its own, so the authenticated user and the session a call leaves behind die with it |
| Optional route segments in the OpenAPI spec | One path item, for the URI with the segment | One path item per URI the route serves |
| `scribe:upgrade` | Retired | Not ported |
| `scribe:generate --no-upgrade-check` | Accepted, does nothing | Removed; `scribe:config-diff` reports the same difference on demand |
| Queued regeneration | `Artisan::queue('scribe:generate')` | `Jobs\RegenerateDocumentation`: fails on a non-zero exit, never overlaps |
| Translations | A custom translation layer, installed on first use | The framework's loader; overrides go in `lang/vendor/scribe` |
| `mpociot/reflection-docblock` | A Composer dependency | Bundled under `src/Reflection/` |

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## Credits

A port of [`knuckleswtf/scribe`](https://github.com/knuckleswtf/scribe) by
Shalvah and its contributors, to
[Hypervel](https://github.com/hypervel/components). `src/Reflection/` is a
re-namespaced copy of
[`mpociot/reflection-docblock`](https://github.com/mpociot/reflection-docblock),
itself a fork of phpDocumentor's ReflectionDocBlock by Mike van Riel; see
[`src/Reflection/README.md`](src/Reflection/README.md) for why it is bundled
instead of required.

## License

MIT. See [LICENSE](LICENSE), which carries the copyright notices of this package
and of `knuckleswtf/scribe`, and [`src/Reflection/LICENSE`](src/Reflection/LICENSE)
for the bundled reflection-docblock copy.
