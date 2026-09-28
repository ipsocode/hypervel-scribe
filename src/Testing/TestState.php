<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\RunState;

/**
 * Package-level test-state registrar.
 *
 * Declared via `extra.hypervel.test-state` in composer.json and discovered
 * during PHPUnit extension bootstrap, so this package's worker-lifetime static
 * state is reset in consuming applications too — including in workers that only
 * run unit tests and never boot a Hypervel application.
 */
class TestState
{
    /**
     * Register the after-each cleanup callback for this package.
     */
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('ipsocode/hypervel-scribe', fn () => static::flushState());
    }

    /**
     * Reset all worker-lifetime static state owned by this package.
     *
     * The per-run caches are {@see RunState}'s, which the generate command
     * also flushes around every run. On top of those, a test has to shed what
     * an application sets once at boot and a run leaves alone: the hooks
     * registered through the `Scribe` class, and the docblock tag handlers.
     */
    public static function flushState(): void
    {
        RunState::flush();
        Globals::flushState();
        Tag::flushState();
    }
}
