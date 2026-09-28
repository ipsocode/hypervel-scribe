<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Jobs;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\Middleware\WithoutOverlapping;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Jobs\RegenerateDocumentation;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The generate command run from a queue worker rather than a terminal: the
 * shape an application takes when it regenerates its docs on deploy or on a
 * schedule, inside a worker that outlives the run.
 *
 * Every run here keeps its intermediate and static output in a directory of
 * this process's own. By default both land beside the package, which every
 * ParaTest worker shares, and GenerateDocumentationTest writes there from
 * another worker at the same time.
 */
#[WithConfig('cache.default', 'array')]
class RegenerateDocumentationTest extends DatabaseTestCase
{
    private string $workDir;

    private string $scribeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/scribe-job-test-' . getmypid();
        $this->scribeDir = $this->workDir . '/.scribe';
        config(['scribe.static.output_path' => $this->workDir . '/public/docs']);
        $this->cleanUpGeneratedFiles();
    }

    protected function tearDown(): void
    {
        $this->cleanUpGeneratedFiles();

        parent::tearDown();
    }

    private function cleanUpGeneratedFiles(): void
    {
        File::deleteDirectory($this->workDir);
        File::deleteDirectory($this->app->viewPath('scribe'));
        File::deleteDirectory($this->app->publicPath('vendor/scribe'));
        Storage::disk('local')->deleteDirectory('scribe');
    }

    private function docsWereWritten(): bool
    {
        return Storage::disk('local')->exists('scribe/openapi.yaml');
    }

    #[Test]
    public function itIsAQueuedJob(): void
    {
        Queue::fake();

        RegenerateDocumentation::dispatch(force: true);

        Queue::assertPushed(
            RegenerateDocumentation::class,
            fn (RegenerateDocumentation $job) => $job->force && $job->config === 'scribe',
        );
        $this->assertInstanceOf(ShouldQueue::class, new RegenerateDocumentation);
        $this->assertFalse($this->docsWereWritten());
    }

    #[Test]
    public function aRunWritesTheDocs(): void
    {
        RegenerateDocumentation::dispatchSync(scribeDir: $this->scribeDir);

        $this->assertTrue($this->docsWereWritten());
        $this->assertFileExists($this->scribeDir . '/endpoints/00.yaml');
    }

    #[Test]
    public function theOptionsReachTheCommand(): void
    {
        RegenerateDocumentation::dispatchSync(scribeDir: $this->scribeDir);
        $spec = Storage::disk('local')->get('scribe/openapi.yaml');

        // Rebuilt from the YAML alone: a group whose file is gone is gone
        // from the output too.
        File::delete($this->scribeDir . '/endpoints/00.yaml');
        Storage::disk('local')->deleteDirectory('scribe');
        RegenerateDocumentation::dispatchSync(noExtraction: true, scribeDir: $this->scribeDir);

        $this->assertTrue($this->docsWereWritten());
        $this->assertFileDoesNotExist($this->scribeDir . '/endpoints/00.yaml');
        $this->assertNotSame($spec, Storage::disk('local')->get('scribe/openapi.yaml'));
    }

    #[Test]
    #[WithConfig('scribe.default_group', 'Endpoints')]
    public function aRunTheCommandRefusesFailsTheJobWithTheCommandsOutput(): void
    {
        // A config from before v4 stops the command with a non-zero exit code
        // and no exception, which Artisan::queue() would count as a success.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exited with code 1\.\n.*upgrade/s');

        RegenerateDocumentation::dispatchSync();
    }

    #[Test]
    public function aRunIsHeldBackWhileAnotherHoldsTheLock(): void
    {
        $job = new RegenerateDocumentation(config: 'another-config');
        [$overlap] = $job->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $overlap);

        // One lock whatever the config: the state it protects is shared by all.
        $lock = Cache::lock($overlap->getLockKey(new RegenerateDocumentation), 60);
        $this->assertTrue($lock->get());

        try {
            RegenerateDocumentation::dispatchSync(scribeDir: $this->scribeDir);

            $this->assertFalse($this->docsWereWritten());
        } finally {
            $lock->release();
        }

        RegenerateDocumentation::dispatchSync(scribeDir: $this->scribeDir);
        $this->assertTrue($this->docsWereWritten());
    }

    #[Test]
    public function aHeldBackRunKeepsTryingForAWhileButFailsOnItsFirstException(): void
    {
        $job = new RegenerateDocumentation;
        [$overlap] = $job->middleware();

        $this->assertSame(60, $overlap->releaseAfter);
        $this->assertSame(30 * 60, $overlap->expiresAfter);
        $this->assertSame(1, $job->maxExceptions);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $job->retryUntil()->getTimestamp(), 5);
    }
}
