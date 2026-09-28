<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Commands;

use Hypervel\Testbench\Attributes\WithConfig;
use InvalidArgumentException;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `scribe:config-diff` — what a consumer pastes into a bug report instead of
 * 270 lines of mostly-untouched defaults.
 *
 * The Workbench application deliberately ships no `config/scribe.php` of its
 * own, so the unmodified case here is also a check on the provider's merge:
 * anything that made the merged config drift from the package defaults would
 * show up as a spurious diff.
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
