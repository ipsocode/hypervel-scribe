# Queues and testing

## Running from a worker

`scribe:generate` can run in-process in a long-lived worker, from a queued job,
a scheduled task or a request:

```php
use Hypervel\Support\Facades\Artisan;

Artisan::call('scribe:generate');
```

The command clears the caches it keeps between endpoints when it starts and
again when it finishes, so it can run more than once in the same worker. It also
clears the URL origin it forces to `base_url` for the run. Hooks registered on
the `Scribe` class are kept. [Run state](design/run-state.md) lists what a run
caches, and [coroutine safety](design/coroutine-safety.md) explains why it
matters in a worker.

## The RegenerateDocumentation job

To regenerate on deploy or on a schedule, dispatch the job the package ships:

```php
use Ipsocode\Scribe\Jobs\RegenerateDocumentation;

RegenerateDocumentation::dispatch();
RegenerateDocumentation::dispatch(config: 'scribe_admin', force: true);
```

Its arguments are the command's options:

| Argument | Option | Default |
|---|---|---|
| `config` | `--config` | `'scribe'` |
| `force` | `--force` | `false` |
| `noExtraction` | `--no-extraction` | `false` |
| `scribeDir` | `--scribe-dir` | `null` |

Beyond running the command, the job does two things.

**It fails when the command does.** A non-zero exit code, which includes a run
that left out routes because their extraction threw, throws a
`RuntimeException` carrying the command's output, so the run shows up with the
other failed jobs. The job fails on the first exception, without retrying.

**Only one regeneration runs at a time.** The job takes a lock through
`WithoutOverlapping`, in the default cache store, whatever the config it was
given. With a shared store (Redis, database) the lock holds across workers. It
expires after 30 minutes in case a worker dies holding it. What happens to a
dispatch that finds the lock taken depends on the queue driver:

| Driver | A dispatch that finds the lock taken |
|---|---|
| A driver that stores jobs (`database`, `redis`, `sqs`, `beanstalkd`) | Goes back on the queue and tries again a minute later, for up to an hour. |
| `sync`, `background`, `deferred` | Is skipped: these run the job in the dispatching worker and have no queue to put it back on. |

The source is
[`src/Jobs/RegenerateDocumentation.php`](../src/Jobs/RegenerateDocumentation.php).

## Testing an application that uses the package

The package keeps its caches and the `Scribe::` hooks in worker-lifetime static
properties, which would otherwise carry from one test into the next.
[`Ipsocode\Scribe\Testing\TestState`](../src/Testing/TestState.php) resets them
after every test: the per-run caches, the hooks and the docblock tag handlers.

It is registered through `extra.hypervel.test-state` in the package's
`composer.json`, so an application's suite picks it up without any code,
provided Hypervel's PHPUnit extension is registered in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Hypervel\Testing\PHPUnit\AfterEachTestExtension"/>
</extensions>
```

Register hooks in the test that needs them, or in a service provider the test
application boots, since they are cleared after each test.
