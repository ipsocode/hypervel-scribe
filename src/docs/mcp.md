# MCP server

hypervel-scribe can serve the generated API docs to your customers' AI clients over the
[Model Context Protocol](https://modelcontextprotocol.io). A client can search the API, read
one operation in full, and get the authentication scheme and base URL, so it writes correct
client code without a person pasting `openapi.yaml` into the conversation. It is built on
Hypervel's MCP package and is off by default.

The server answers from the OpenAPI spec that `scribe:generate` wrote. It never runs
extraction, and it never calls your API, except through the opt-in [sandbox](#the-sandbox).
Because it reads the file on every call, running `scribe:generate` on a live worker is
picked up by the next request. Before the first run, its tools answer that the spec
cannot be read.

## Enabling the server

Set `mcp.enabled` in `config/scribe.php`, or `SCRIBE_MCP_ENABLED=true` in the environment,
and run `scribe:generate`:

```php
'mcp' => [
    'enabled' => (bool) env('SCRIBE_MCP_ENABLED', false),
],
```

Two servers are then registered, both named after `title` (or `app.name`):

- An HTTP server at `mcp.path`, `/docs/mcp` by default, beside the docs. It accepts `POST` requests only.
- A local server named `mcp.local_name`, `scribe` by default, for clients that start a process:
  `php artisan mcp:start scribe`.

With `mcp.enabled` off, neither exists.

## What the server offers

| Tool | What it does |
|---|---|
| `search-endpoints` | Lists the endpoints matching words in their path, summary, description or group, optionally limited to a group or an HTTP method. |
| `list-groups` | Lists the groups, with a description and the number of endpoints in each. |
| `describe-endpoint` | Reads one endpoint in full: parameters, request body and example, responses and examples, and security. Give its `operationId`, or its method and path. |
| `get-authentication` | Gets the security schemes, the base URLs, the authentication instructions and the placeholder the docs show for the credential. |
| `call-sandbox` | Tries an endpoint. Only with `mcp.sandbox.enabled`; see [the sandbox](#the-sandbox). |

Two resources hold the whole documents: `openapi-spec` (`scribe://openapi.yaml`) and
`postman-collection` (`scribe://collection.json`). The `integrate-endpoint` prompt takes an
`operationId` and a `language`, and tells the client to read the endpoint and its
authentication before it writes the call.

## The sandbox

`call-sandbox` lets the client try an endpoint before it writes code. It checks the request
against the endpoint's parameters and body, and answers with the response `mcp.sandbox.mode`
selects. It is off by default:

```php
'sandbox' => [
    'enabled' => (bool) env('SCRIBE_MCP_SANDBOX', false),
    'mode' => env('SCRIBE_MCP_SANDBOX_MODE', 'examples'),
    'base_url' => env('SCRIBE_MCP_SANDBOX_URL'),
    'timeout' => (float) env('SCRIBE_MCP_SANDBOX_TIMEOUT', 10),
    'max_response_bytes' => 65536,
    'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
],
```

An application config that names `mcp.sandbox` replaces the package's block whole, so name every key in it.

In `examples` mode, the default, the tool answers with the example the documentation shows for
the response, the first 2xx unless the client asks for a `status`, and calls nothing.

In `http` mode it sends the request to `mcp.sandbox.base_url`. Point that at a sandbox
environment, never at production data. The tool tells the client it is not read-only, and
these safeguards apply:

- It calls only one server. `mcp.sandbox.base_url` is used, then `try_it_out.base_url`, then `base_url`. The
  client cannot name another, and path values are encoded so they cannot change the host.
- When the application runs with `app.env` set to `production` and the sandbox would call
  `app.url` itself, the application refuses to boot.
- Only the methods in `mcp.sandbox.methods` are sent.
- The `Authorization`, `Proxy-Authorization`, `Host` and `Content-Length` headers cannot be set by the client.
  The credential is the `Authorization` header of the request to the MCP server, so the
  client acts as its own user. A local server has no such request, so the client passes a `token` instead,
  which is sent as a Bearer token. Neither is stored.
- Redirects are not followed.
- The call stops after `mcp.sandbox.timeout` seconds, and the tool returns an error.
- A response body past `mcp.sandbox.max_response_bytes` is cut off, and the tool says so.

| Key | Default | What it does |
|---|---|---|
| `mcp.sandbox.enabled` | `false` (`SCRIBE_MCP_SANDBOX`) | Add the `call-sandbox` tool. |
| `mcp.sandbox.mode` | `'examples'` (`SCRIBE_MCP_SANDBOX_MODE`) | `'examples'` or `'http'`. |
| `mcp.sandbox.base_url` | `null` (`SCRIBE_MCP_SANDBOX_URL`) | The one server `http` mode calls. `null` means `try_it_out.base_url`, then `base_url`. |
| `mcp.sandbox.timeout` | `10` (`SCRIBE_MCP_SANDBOX_TIMEOUT`) | Seconds to wait for the sandbox. |
| `mcp.sandbox.max_response_bytes` | `65536` | The size at which a response body is cut off. |
| `mcp.sandbox.methods` | `['GET', 'POST', 'PUT', 'PATCH', 'DELETE']` | The HTTP methods the tool may send. |

## Protecting the server

The HTTP server is open to anyone who can reach the URL, and it describes your whole API, so
decide who may use it.

`mcp.middleware` is applied to the HTTP server, like the middleware of any route:

```php
'middleware' => ['auth:sanctum', 'throttle:scribe-mcp'],
```

`throttle:scribe-mcp` names a rate limiter that your application defines, for example in a
service provider's `boot()`; see
[rate limiting](https://hypervel.org/docs/routing#rate-limiting):

```php
RateLimiter::for('scribe-mcp', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
```

Hypervel's MCP package also checks the `Origin` header of requests that carry one: it must
match `app.url` or an entry in `mcp.allowed_origins` in `config/mcp.php`, which
`php artisan vendor:publish --tag=mcp-config` publishes. Requests without the header, such as
those from command-line clients, pass. This is not authentication. Add an entry only for a
browser application on another origin.

The `http` sandbox forwards the caller's `Authorization` header, so protect the server with
the same authentication as the API when you turn it on.

## Connecting a client

Point the client at the HTTP URL, `https://example.com/docs/mcp`. With Claude Code:

```shell
claude mcp add --transport http my-api https://example.com/docs/mcp
```

Cursor and other clients that read an `mcp.json` file take the same URL:

```json
{
  "mcpServers": {
    "my-api": { "url": "https://example.com/docs/mcp" }
  }
}
```

To send a credential, add the client's headers setting for `Authorization`.

For a local server, give the client the command that starts it, run from your application's
directory: `php artisan mcp:start scribe`.

To try the server before connecting a client, open the MCP inspector:

```shell
php artisan mcp:inspector docs/mcp
```

Pass the `mcp.path` without its leading slash, or `scribe` for the local server.

## Next

- [Configuration](configuration.md): the `mcp` options with the rest of `config/scribe.php`.
- [Output](output.md): the OpenAPI spec the server reads.
