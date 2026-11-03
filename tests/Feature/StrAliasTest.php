<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use Ipsocode\Scribe\ScribeServiceProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * The Blade views call the short `Str` alias, which an application can drop
 * from its `aliases` config. The alias is global to the PHP process, so the
 * test runs in its own.
 */
class StrAliasTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScribeServiceProvider::class];
    }

    protected function overrideApplicationAliases($app): array
    {
        return ['Str' => false];
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function itAliasesStrWhenTheApplicationDroppedIt(): void
    {
        $this->assertTrue(class_exists('Str'));
        $this->assertSame(Str::class, (new ReflectionClass('Str'))->getName());
    }
}
