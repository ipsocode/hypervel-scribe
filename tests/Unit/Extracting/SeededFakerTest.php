<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Extracting;

use Faker\Factory;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Extracting\SeededFaker;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Example values come from one shared Faker generator rather than a fresh,
 * freshly seeded one per value. That is only safe while it hands out exactly
 * the values a fresh one would.
 */
class SeededFakerTest extends TestCase
{
    protected function tearDown(): void
    {
        SeededFaker::flushState();

        parent::tearDown();
    }

    #[Test]
    #[UnitTest]
    public function everyCallerSharesOneGenerator(): void
    {
        $this->assertSame(SeededFaker::get(1234), SeededFaker::get(1234));
    }

    #[Test]
    #[UnitTest]
    public function aSeededDrawMatchesAFreshlyCreatedAndSeededGenerator(): void
    {
        $fresh = Factory::create();
        $fresh->seed(1234);
        $expected = [$fresh->word(), $fresh->numberBetween(1, 1000), $fresh->uuid()];

        $shared = SeededFaker::get(1234);
        $this->assertSame($expected, [$shared->word(), $shared->numberBetween(1, 1000), $shared->uuid()]);
    }

    #[Test]
    #[UnitTest]
    public function eachSeededHandOutRestartsTheSequence(): void
    {
        $first = SeededFaker::get(1234)->word();
        SeededFaker::get(1234)->word();

        $this->assertSame($first, SeededFaker::get(1234)->word());
    }

    #[Test]
    #[UnitTest]
    public function aFalsySeedLeavesTheSequenceWhereItIs(): void
    {
        // `examples.faker_seed` of null means unseeded: the hand-out must not
        // reset the sequence, or every "random" example would be the same one.
        $fresh = Factory::create();
        $fresh->seed(1234);
        $expected = [$fresh->word(), $fresh->word()];

        SeededFaker::get(1234);

        $this->assertSame($expected, [SeededFaker::get(null)->word(), SeededFaker::get(0)->word()]);
    }

    #[Test]
    #[UnitTest]
    public function flushingDropsTheGenerator(): void
    {
        $before = SeededFaker::get(1234);

        SeededFaker::flushState();

        $this->assertNotSame($before, SeededFaker::get(1234));
    }
}
