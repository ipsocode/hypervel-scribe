# hypervel-scribe documentation

hypervel-scribe generates API documentation (an HTML page, an OpenAPI 3 spec and
a Postman collection) from a Hypervel application's routes. The pages are listed
in reading order, starting with installation.

## Setting up

- [Getting started](getting-started.md): installing, publishing, running `scribe:generate`, what it writes and the routes that serve it.
- [Configuration](configuration.md): the options in `config/scribe.php`, and how a partial config file is merged.

## Writing the documentation

- [Documenting endpoints](documenting-endpoints.md): the attributes, docblock tags and validation rules that describe an endpoint, and endpoints added by hand.
- [Strategies](strategies.md): the extraction stages, the built-in strategies, response calls and writing your own.
- [Output](output.md): output types, themes, external viewers, the OpenAPI spec, the Postman collection and translations.

## Integrating with your application

- [MCP server](mcp.md): serving the generated docs to AI clients, the opt-in sandbox, protecting the route and connecting a client.
- [Hooks](hooks.md): the callbacks an application registers through the `Scribe` class.
- [Queues and testing](queue-and-testing.md): regenerating from a queue or a running worker, and resetting the package's state between tests.

Contributing: [CONTRIBUTING.md](https://github.com/ipsocode/hypervel-scribe/blob/main/CONTRIBUTING.md) has the development setup, the checks, the coroutine-safety rules and the design notes on how the package is built.
