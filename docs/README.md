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

## Design notes

For contributors. [CONTRIBUTING.md](../CONTRIBUTING.md) covers the development
setup and the checks.

- [Coroutine safety](design/coroutine-safety.md): why worker-lifetime state matters under Swoole, the rules every change is held to, and why extraction is sequential.
- [Run state](design/run-state.md): what one `scribe:generate` run caches, when it is flushed, and what is left alone.
- [Response calls](design/response-calls.md): how `ResponseCalls` builds a request and dispatches it in a coroutine of its own.
- [Config merging](design/config-merging.md): how an application's partial `config/scribe.php` is merged with the package's defaults.
