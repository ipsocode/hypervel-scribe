# Getting started

hypervel-scribe reads a Hypervel 0.4 application's routes and writes API
documentation from them: an HTML page, an OpenAPI 3 spec and a Postman
collection.

## Install

The package is not on Packagist. Add its repository to the application's
Composer repositories, then require it and publish the config:

```sh
composer config repositories.hypervel-scribe vcs https://github.com/ipsocode/hypervel-scribe
composer require ipsocode/hypervel-scribe
php artisan vendor:publish --tag=scribe-config
```

Hypervel 0.4 is only available as the `0.4.x-dev` branch of
`hypervel/components`, so the application's `composer.json` has to allow dev
packages while still preferring stable ones:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

The service provider (`Ipsocode\Scribe\ScribeServiceProvider`) and the `Scribe`
alias are discovered through the package's `extra.hypervel` block. There is
nothing to register.

With the default `hypervel` output type the application serves the docs itself,
through routes this package registers, so install the package wherever the docs
are served. The `static` types write plain files, and a `--dev` install where
you generate them is enough.

## Publish

| Tag | Publishes to |
|---|---|
| `scribe-config` | `config/scribe.php` |
| `scribe-views` | `resources/views/vendor/scribe/` (every view) |
| `scribe-themes` | `resources/views/vendor/scribe/themes/` |
| `scribe-examples` | `resources/views/vendor/scribe/partials/example-requests/` |
| `scribe-markdown` | `resources/views/vendor/scribe/markdown/` |
| `scribe-external` | `resources/views/vendor/scribe/external/` |
| `scribe-translations` | `lang/vendor/scribe/` |

The view tags are split so you can override one part of the theme without
copying the rest. See [output](output.md#publishing-views) and
[translations](output.md#translations).

```sh
php artisan vendor:publish --tag=scribe-examples
```

## Generate

```sh
php artisan scribe:generate
```

| Option | Effect |
|---|---|
| `--force` | Discard your edits to the extracted endpoints, `intro.md` and `auth.md` in `.scribe/`, and extract everything afresh. |
| `--no-extraction` | Skip extraction and rebuild the output from the files already in `.scribe/`. Cannot be combined with `--force`. |
| `--config=scribe` | The config file to use (`config/<name>.php`). |
| `--scribe-dir=` | Where to keep the intermediate files. Defaults to `.<config name>`, so `.scribe`. |
| `--verbose` | Print the full stack trace of an exception instead of a one-line summary. |

A route whose extraction throws is left out and its error printed, and the
command then exits non-zero.

By default, every route whose path matches `api/*` is documented. The
[`routes`](configuration.md#routes) setting changes that.

## What gets written

`type` in `config/scribe.php` decides where the output goes. The paths below are
for the default config name, `scribe`.

| `type` | Page | CSS, JS, images | `openapi.yaml`, `collection.json` |
|---|---|---|---|
| `hypervel` (default) | `resources/views/scribe/index.blade.php` | `public/vendor/scribe/` | `scribe/` on the `local` disk |
| `static` | `public/docs/index.html` | `public/docs/` | `public/docs/` |
| `external_hypervel` | `resources/views/scribe/index.blade.php` | none | `scribe/` on the `local` disk |
| `external_static` | `public/docs/index.html` | none | `public/docs/` |

- The Blade view goes in the first directory of `view.paths`.
- `hypervel.assets_directory` moves the assets, and `static.output_path` moves
  the `public/docs/` output.
- With Hypervel's default `local` disk, `scribe/` on that disk is
  `storage/app/private/scribe/`.
- `postman.enabled` and `openapi.enabled` turn the collection and the spec off.
  The `external_*` types always write the spec, because their page renders it.

See [output](output.md) for what each type looks like.

## The `.scribe` directory

Extraction writes its results to `.scribe/` before turning them into output:

| Path | What it is |
|---|---|
| `endpoints/00.yaml`, `01.yaml`, ... | The extracted endpoints, one file per group. |
| `endpoints/custom.*.yaml` | Endpoints you add by hand. See [custom endpoints](documenting-endpoints.md#custom-endpoints). |
| `endpoints.cache/` | The previous extraction, compared with `endpoints/` to find your edits. Do not edit it. |
| `intro.md`, `auth.md` | The introduction and authentication sections of the page. |
| `.filehashes` | The hashes of `intro.md` and `auth.md` as last written, used to spot your edits. |
| `append.md` | Optional. Create it to add content after the endpoints. |

You can edit the endpoint YAML, `intro.md` and `auth.md`. The next
`scribe:generate` keeps your edits: for an endpoint, each section you changed
(metadata, headers, parameters, responses, response fields) replaces the freshly
extracted one, and an edited `intro.md` or `auth.md` is skipped with a warning.
`--force` discards all of it.

Commit `.scribe/` if you edit it, so the edits survive a fresh checkout.

## The docs routes

For the `hypervel` and `external_hypervel` types the package registers three
routes, defined in [`routes/hypervel.php`](../routes/hypervel.php):

| Name | URL | Serves |
|---|---|---|
| `scribe` | `/docs` | the `scribe.index` view |
| `scribe.postman` | `/docs.postman` | `scribe/collection.json` from the `local` disk |
| `scribe.openapi` | `/docs.openapi` | `scribe/openapi.yaml` from the `local` disk |

`hypervel.docs_url` moves them and `hypervel.middleware` puts middleware (such
as auth) in front of them. With `hypervel.add_routes` set to `false` nothing is
registered, and you route the view and the two files yourself.

The generated page links the collection and the spec with
`{{ route("scribe.postman") }}` and `{{ route("scribe.openapi") }}` when those
routes exist at generation time, and keeps relative links otherwise. For
`external_hypervel` without the routes, `scribe:generate` warns that the viewer
has no spec to load.

The `static` types register no routes: the web server serves `public/docs/`
directly.

## Next

- [Configuration](configuration.md): every setting in `config/scribe.php`.
- [Documenting endpoints](documenting-endpoints.md): docblock tags, attributes
  and validation rules.
- [Strategies](strategies.md): how extraction works and how to extend it.
- [Output](output.md): themes, viewers, languages and translations.
- [Hooks](hooks.md): callbacks on the `Scribe` class.
- [Queues and testing](queue-and-testing.md): regenerating from a worker, and
  testing an application that uses the package.
