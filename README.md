# hypervel-scribe

Generate API documentation from your [Hypervel](https://github.com/hypervel/components)
routes: a browsable HTML page, an OpenAPI 3 spec and a Postman collection. The
package enumerates the application's registered routes and runs a set of
extraction strategies against each one (docblocks, PHP attributes, FormRequest
and inline validation rules, URL, query and body parameters, headers, and
responses, including a live call to the endpoint), then writes what it found as
documentation.

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

Your application's `composer.json` must allow Hypervel's dev branch:
`"minimum-stability": "dev"` together with `"prefer-stable": true`. The service
provider and the `Scribe` alias are discovered, so there is nothing to register.
[Getting started](docs/getting-started.md) has the details, and each release's
notes, breaking changes first, are on the
[Releases](https://github.com/ipsocode/hypervel-scribe/releases) page.

## Generating the docs

```sh
php artisan scribe:generate
```

With the default `type: hypervel`, that writes the page as a Blade view in
`resources/views/scribe/`, its assets in `public/vendor/scribe/`, and
`openapi.yaml` and `collection.json` under `scribe/` on the `local` disk. It
keeps the extracted endpoints, `intro.md` and `auth.md` in `.scribe/`, where
you can edit them. The package serves the output through three routes of its
own:

| Name             | URL             | Serves                 |
|------------------|-----------------|------------------------|
| `scribe`         | `/docs`         | the documentation page |
| `scribe.postman` | `/docs.postman` | the Postman collection |
| `scribe.openapi` | `/docs.openapi` | the OpenAPI spec       |

The `static` types write everything to `public/docs/` instead and register no
routes. See [what gets written](docs/getting-started.md#what-gets-written) for
every type.

## Documentation

The pages below live in [`docs/`](docs/README.md).

Using the package:

- [Getting started](docs/getting-started.md): installing, publishing, running `scribe:generate` and what it writes.
- [Configuration](docs/configuration.md): the options in `config/scribe.php`, and how a partial config file is merged.
- [Documenting endpoints](docs/documenting-endpoints.md): the attributes, docblock tags and validation rules that describe an endpoint.
- [Strategies](docs/strategies.md): the extraction stages, the built-in strategies and writing your own.
- [Output](docs/output.md): output types, themes, external viewers, the OpenAPI spec, the Postman collection and translations.
- [Hooks](docs/hooks.md): the callbacks an application registers through the `Scribe` class.
- [Queues and testing](docs/queue-and-testing.md): regenerating from a queue or a running worker, and resetting the package's state between tests.

Design notes, for contributors:

- [Coroutine safety](docs/design/coroutine-safety.md): why worker-lifetime state matters under Swoole, the rules every change is held to, and why extraction is sequential.
- [Run state](docs/design/run-state.md): what one `scribe:generate` run caches, when it is flushed, and what is left alone.
- [Response calls](docs/design/response-calls.md): how `ResponseCalls` builds a request and dispatches it in a coroutine of its own.
- [Config merging](docs/design/config-merging.md): how an application's partial `config/scribe.php` is merged with the package's defaults.

## Contributing

The development setup, the checks CI runs, the coroutine-safety rules every
change is held to, and how releases are cut are in
[CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## License

MIT. See [LICENSE](LICENSE). `src/Reflection/` is a re-namespaced copy of
[`mpociot/reflection-docblock`](https://github.com/mpociot/reflection-docblock)
under its own licence ([`src/Reflection/LICENSE`](src/Reflection/LICENSE)); see
[`src/Reflection/README.md`](src/Reflection/README.md).
