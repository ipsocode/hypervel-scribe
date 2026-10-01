<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * The `Scribe` class is the whole public API a consuming application writes
 * against — every hook is a static setter onto one slot of `Tools\Globals`.
 *
 * Nothing here fails loudly if a setter writes to the wrong slot: the hook would
 * simply never fire, and generation would carry on producing subtly different
 * docs. Pairing each method with the global it owns is what catches that.
 */
class ScribeTest extends TestCase
{
    protected function tearDown(): void
    {
        // These are worker-lifetime statics, and a `#[UnitTest]` method runs
        // without the application whose teardown would otherwise flush them.
        Globals::flushState();

        parent::tearDown();
    }

    #[UnitTest]
    #[Test]
    #[DataProvider('hooks')]
    public function eachHookSetterWritesToItsOwnGlobal(string $method, string $global): void
    {
        $callable = fn () => 'called';

        Scribe::{$method}($callable);

        $this->assertSame($callable, (new ReflectionClass(Globals::class))->getStaticPropertyValue($global));
    }

    /**
     * Every hook on the public API, paired with the global it sets.
     *
     * @return array<string, array{string, string}>
     */
    public static function hooks(): array
    {
        return [
            'beforeResponseCall' => ['beforeResponseCall', '__beforeResponseCall'],
            'afterResponseCall' => ['afterResponseCall', '__afterResponseCall'],
            'bootstrap' => ['bootstrap', '__bootstrap'],
            'afterGenerating' => ['afterGenerating', '__afterGenerating'],
            'instantiateFormRequestUsing' => ['instantiateFormRequestUsing', '__instantiateFormRequestUsing'],
            'normalizeEndpointUrlUsing' => ['normalizeEndpointUrlUsing', '__normalizeEndpointUrlUsing'],
            'afterExtracting' => ['afterExtracting', '__afterExtracting'],
            'resolveExampleModelUsing' => ['resolveExampleModelUsing', '__resolveExampleModelUsing'],
        ];
    }

    #[UnitTest]
    #[Test]
    public function theNullableHooksCanBeClearedAgain(): void
    {
        // The three hooks that take `?callable` are the ones a test suite or a
        // conditional bootstrap needs to be able to switch back off.
        Scribe::instantiateFormRequestUsing(fn () => null);
        Scribe::normalizeEndpointUrlUsing(fn () => '');
        Scribe::resolveExampleModelUsing(fn () => null);

        Scribe::instantiateFormRequestUsing(null);
        Scribe::normalizeEndpointUrlUsing(null);
        Scribe::resolveExampleModelUsing(null);

        $this->assertNull(Globals::$__instantiateFormRequestUsing);
        $this->assertNull(Globals::$__normalizeEndpointUrlUsing);
        $this->assertNull(Globals::$__resolveExampleModelUsing);
    }
}
