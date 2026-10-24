<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Jobs;

use DateTimeInterface;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Queue\Queueable;
use Hypervel\Queue\Middleware\WithoutOverlapping;
use Hypervel\Support\Carbon;
use RuntimeException;

/**
 * Run `scribe:generate` from a queue worker.
 *
 * Unlike `Artisan::queue('scribe:generate')`, it fails when the command does,
 * and runs one regeneration at a time whichever config each was given: two
 * runs in one Swoole worker would reset each other's worker-lifetime state,
 * which every config shares, half way through. See src/docs/queue-and-testing.md.
 */
class RegenerateDocumentation implements ShouldQueue
{
    use Queueable;

    /**
     * Fail on the first exception the command throws. Being released because
     * another run holds the lock is not an exception, and does not count.
     */
    public int $maxExceptions = 1;

    /**
     * Seconds the lock is held, in case a worker dies holding it.
     */
    public const LOCK_EXPIRES_AFTER = 30 * 60;

    /**
     * A full extraction can take minutes and one response call alone up to a
     * minute, so the worker's default `--timeout` of 60 seconds would kill the
     * worker mid-run and leave the lock held. Never longer than the lock.
     */
    public int $timeout = self::LOCK_EXPIRES_AFTER;

    public function __construct(
        public string $config = 'scribe',
        public bool $force = false,
        public bool $noExtraction = false,
        public ?string $scribeDir = null,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // On a queue that stores jobs, a run that finds the lock taken goes
        // back on the queue and tries again a minute later. The `sync`,
        // `background` and `deferred` drivers have nowhere to put it back, so
        // there it is dropped. The lock expires on its own in case a worker
        // dies holding it.
        return [
            (new WithoutOverlapping)->releaseAfter(60)->expireAfter(self::LOCK_EXPIRES_AFTER),
        ];
    }

    /**
     * How long a run may keep being released while another one holds the lock.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addHour();
    }

    public function handle(Kernel $kernel): void
    {
        $parameters = array_filter([
            '--config' => $this->config,
            '--force' => $this->force,
            '--no-extraction' => $this->noExtraction,
            '--scribe-dir' => $this->scribeDir,
        ]);

        $exitCode = $kernel->call('scribe:generate', $parameters);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "scribe:generate exited with code {$exitCode}.\n" . mb_trim($kernel->output())
            );
        }
    }
}
