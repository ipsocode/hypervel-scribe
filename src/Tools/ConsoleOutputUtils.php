<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tools;

use Hypervel\Routing\Route;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleOutputUtils
{
    private static ?OutputInterface $output = null;

    private static ?GenerateDocumentation $command = null;

    private static array $warningBuffer = [];

    private static bool $isBufferingWarnings = false;

    /**
     * The output the running command bootstrapped, if any.
     *
     * Lets other tools write through the command's own output rather than
     * opening a second handle on STDOUT, which a test, or a caller that
     * redirected the command's output, would never see.
     */
    public static function getOutput(): ?OutputInterface
    {
        return self::$output;
    }

    public static function bootstrapOutput(OutputInterface $outputInterface): void
    {
        self::$output = $outputInterface;
    }

    public static function setCommand(?GenerateDocumentation $command): void
    {
        self::$command = $command;
    }

    public static function startWarningBuffer(): void
    {
        self::$isBufferingWarnings = true;
        self::$warningBuffer = [];
    }

    public static function flushWarningBuffer(): void
    {
        self::$isBufferingWarnings = false;
        foreach (self::$warningBuffer as $warning) {
            self::warn($warning);
        }
        self::$warningBuffer = [];
    }

    /**
     * Run a task with Hypervel-style output (dots, timing, status).
     */
    public static function task(string $description, callable $task): mixed
    {
        if (self::$command) {
            // The framework's task() doesn't return the callback's result, so it is captured here.
            $result = null;
            self::$command->outputComponents()->task($description, function () use (&$result, $task) {
                $result = $task();
            });

            return $result;
        }

        // No command bound: plain output.
        self::info($description);
        $result = $task();
        self::success($description);

        return $result;
    }

    public static function warn(string $message): void
    {
        if (self::$isBufferingWarnings) {
            self::$warningBuffer[] = $message;

            return;
        }

        if (self::$command) {
            self::$command->outputComponents()->warn($message);

            return;
        }

        if (! self::$output) {
            self::bootstrapOutput(new ConsoleOutput);
        }
        self::$output->writeln("<fg=yellow>  ⚠ {$message}</>");
    }

    public static function info(string $message): void
    {
        if (self::$command) {
            self::$command->outputComponents()->info($message);

            return;
        }

        if (! self::$output) {
            self::bootstrapOutput(new ConsoleOutput);
        }
        self::$output->writeln("<fg=gray>  ℹ {$message}</>");
    }

    public static function debug(string $message): void
    {
        if (! Globals::$shouldBeVerbose) {
            return;
        }

        if (! self::$output) {
            self::bootstrapOutput(new ConsoleOutput);
        }
        self::$output->writeln("<fg=gray>  🐛 {$message}</>");
    }

    public static function success(string $message): void
    {
        if (! self::$output) {
            self::bootstrapOutput(new ConsoleOutput);
        }
        self::$output->writeln("<fg=green>  ✔ {$message}</>");
    }

    public static function error(string $message): void
    {
        if (self::$command) {
            self::$command->outputComponents()->error($message);

            return;
        }

        if (! self::$output) {
            self::bootstrapOutput(new ConsoleOutput);
        }
        self::$output->writeln("<fg=red>  ✖ {$message}</>");
    }

    /**
     * A route as the console shows it, e.g. [GET] /api/users.
     */
    public static function getRouteRepresentation(Route $route): string
    {
        $methods = $route->methods();
        if (count($methods) > 1) {
            $methods = array_diff($route->methods(), ['HEAD']);
        }

        $routeMethods = implode('|', $methods);
        $routePath = $route->uri();

        return "[<fg=cyan>{$routeMethods}</>] {$routePath}";
    }

    public static function flushState(): void
    {
        self::$output = null;
        self::$command = null;
        self::$warningBuffer = [];
        self::$isBufferingWarnings = false;
    }
}
