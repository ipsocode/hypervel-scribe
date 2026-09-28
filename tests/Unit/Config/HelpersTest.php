<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Config;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Extracting\Strategies\Responses\ResponseCalls;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseResponseTag;
use Ipsocode\Scribe\Extracting\Strategies\StaticData;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Test;

use function Ipsocode\Scribe\Config\configureStrategy;
use function Ipsocode\Scribe\Config\removeStrategies;

/**
 * These two functions are the public API of `config/scribe.php` — every
 * application's strategy list is built by calling them. A strategy list is a
 * mixed list of bare class names and [class, settings] tuples, and both helpers
 * have to cope with either form.
 */
class HelpersTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function removesAStrategyGivenAsABareClassName(): void
    {
        $result = removeStrategies([UseResponseTag::class, ResponseCalls::class], [ResponseCalls::class]);

        $this->assertSame([UseResponseTag::class], array_values($result));
    }

    #[UnitTest]
    #[Test]
    public function removesAStrategyThatWasAlreadyConfiguredAsATuple(): void
    {
        $result = removeStrategies(
            [UseResponseTag::class, [ResponseCalls::class, ['only' => ['GET *']]]],
            [ResponseCalls::class],
        );

        $this->assertSame([UseResponseTag::class], array_values($result));
    }

    #[UnitTest]
    #[Test]
    public function removingAStrategyThatIsNotThereIsANoOp(): void
    {
        $list = [UseResponseTag::class];

        $this->assertSame($list, removeStrategies($list, [ResponseCalls::class]));
    }

    #[UnitTest]
    #[Test]
    public function configuringAnExistingStrategyReplacesItInPlace(): void
    {
        $tuple = [ResponseCalls::class, ['only' => ['GET *']]];

        $result = configureStrategy([UseResponseTag::class, ResponseCalls::class], $tuple);

        // Position matters: strategies run in list order, so replacing must not
        // move a strategy to the end.
        $this->assertSame([UseResponseTag::class, $tuple], $result);
    }

    #[UnitTest]
    #[Test]
    public function configuringAnAbsentStrategyAppendsIt(): void
    {
        $tuple = [StaticData::class, ['data' => ['x' => 1]]];

        $result = configureStrategy([UseResponseTag::class], $tuple);

        $this->assertSame([UseResponseTag::class, $tuple], $result);
    }

    #[UnitTest]
    #[Test]
    public function reconfiguringAnAlreadyConfiguredStrategyOverwritesItsSettings(): void
    {
        $result = configureStrategy(
            [[ResponseCalls::class, ['only' => ['GET *']]]],
            [ResponseCalls::class, ['except' => ['POST *']]],
        );

        $this->assertSame([[ResponseCalls::class, ['except' => ['POST *']]]], $result);
    }
}
