<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Everything Scribe tells the user goes through here, from anywhere in the
 * extraction — strategies deep in the call stack have no command to write to.
 * So the class carries the running command as static state and falls back to a
 * console of its own when there is none.
 *
 * The command-backed half is exercised by the `scribe:generate` tests, which
 * have a real command; what is left for here is the standalone half, plus the
 * warning buffer that keeps a strategy's warnings from tearing through a task's
 * progress line.
 */
class ConsoleOutputUtilsTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        // Static state, and the after-each cleanup only runs for tests that
        // booted an application — which unit tests deliberately do not.
        ConsoleOutputUtils::flushState();
        Globals::$shouldBeVerbose = false;

        ConsoleOutputUtils::bootstrapOutput($this->output = new BufferedOutput);
    }

    protected function tearDown(): void
    {
        ConsoleOutputUtils::flushState();
        Globals::$shouldBeVerbose = false;

        parent::tearDown();
    }

    #[UnitTest]
    #[Test]
    public function writesEachLevelThroughTheBootstrappedOutput(): void
    {
        ConsoleOutputUtils::info('Extracting.');
        ConsoleOutputUtils::success('Wrote the docs.');
        ConsoleOutputUtils::warn('Skipped a route.');
        ConsoleOutputUtils::error('Could not write.');

        $written = $this->output->fetch();

        $this->assertStringContainsString('Extracting.', $written);
        $this->assertStringContainsString('Wrote the docs.', $written);
        $this->assertStringContainsString('Skipped a route.', $written);
        $this->assertStringContainsString('Could not write.', $written);
    }

    #[UnitTest]
    #[Test]
    public function debugOutputIsSilentUnlessTheRunIsVerbose(): void
    {
        ConsoleOutputUtils::debug('Considered a strategy.');
        $this->assertSame('', $this->output->fetch());

        Globals::$shouldBeVerbose = true;
        ConsoleOutputUtils::debug('Considered a strategy.');
        $this->assertStringContainsString('Considered a strategy.', $this->output->fetch());
    }

    #[UnitTest]
    #[Test]
    public function bufferedWarningsAreHeldBackUntilTheTaskFinishes(): void
    {
        // A task prints its own progress line; a warning arriving mid-task
        // would break it in half.
        ConsoleOutputUtils::startWarningBuffer();
        ConsoleOutputUtils::warn('Skipped a route.');

        $this->assertSame('', $this->output->fetch());

        ConsoleOutputUtils::flushWarningBuffer();

        $this->assertStringContainsString('Skipped a route.', $this->output->fetch());
    }

    #[UnitTest]
    #[Test]
    public function aTaskWithNoCommandStillReportsItselfAndReturnsItsResult(): void
    {
        $result = ConsoleOutputUtils::task('Doing the thing', fn () => 'done');

        $this->assertSame('done', $result);
        $this->assertStringContainsString('Doing the thing', $this->output->fetch());
    }

    #[UnitTest]
    #[Test]
    public function withNoOutputBootstrappedItOpensAConsoleOfItsOwn(): void
    {
        // Scribe is usable as a library, not just behind `scribe:generate`, so
        // every level has to work with nothing bootstrapped at all. The writes
        // below land on the real STDOUT — that is the branch under test.
        Globals::$shouldBeVerbose = true;

        foreach (['info', 'success', 'warn', 'error', 'debug'] as $level) {
            ConsoleOutputUtils::flushState();
            ConsoleOutputUtils::{$level}('');

            $this->assertInstanceOf(ConsoleOutput::class, ConsoleOutputUtils::getOutput());
        }
    }
}
