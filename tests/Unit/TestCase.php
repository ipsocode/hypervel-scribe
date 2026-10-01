<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit;

use Hypervel\Testbench\TestCase as BaseTestCase;

/**
 * Base for tests of pure logic: the Camel DTOs, group sorting, parameter
 * helpers, annotation parser and writing/URL utilities.
 *
 * Every test method carries `#[UnitTest]`, so no application is booted. Code
 * that reaches for a facade or the container fails outright, which proves the
 * unit is free of the framework. The writers resolve generators from the
 * container and fall back to `config('app.*')`, so they are tested in the
 * Feature suite.
 */
abstract class TestCase extends BaseTestCase
{
}
