<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Commands;

use Hypervel\Support\Facades\File;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * `scribe:strategy`, the custom-strategy scaffolder. The stub names three of the
 * package's classes, and a strategy that breaks the `Strategy` contract only
 * fails at extraction time in someone else's application, so one test compiles
 * the generated file and runs it.
 */
class MakeStrategyTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(app_path('Docs'));

        parent::tearDown();
    }

    private function strategyPath(string $name): string
    {
        return app_path("Docs/Strategies/{$name}.php");
    }

    #[Test]
    public function writesTheStrategyUnderTheApplicationsDocsNamespace(): void
    {
        $this->artisan('scribe:strategy', ['name' => 'AddCustomHeader'])->assertSuccessful();

        $this->assertFileExists($this->strategyPath('AddCustomHeader'));

        $contents = File::get($this->strategyPath('AddCustomHeader'));

        // Documentation tooling rather than application code, so it lands in a
        // namespace of its own rather than beside the controllers it reads.
        $this->assertStringContainsString('namespace App\Docs\Strategies;', $contents);
        $this->assertStringContainsString('class AddCustomHeader extends Strategy', $contents);
    }

    #[Test]
    public function theGeneratedStrategyReferencesThisPackagesClasses(): void
    {
        $this->artisan('scribe:strategy', ['name' => 'ReferencesPackage'])->assertSuccessful();

        $contents = File::get($this->strategyPath('ReferencesPackage'));

        $this->assertStringContainsString('use ' . Strategy::class . ';', $contents);
        $this->assertStringContainsString('use ' . ExtractedEndpointData::class . ';', $contents);
        $this->assertStringContainsString('use Ipsocode\Scribe\Extracting\ParamHelpers;', $contents);
    }

    #[Test]
    public function theGeneratedStrategyIsAWorkingStrategy(): void
    {
        $this->artisan('scribe:strategy', ['name' => 'GeneratedRunnable'])->assertSuccessful();

        require_once $this->strategyPath('GeneratedRunnable');

        $class = 'App\Docs\Strategies\GeneratedRunnable';

        $this->assertTrue(class_exists($class));
        $this->assertTrue(is_subclass_of($class, Strategy::class));
        // Concrete, so `strategies` in config/scribe.php can name it directly.
        $this->assertFalse((new ReflectionClass($class))->isAbstract());

        $strategy = new $class(new DocumentationConfig);

        $this->assertSame([], $strategy(ExtractedEndpointData::fromRoute($this->workbenchRoute('users.index'))));
    }

    #[Test]
    public function refusesToOverwriteAnExistingStrategy(): void
    {
        $this->artisan('scribe:strategy', ['name' => 'AlreadyThere'])->assertSuccessful();
        File::put($this->strategyPath('AlreadyThere'), '<?php // hand-edited');

        // `GeneratorCommand` bails by returning `false`, which is not an int,
        // so the command still exits 0, the same as `make:controller`.
        $this->artisan('scribe:strategy', ['name' => 'AlreadyThere'])
            ->expectsOutputToContain('Strategy already exists.')
            ->assertSuccessful();

        $this->assertSame('<?php // hand-edited', File::get($this->strategyPath('AlreadyThere')));
    }

    #[Test]
    public function overwritesAnExistingStrategyWhenForced(): void
    {
        $this->artisan('scribe:strategy', ['name' => 'Clobbered'])->assertSuccessful();
        File::put($this->strategyPath('Clobbered'), '<?php // hand-edited');

        $this->artisan('scribe:strategy', ['name' => 'Clobbered', '--force' => true])->assertSuccessful();

        $this->assertStringContainsString('class Clobbered extends Strategy', File::get($this->strategyPath('Clobbered')));
    }
}
