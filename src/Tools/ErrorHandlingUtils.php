<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Exception;
use NunoMaduro\Collision\Handler;
use NunoMaduro\Collision\Writer;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Whoops\Exception\Inspector;

class ErrorHandlingUtils
{
    public static function dumpExceptionIfVerbose(Throwable $e): void
    {
        if (Globals::$shouldBeVerbose) {
            self::dumpException($e);

            return;
        }
        [$firstFrame, $secondFrame] = $e->getTrace();

        try {
            ['file' => $file, 'line' => $line] = $firstFrame;
        } catch (Exception $_) {
            ['file' => $file, 'line' => $line] = $secondFrame;
        }
        $exceptionType = get_class($e);
        $message = $e->getMessage();
        $message = "{$exceptionType} in {$file} at line {$line}: {$message}";
        ConsoleOutputUtils::error($message);
        ConsoleOutputUtils::error('Run this again with the --verbose flag to see the full stack trace.');
    }

    /**
     * Render a full stack trace.
     *
     * Collision and Whoops render this beautifully but are optional — they are
     * a dev-time convenience, and an application that documents its API in CI
     * may well not have them. Without the guard this method fatals on a missing
     * class, turning "show me the trace" into "the command died".
     */
    public static function dumpException(Throwable $e, ?OutputInterface $output = null): void
    {
        // Falls back to the command's own output, so a caller that redirected
        // it still sees the trace.
        $output ??= ConsoleOutputUtils::getOutput() ?? new ConsoleOutput(OutputInterface::VERBOSITY_VERBOSE);

        // @codeCoverageIgnoreStart
        // Whoops is a dev dependency of this package, so the suite always has
        // it; the guard is for the consuming applications that do not.
        if (! class_exists(Handler::class) || ! class_exists(Inspector::class)) {
            $output->writeln((string) $e);

            return;
        }
        // @codeCoverageIgnoreEnd

        $handler = new Handler(new Writer(null, $output));
        $handler->setInspector(new Inspector($e));
        $handler->setException($e);
        $handler->handle();
    }
}
