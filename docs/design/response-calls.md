# Response calls

[`ResponseCalls`](../../src/Extracting/Strategies/Responses/ResponseCalls.php)
(`Ipsocode\Scribe\Extracting\Strategies\Responses\ResponseCalls`) is the one
response strategy that needs no annotation. It builds a request from the
parameters the earlier stages extracted, dispatches it through the
application's own HTTP kernel, and records the status, content and headers that
come back. An endpoint that already has a successful response is left alone.

It is configured in the `responses` stage of `config/scribe.php`:

```php
use Ipsocode\Scribe\Config\Defaults;
use Ipsocode\Scribe\Extracting\Strategies;

use function Ipsocode\Scribe\Config\configureStrategy;

'responses' => configureStrategy(
    Defaults::RESPONSES_STRATEGIES,
    Strategies\Responses\ResponseCalls::withSettings(
        only: ['GET *'],
        config: ['app.debug' => false],
        timeout: 120,
    )
),
```

`withSettings()` also takes `except`, `queryParams`, `bodyParams`, `fileParams`
and `cookies`.

## Building the request

- Query and body parameters are the extracted examples, with `queryParams` and
  `bodyParams` from the settings merged over them. URL parameters are bound into
  the path. `fileParams` maps parameter names to file paths, sent as uploads.
- If the endpoint is authenticated, the call sends `auth.use_value` (a
  generated token when that is empty) where the docs show `auth.placeholder`:
  in the query, the body or the headers, as `auth.in` says. Auth data naming
  any other location throws `InvalidArgumentException`.
- The URL is `app.url` plus the bound path. A route bound to a domain gets that
  domain as its `Host`.
- The request is built as a Symfony request and promoted with
  `Hypervel\Http\Request::createFromBase()`, so a JSON body reads back through
  `$request->input()` as well as `$request->json()`.
- The `Scribe::beforeResponseCall()` hook gets the request before it is sent,
  and can change it. `Scribe::afterResponseCall()` gets the response.

## Which HTTP method

The call uses the first method `ExtractedEndpointData::getMethods()`
([source](../../camel/Extraction/ExtractedEndpointData.php)) returns. Hypervel
adds `HEAD` to every `GET` route, so `HEAD` is dropped when the route has other
methods. It is kept when it is the only one, so a `HEAD`-only route is still
called.

## Order of operations

Nothing the strategy sets up is left open when something throws:

1. Merge the parameters and place the auth value. An unknown auth location
   throws here, before any transaction or config override exists.
2. In a `try`: configure the environment (the timeout, the database
   transactions, the config overrides), build the request, run the before hook,
   and dispatch.
3. In the `finally`: roll the transactions back and restore the config.

The `finally` covers every throw after step 1, including one from configuring
the environment itself, such as a later connection in
`database_connections_to_transact` failing to start its transaction.

An `Exception` thrown by the dispatch or the after hook is caught: the strategy
warns `Exception thrown during response call for ...` and documents no response.
Anything thrown earlier in step 2 propagates once the cleanup has run.

## One coroutine per call

The dispatch runs in a coroutine of its own:

```php
return (new Waiter)->wait(function () use ($kernel, $request) {
    RequestContext::set($request);

    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}, $this->timeout, copyContext: true);
```

A real request on a Swoole worker gets a fresh coroutine, and what it leaves
behind (the request, the authenticated user, the session) lives in that
coroutine's context and ends with it. Called inline, one endpoint's response
call would hand all of that to the next endpoint and to the rest of the run.

`copyContext: true` starts the child from a copy of the parent's context
rather than an empty one. What the call adds to its context stays in the child.
Values that implement `Hypervel\Context\NonCopyableContext` are left out of the
copy, and `Hypervel\Database\Connection` is one of them, so the call resolves
its own database connection rather than using the parent's.

`RequestContext::set()` comes first because the global middleware runs before
the kernel reaches the router, and `request()` has to work there too.

The parent waits for each call to finish. This is isolation and a timeout, not
concurrency; extraction stays sequential, as
[coroutine safety](coroutine-safety.md) explains.

## Timeout

`ResponseCalls::DEFAULT_TIMEOUT` is 60 seconds. The `timeout` setting raises or
lowers it per strategy configuration. When a call outlives it, the waiter
cancels the child coroutine and throws, and the call is treated like any other
failed call: a warning and no response. A call that never returned would
otherwise hang the whole run.

## Config overrides

The `config` setting is a map of config keys to values for the duration of the
call. For each key the strategy records the current value, then applies the
override with `Config::set()`. The `finally` sets every recorded key back, so
later endpoints and the rest of the run see the application's own config.

`Config::set()` is worker-global, not coroutine-scoped. That is one of the
reasons routes are not extracted in parallel.

## Database transactions

[`DatabaseTransactionHelpers`](../../src/Extracting/DatabaseTransactionHelpers.php)
begins a transaction on each connection in `database_connections_to_transact`
(by default `[config('database.default')]`) before the call, in the strategy's
own coroutine, and rolls each one back in the `finally`. Because the call
resolves its own connection (see [one coroutine per call](#one-coroutine-per-call)),
its queries can run outside these transactions.

- A connection without `beginTransaction()` and `rollback()` throws
  `DatabaseTransactionsNotSupported`.
- A connection that fails to begin throws `CouldntStartDatabaseTransaction`.
- The rollback skips connections that do not support transactions and ignores
  errors from `rollback()` itself.

The example-model strategies (`UseApiResourceTags`, `UseResponseAttributes`,
`UseTransformerTags`) use the same helpers.

## Streamed responses

A `StreamedResponse` has no content until something calls `sendContent()`, and
its callback echoes rather than returns. The strategy wraps that callback in an
output buffer whose handler appends each chunk to a string and returns an empty
string. Every chunk is captured, including one an inner `ob_flush()` pushes out
early, and none of it reaches the console.
