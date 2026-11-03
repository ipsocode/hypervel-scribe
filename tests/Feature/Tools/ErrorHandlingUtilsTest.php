<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Tools;

use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * What a user sees when a strategy blows up mid-generation. Generation catches
 * per-endpoint failures and keeps going, so this message is often the only
 * evidence that anything went wrong.
 */
class ErrorHandlingUtilsTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($this->output);
    }

    #[Test]
    public function withoutVerboseItReportsTheMessageAndWhereItCameFrom(): void
    {
        $e = $this->throwFrom();

        ErrorHandlingUtils::dumpExceptionIfVerbose($e);

        $written = $this->output->fetch();

        $this->assertStringContainsString(RuntimeException::class, $written);
        $this->assertStringContainsString('something broke', $written);
        $this->assertStringContainsString(basename(__FILE__), $written);
        // The nudge matters: without it a user has no idea more detail exists.
        $this->assertStringContainsString('--verbose', $written);
    }

    #[Test]
    public function withoutVerboseItNamesTheLineTheExceptionWasThrownOn(): void
    {
        try {
            $thrownOn = __LINE__ + 1;
            throw new RuntimeException('something broke');
        } catch (RuntimeException $e) {
            ErrorHandlingUtils::dumpExceptionIfVerbose($e);
        }

        $this->assertStringContainsString(
            RuntimeException::class . ' in ' . __FILE__ . " at line {$thrownOn}: something broke",
            $this->output->fetch(),
        );
    }

    #[Test]
    public function withVerboseItDumpsTheWholeTrace(): void
    {
        Globals::$shouldBeVerbose = true;

        ErrorHandlingUtils::dumpExceptionIfVerbose($this->throwFrom());

        $this->assertStringContainsString('something broke', $this->output->fetch());
    }

    #[Test]
    public function theDumpGoesToTheOutputItIsHanded(): void
    {
        $other = new BufferedOutput;

        ErrorHandlingUtils::dumpException($this->throwFrom(), $other);

        $this->assertStringContainsString('something broke', $other->fetch());
        // ...and not to the one the command bootstrapped.
        $this->assertSame('', $this->output->fetch());
    }

    #[Test]
    public function theDumpFallsBackToTheCommandsOwnOutput(): void
    {
        ErrorHandlingUtils::dumpException($this->throwFrom());

        $this->assertStringContainsString('something broke', $this->output->fetch());
    }

    /**
     * Collision and Whoops are dev dependencies, so a production install
     * (including one running the regeneration job) has neither. The process is
     * separate because the autoloader has to stop finding them.
     */
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function withoutCollisionTheTraceIsPrintedAsPlainText(): void
    {
        $e = $this->throwFrom();
        $other = new BufferedOutput;

        // Everything the call and the assertions need is loaded first; only the
        // optional packages are left to the autoloader.
        class_exists(ErrorHandlingUtils::class);
        class_exists(ConsoleOutputUtils::class);
        class_exists(ConsoleOutput::class);
        $this->assertSame('', $other->fetch());
        $loaders = array_filter(spl_autoload_functions(), is_array(...));

        foreach ($loaders as $loader) {
            spl_autoload_unregister($loader);
        }

        try {
            ErrorHandlingUtils::dumpException($e, $other);
        } finally {
            foreach ($loaders as $loader) {
                spl_autoload_register($loader);
            }
        }

        $this->assertSame((string) $e . PHP_EOL, $other->fetch());
    }

    /**
     * Produce an exception with a real, multi-frame stack trace.
     */
    private function throwFrom(): RuntimeException
    {
        try {
            (fn () => throw new RuntimeException('something broke'))();
        } catch (RuntimeException $e) {
            return $e;
        }
    }
}
