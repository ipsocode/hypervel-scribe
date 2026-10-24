# hypervel-scribe

Generate API documentation from your [Hypervel](https://github.com/hypervel/components)
routes: a browsable HTML page, an OpenAPI 3 spec and a Postman collection.

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
#[Group('Posts')]
#[BodyParam('title', 'string', 'The headline.', example: 'Hello, world')]
public function store(StorePostRequest $request): PostResource
```

With these attributes from `Ipsocode\Scribe\Attributes` on a controller method,
`php artisan scribe:generate` documents its endpoint on the page at `/docs`.

## Requirements

- PHP 8.4 or newer (CI runs 8.4 and 8.5)
- Hypervel 0.4, which today means `hypervel/components` at `0.4.x-dev` (the package requires it whole, not its split packages)

## Installation

```sh
composer require ipsocode/hypervel-scribe
php artisan vendor:publish --tag=scribe-config
```

Your application's `composer.json` must allow Hypervel's dev branch:
`"minimum-stability": "dev"` together with `"prefer-stable": true`.

## Documentation

Start with [getting started](src/docs/getting-started.md); the
[documentation index](src/docs/index.md) lists every page.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Report security issues privately, as
described in [SECURITY.md](.github/SECURITY.md), rather than in a public issue.

## Credits

A port of [`knuckleswtf/scribe`](https://github.com/knuckleswtf/scribe) by
Shalvah and its contributors, to [Hypervel](https://github.com/hypervel/components).
LICENSE carries its copyright notice alongside this package's.

## License

MIT. See [LICENSE](LICENSE).
