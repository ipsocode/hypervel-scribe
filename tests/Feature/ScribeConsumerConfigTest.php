<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Testbench\Attributes\ResolvesHypervel;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a *partial* application `config/scribe.php` does to the package's
 * defaults. `#[ResolvesHypervel]` swaps the config path before configuration
 * loads; `#[WithConfig]` sets values afterwards, so it would bypass the merge.
 * See docs/design/config-merging.md.
 */
#[ResolvesHypervel('useConsumerConfig')]
class ScribeConsumerConfigTest extends TestCase
{
    protected function useConsumerConfig(Application $app): void
    {
        $app->useConfigPath(__DIR__ . '/Fixtures/config');
    }

    #[Test]
    public function theApplicationsOwnValuesWin(): void
    {
        $this->assertSame('Consumer API', config('scribe.title'));
        $this->assertTrue(config('scribe.auth.enabled'));
    }

    #[Test]
    public function topLevelKeysTheApplicationDidNotNameKeepTheirDefaults(): void
    {
        // The shallow `array_merge` handles this much on its own.
        $this->assertSame('hypervel', config('scribe.type'));
        $this->assertSame('default', config('scribe.theme'));
        $this->assertTrue(config('scribe.openapi.enabled'));
    }

    #[Test]
    public function siblingsInsideAPartiallyOverriddenOptionArraySurvive(): void
    {
        // The reason `mergeableOptions()` is overridden at all. Without it the
        // application's one-key `auth` block replaces the package's whole one,
        // and these come back null.
        //
        // A null `auth.in` is not inert: `Writing\HtmlWriter::getMetadata()`
        // branches on `$auth['in'] === 'bearer'`, and the extractor's auth
        // descriptions read the same key — so it produces silently wrong
        // documentation rather than an error.
        $this->assertSame('bearer', config('scribe.auth.in'));
        $this->assertSame('key', config('scribe.auth.name'));
        $this->assertSame('{YOUR_AUTH_KEY}', config('scribe.auth.placeholder'));
        $this->assertNotNull(config('scribe.auth.extra_info'));
    }

    #[Test]
    public function everyNestedOptionArrayIsCoveredByTheMerge(): void
    {
        // The option arrays are named one by one in `mergeableOptions()`, so a
        // config file that grows a new one silently misses out. This is the
        // check that notices.
        $packageDefaults = require dirname(__DIR__, 2) . '/config/scribe.php';

        $nestedOptionArrays = array_keys(array_filter(
            $packageDefaults,
            // String keys mean an option array. `routes`,
            // `database_connections_to_transact` and `example_languages` are
            // positional lists, and drop out here.
            fn ($value) => is_array($value) && $value !== [] && ! array_is_list($value),
        ));

        $mergeable = $this->mergeableOptions();

        // `strategies` is the deliberate exception — see the test below.
        $expected = array_values(array_diff($nestedOptionArrays, ['strategies']));

        sort($expected);
        sort($mergeable);

        $this->assertSame($expected, $mergeable);
    }

    #[Test]
    public function aListIsReplacedWholesaleRatherThanSplicedByIndex(): void
    {
        // `routes` entries are positional, so merging them by key would graft
        // the application's first entry over the package's first entry and
        // leave the rest of the package's behind. Replacing the list is the
        // only reading that preserves what the application wrote.
        $routes = config('scribe.routes');

        $this->assertCount(1, $routes);
        $this->assertSame(['v2/*'], $routes[0]['match']['prefixes']);
    }

    #[Test]
    public function anApplicationThatTouchesStrategiesTakesOverAllOfThem(): void
    {
        // `strategies` is left out of `mergeableOptions()` by choice, so an
        // application that names even one stage replaces the whole block, and
        // every stage it does not name extracts nothing. The fixture names
        // none, so all seven stages are the package's here.
        $strategies = config('scribe.strategies');

        $this->assertSame([
            'metadata',
            'headers',
            'urlParameters',
            'queryParameters',
            'bodyParameters',
            'responses',
            'responseFields',
        ], array_keys($strategies));
    }

    /**
     * The provider's `mergeableOptions()`, which is protected.
     *
     * @return array<int, string>
     */
    private function mergeableOptions(): array
    {
        $provider = new \Ipsocode\Scribe\ScribeServiceProvider($this->app);

        return (fn () => $this->mergeableOptions('scribe'))->call($provider);
    }
}
