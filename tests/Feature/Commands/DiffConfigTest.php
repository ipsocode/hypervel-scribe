<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Commands;

use Hypervel\Testbench\Attributes\WithConfig;
use InvalidArgumentException;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `scribe:config-diff` dumps only the changed options, for a bug report. The
 * Workbench application ships no `config/scribe.php`, so the unmodified case
 * also checks the provider's merge: any drift shows up as a spurious diff.
 */
class DiffConfigTest extends TestCase
{
    #[Test]
    public function reportsNoDifferenceWhenTheApplicationHasNotChangedTheConfig(): void
    {
        $this->artisan('scribe:config-diff')
            ->expectsOutputToContain('------ SAME AS DEFAULT CONFIG ------')
            ->assertSuccessful();
    }

    #[Test]
    #[WithConfig('scribe.theme', 'elements')]
    #[WithConfig('scribe.auth.enabled', true)]
    public function reportsChangedValuesByTheirDottedPath(): void
    {
        $this->artisan('scribe:config-diff')
            ->expectsOutput('theme => "elements"')
            ->expectsOutput('auth.enabled => true')
            ->doesntExpectOutput('------ SAME AS DEFAULT CONFIG ------')
            ->assertSuccessful();
    }

    #[Test]
    #[WithConfig('scribe.description', 'A description of our API.')]
    #[WithConfig('scribe.auth.extra_info', 'Ask an administrator for a token.')]
    #[WithConfig('scribe.example_languages', ['php'])]
    public function staysQuietAboutTheProseAndPerApplicationLists(): void
    {
        // These differ in every real application, so reporting them would bury
        // whatever is actually misconfigured.
        $this->artisan('scribe:config-diff')
            ->expectsOutputToContain('------ SAME AS DEFAULT CONFIG ------')
            ->assertSuccessful();
    }

    #[Test]
    #[WithConfig('scribe.examples.models_source', ['factoryMake', 'databaseFirst'])]
    public function reportsAListByWhatJoinedAndLeftIt(): void
    {
        $this->artisan('scribe:config-diff')
            ->expectsOutput('examples.models_source => removed factoryCreate')
            ->assertSuccessful();
    }

    #[Test]
    #[WithConfig('scribe.strategies.responses', [])]
    public function reportsAnEmptiedStrategyStageAsRemovals(): void
    {
        // `strategies.*` are lists of class-strings and configured-strategy
        // arrays; the latter is why the comparison exports before subtracting.
        $this->artisan('scribe:config-diff')
            ->expectsOutputToContain('strategies.responses => removed ')
            ->assertSuccessful();
    }

    #[Test]
    public function refusesAConfigFileThatDoesNotExist(): void
    {
        // Same guard, and the same message, as `scribe:generate --config`.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The specified config (config/nope.php) doesn't exist.");

        $this->artisan('scribe:config-diff', ['--config' => 'nope'])->run();
    }
}
