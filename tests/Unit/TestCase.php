<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit;

use Hypervel\Testbench\TestCase as BaseTestCase;

/**
 * Base for tests of the package's pure logic — the Camel DTOs and group sorting,
 * the parameter helpers, the annotation parser, and the writing/URL utilities.
 * None of it touches the container, a database or a request.
 *
 * Every test method here carries `#[UnitTest]`, which tells the framework to skip
 * building and booting a Testbench application for that method. That is not just
 * a speed trick: it is what proves these units really are free of the framework,
 * because anything that reaches for a facade or the container fails outright
 * instead of quietly working off an application the test never needed.
 *
 * Scribe's writers are deliberately not here. They resolve their generators out
 * of the container and fall back to `config('app.*')`, so they belong in the
 * Feature suite where that is real rather than mocked.
 */
abstract class TestCase extends BaseTestCase
{
}
