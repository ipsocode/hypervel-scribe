# Run state

[`Tools\RunState`](../../src/Tools/RunState.php) is the state one
`scribe:generate` run builds up and nothing after it should see. Its one method,
`RunState::flush()`, resets all of it. Why that matters under Swoole is in
[coroutine safety](coroutine-safety.md).

## What a run caches

Class names are relative to `Ipsocode\Scribe`, except `BaseDTO`.

| Class | What it keeps |
|---|---|
| `Tools\ConsoleOutputUtils` | The console output and command the run reports to, and the warning buffer |
| `Tools\Globals::$shouldBeVerbose` | The command's `--verbose` flag |
| `Extracting\Extractor` | The route being processed |
| `Extracting\MethodAstParser` | Parsed ASTs, per source file and per method |
| `Extracting\RouteDocBlocker` | Parsed docblocks, per route, per class and per method |
| `Extracting\Shared\UrlParamsNormalizer` | A controller method's type-hinted models |
| `Extracting\Strategies\UrlParameters\GetFromLaravelAPI` | Each model's example route key, taken from its first row (null for an empty table) |
| `Extracting\SeededFaker` | The one Faker generator example values are drawn from |
| `Ipsocode\Camel\BaseDTO` | Reflected property metadata, per DTO class |
| `Extracting\Strategies\Responses\UseResponseAttributes` | Example models for single API resources, reused across a path's operations so the `{id}` in the path matches the id in every response body |
| `UseResponseAttributes`, `UseApiResourceTags`, `UseTransformerTags` | Whether the model factories' Faker has been seeded this run (from the `Extracting\InstantiatesExampleModels` trait) |
| `Extracting\Strategies\GetFromFormRequestBase`, `GetFromInlineValidatorBase` | The `$MISSING_VALUE` sentinel (from the `Extracting\ParsesValidationRules` trait) |

A trait's static property is per class, not shared: each class that uses
`InstantiatesExampleModels` or `ParsesValidationRules` has its own copy, so each
one has its own line in `RunState::flush()`.

## Flushed on the way in and on the way out

[`GenerateDocumentation::handle()`](../../src/Commands/GenerateDocumentation.php)
calls `RunState::flush()` before it does anything else, and again in a
`finally` block:

```php
RunState::flush();

try {
    // bootstrap, extract, write
} finally {
    URL::useOrigin(null);
    RunState::flush();
}
```

A command running in its own CLI process takes its statics with it when it
exits. One running in-process in a worker does not:

- **On the way in**, the run starts from a clean slate rather than from what an
  earlier run in the same worker cached. Otherwise it would read the ASTs and
  docblocks of controllers that have changed since.
- **On the way out**, the run leaves nothing behind for the rest of the worker's
  life: no example models (which hold Eloquent models, and connections through
  them), no console binding for a command that has finished.

The `finally` also clears the URL origin the command forces to `base_url` (or
`app.url`) while it runs. That origin lives in the coroutine's context, but the
command may run inside a caller's coroutine rather than a fresh one, so it is
cleared before control goes back to the caller.

## What it leaves alone

`RunState::flush()` does not touch:

- the hooks an application registers through the `Scribe` class
  (`Scribe::beforeResponseCall()`, `Scribe::afterGenerating()` and the rest),
  stored in `Tools\Globals`;
- the docblock tag handlers registered with
  `Ipsocode\Scribe\Reflection\DocBlock\Tag::registerTagHandler()`.

Those are configuration. The application sets them once when it boots and
expects them to be there for every run, so a run must not erase them.
`Globals::$shouldBeVerbose` sits on the same class but belongs to one run, so
`RunState::flush()` resets that one property directly.

[`RunStateTest`](../../tests/Feature/Tools/RunStateTest.php) pins both sides:
every cache is dropped, and a hook and a tag handler survive.

## Testing\TestState

[`Testing\TestState`](../../src/Testing/TestState.php) is the package's
test-state registrar. Its `flushState()` resets everything:

```php
RunState::flush();
Globals::flushState();
Tag::flushState();
```

A test has to shed what an application sets at boot as well as what a run
caches, or one test's hooks and tag handlers reach the next.

It is declared in `composer.json` under `extra.hypervel.test-state`, and
Hypervel's `AfterEachTestExtension` discovers it and runs it after every test.
That covers the package's own suite and a consuming application's suite alike,
provided `phpunit.xml` registers the extension:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

Without that entry the registrar is declared and never runs, and tests pass on
ordering luck rather than on cleanup.

## Adding state

- A per-run cache gets a `flushState()`, called from `RunState::flush()`.
- Configuration an application sets at boot gets a `flushState()`, called from
  `TestState::flushState()` directly.
- A new class that uses `InstantiatesExampleModels` or `ParsesValidationRules`
  needs its own line in `RunState::flush()`.
