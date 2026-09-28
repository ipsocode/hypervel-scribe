<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Tools;

use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

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
