# Coroutine safety

A Hypervel worker is long-lived and serves many requests at once as Swoole
coroutines. A `static` property, a container binding or a config value lives as
long as the worker, and every coroutine on it sees the same one. State that
would end with the request under PHP-FPM is shared here.

`scribe:generate` is a console command, but it is not exempt. An application can
run it inside a worker with `Artisan::call('scribe:generate')`, from a queued
job, a scheduled task or a request, and the package ships a job that does
exactly that ([`Jobs\RegenerateDocumentation`](../../src/Jobs/RegenerateDocumentation.php)).

## Rules

Every change is held to these:

- No new `static` property unless its class has a `flushState()` that
  [`Testing\TestState`](../../src/Testing/TestState.php) reaches: through
  [`Tools\RunState::flush()`](../../src/Tools/RunState.php) for a per-run cache,
  directly for configuration an application sets at boot. See
  [run state](run-state.md).
- Request-scoped state goes through Hypervel's context
  (`Hypervel\Context\RequestContext`, `Hypervel\Context\CoroutineContext`), as
  `ResponseCalls` does with `RequestContext::set()`, never through a static or a
  container rebinding.
- No native `sleep()` or `usleep()`, no unguarded `shell_exec()`, no `exit`.
  Commands return an exit code.
- Hypervel APIs only. Tests reach the container with `$app->get(...)`, never
  with array access.
- `src/Reflection/` is vendored third-party code. Leave it alone unless the
  change is about it.
- New behaviour comes with a test. `@codeCoverageIgnore` is not a way past the
  coverage gate.

The conventions check enforces the rules a script can decide (framework
namespaces, container array access, `sleep()` and `exit`, shell calls,
coverage ignores):

```sh
php .github/scripts/conventions.php
```

Testbench runs every test method inside a real coroutine, and
[`CoroutineSafetyTest`](../../tests/Feature/CoroutineSafetyTest.php) asserts
against that directly.

## Where the package keeps state

The generator does not serve concurrent requests. What it keeps between one
endpoint and the next lives in worker-lifetime statics, and every class that
holds one has a `flushState()`. There are two kinds.

**Per-run caches**, built up during one `scribe:generate` run:

- parsed controller ASTs: `Extracting\MethodAstParser`
- class and method docblocks: `Extracting\RouteDocBlocker`
- a controller method's type-hinted models: `Extracting\Shared\UrlParamsNormalizer`
- each model's example route key: `Extracting\Strategies\UrlParameters\GetFromLaravelAPI`
- the one Faker generator example values are drawn from: `Extracting\SeededFaker`
- per-class DTO reflection: `Ipsocode\Camel\BaseDTO`
- the example models the response strategies reuse, and whether the model
  factories' Faker has been seeded: `UseResponseAttributes`,
  `UseApiResourceTags`, `UseTransformerTags`
- the route being processed: `Extracting\Extractor`
- the console the run reports to, and its warning buffer: `Tools\ConsoleOutputUtils`

**Configuration an application sets at boot**:

- the `Scribe::` hooks, stored in `Tools\Globals`
- the docblock tag handlers, registered with
  `Ipsocode\Scribe\Reflection\DocBlock\Tag::registerTagHandler()`

`Tools\RunState::flush()` clears the first kind, and `scribe:generate` calls it
on the way in and on the way out. It leaves the second kind alone.
`Testing\TestState` clears both. [Run state](run-state.md) has the details.

Flushing makes one run after another safe in the same worker. Two runs at the
same time in one worker would still reset each other's state half way through.
That is why `Jobs\RegenerateDocumentation` runs one regeneration at a time: on
the `background` and `deferred` queue drivers two dispatches run side by side in
the same worker, and the job takes its lock whatever the driver.

## Why extraction is sequential

Spreading the routes over coroutines with `Hypervel\Coroutine\parallel()` or
`Concurrent` looks like a free speed-up. Five things in the design rule it out:

- The console output and its warning buffer are shared, so concurrent routes
  would interleave both.
- `Extractor::getRouteBeingProcessed()`, which a `Scribe::resolveExampleModelUsing()`
  hook reads, is one static.
- `ResponseCalls` applies its `config` overrides with `Config::set()`, which is
  worker-global, so two routes with different overrides would clobber each
  other.
- Database connections are resolved per coroutine. A child coroutine either
  takes another pooled connection, which cannot see rows the parent's
  transaction created, or shares one PDO with coroutines running at the same
  time.
- `examples.faker_seed` only reproduces a run if the example models are built
  in route order: the model factories' Faker is seeded once per run and its
  sequence advances from one model to the next.

Response calls do run one child coroutine each, through
`Hypervel\Coroutine\Waiter`. That is for isolation and a timeout, not for
concurrency: the parent waits for each call before it moves on. See
[response calls](response-calls.md).
