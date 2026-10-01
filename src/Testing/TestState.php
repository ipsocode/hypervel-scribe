<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Testing;

use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\RunState;

/**
 * Resets the package's worker-lifetime static state after every test.
 *
 * Declared via `extra.hypervel.test-state` in composer.json, so consuming
 * applications' suites pick it up too, including workers that only run unit
 * tests and never boot an application. See docs/queue-and-testing.md.
 */
class TestState
{
    public static function register(): void
    {
        AfterEachTestCleanup::flushUsing('ipsocode/hypervel-scribe', fn () => static::flushState());
    }

    /**
     * Resets the per-run caches ({@see RunState}) plus the configuration a run
     * leaves alone: the hooks registered through the `Scribe` class and the
     * docblock tag handlers.
     */
    public static function flushState(): void
    {
        RunState::flush();
        Globals::flushState();
        Tag::flushState();
    }
}
