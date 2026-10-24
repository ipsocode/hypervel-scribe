# Documentation

`ipsocode/hypervel-scribe` generates API documentation (an HTML page, an
OpenAPI spec and a Postman collection) from a Hypervel application's routes.

## Using the package

- [Getting started](getting-started.md): install the package, publish the config and run `scribe:generate`.
- [Configuration](configuration.md): the options in `config/scribe.php`.
- [Documenting endpoints](documenting-endpoints.md): the attributes and docblock tags that describe an endpoint.
- [Strategies](strategies.md): the extraction stages, the built-in strategies and writing your own.
- [Output](output.md): the output types and what each one writes.
- [Hooks](hooks.md): the callbacks an application registers through the `Scribe` class.
- [Queues and testing](queue-and-testing.md): regenerating from a queue or a running worker, and resetting the package's state between tests.

## Contributing

[CONTRIBUTING.md](../../CONTRIBUTING.md) covers the development setup, the
checks, the coroutine-safety rules every change is held to, and the design
notes on how the package is built.
