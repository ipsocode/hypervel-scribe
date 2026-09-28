<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Unit\Tools;

use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Ipsocode\Scribe\Tests\Unit\TestCase;
use Ipsocode\Scribe\Tools\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;

/**
 * The differ behind `scribe:config-diff`. Its output is a flat map of dotted
 * paths to printable strings, so every case here asserts on the exact spelling
 * a bug report would be pasted with.
 */
class ConfigDifferTest extends TestCase
{
    #[UnitTest]
    #[Test]
    public function reportsNothingWhenTheValuesMatch(): void
    {
        $differ = new ConfigDiffer(
            original: ['title' => null, 'theme' => 'default', 'unvisited' => 'ignored'],
            changed: ['theme' => 'default', 'title' => null],
        );

        // Keys absent from `changed` are never visited: the walk is driven by
        // what the application actually has, not by what the defaults offer.
        $this->assertSame([], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function reportsChangedScalarsAsJson(): void
    {
        $differ = new ConfigDiffer(
            original: ['title' => null, 'theme' => 'default', 'logo' => false],
            changed: ['theme' => 'elements', 'title' => null, 'logo' => 'img/logo.png'],
        );

        // JSON rather than PHP literals, and slashes left unescaped so a path
        // or a URL stays readable.
        $this->assertSame([
            'theme' => '"elements"',
            'logo' => '"img/logo.png"',
        ], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function reportsNestedArraysByTheirDottedPath(): void
    {
        $differ = new ConfigDiffer(
            original: ['auth' => ['enabled' => false, 'in' => 'bearer', 'name' => 'key']],
            changed: ['auth' => ['enabled' => true, 'in' => 'query', 'name' => 'key']],
        );

        $this->assertSame([
            'auth.enabled' => 'true',
            'auth.in' => '"query"',
        ], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function reportsAKeyTheDefaultsDoNotHaveAtAll(): void
    {
        $differ = new ConfigDiffer(
            original: ['theme' => 'default'],
            changed: ['theme' => 'default', 'nested' => ['added' => 'value']],
        );

        // `data_get` returns null for the missing branch, which differs from
        // the value — a config key this package has since dropped is exactly
        // what a bug report needs to surface.
        $this->assertSame(['nested.added' => '"value"'], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function skipsTheIgnoredPaths(): void
    {
        $differ = new ConfigDiffer(
            original: [
                'theme' => 'default',
                'description' => '',
                'auth' => ['extra_info' => 'Default blurb.', 'in' => 'bearer'],
            ],
            changed: [
                'theme' => 'elements',
                'description' => 'Details',
                'auth' => ['extra_info' => 'Ours.', 'in' => 'query'],
            ],
            ignorePaths: ['description', 'auth.extra_info'],
        );

        // Prose is expected to differ; reporting it would bury the one line
        // that matters.
        $this->assertSame([
            'theme' => '"elements"',
            'auth.in' => '"query"',
        ], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function ignorePathsAreMatchedAsPatterns(): void
    {
        $differ = new ConfigDiffer(
            original: ['routes' => [['match' => ['prefixes' => ['api/*']]]], 'theme' => 'default'],
            changed: ['routes' => [['match' => ['prefixes' => ['v2/*']]]], 'theme' => 'elements'],
            ignorePaths: ['routes'],
        );

        // `routes` names the array, not a leaf, so the whole branch has to go —
        // `Str::is` is what stops the walk before it descends into `routes.0.*`.
        $this->assertSame(['theme' => '"elements"'], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function comparesListedPathsByMembershipRatherThanByIndex(): void
    {
        $differ = new ConfigDiffer(
            original: ['examples' => ['models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst']]],
            changed: ['examples' => ['models_source' => ['factoryMake', 'databaseFirst', 'stub']]],
            asList: ['examples.models_source'],
        );

        // Index-wise, every entry moved. As a list, one joined and one left.
        $this->assertSame([
            'examples.models_source' => 'added stub: removed factoryCreate',
        ], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function reportsAReorderedListAsUnchanged(): void
    {
        $differ = new ConfigDiffer(
            original: ['strategies' => ['metadata' => ['A', 'B']]],
            changed: ['strategies' => ['metadata' => ['B', 'A']]],
            asList: ['strategies.*'],
        );

        $this->assertSame([], $differ->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function reportsOnlyAdditionsOrOnlyRemovals(): void
    {
        $added = new ConfigDiffer(
            original: ['strategies' => ['headers' => ['A']]],
            changed: ['strategies' => ['headers' => ['A', 'B']]],
            asList: ['strategies.*'],
        );
        $removed = new ConfigDiffer(
            original: ['strategies' => ['headers' => ['A', 'B']]],
            changed: ['strategies' => ['headers' => ['A']]],
            asList: ['strategies.*'],
        );

        $this->assertSame(['strategies.headers' => 'added B'], $added->getDiff());
        $this->assertSame(['strategies.headers' => 'removed B'], $removed->getDiff());
    }

    #[UnitTest]
    #[Test]
    public function comparesListEntriesThatAreThemselvesArrays(): void
    {
        // What a configured strategy looks like: `Strategy::withSettings()`
        // returns an array, not a class-string. `array_diff` compares items as
        // strings, so these have to be exported before they can be told apart.
        $configured = ['ResponseCalls', ['only' => ['GET *']]];
        $reconfigured = ['ResponseCalls', ['only' => ['POST *']]];

        $differ = new ConfigDiffer(
            original: ['strategies' => ['responses' => [$configured]]],
            changed: ['strategies' => ['responses' => [$reconfigured]]],
            asList: ['strategies.*'],
        );

        $diff = $differ->getDiff();

        $this->assertArrayHasKey('strategies.responses', $diff);
        $this->assertStringContainsString("'POST *'", $diff['strategies.responses']);
        $this->assertStringContainsString("'GET *'", $diff['strategies.responses']);
        $this->assertStringStartsWith('added ', $diff['strategies.responses']);
    }

    #[UnitTest]
    #[Test]
    public function reportsAListThatReplacedANonList(): void
    {
        $differ = new ConfigDiffer(
            original: ['examples' => ['models_source' => null]],
            changed: ['examples' => ['models_source' => ['factoryCreate']]],
            asList: ['examples.models_source'],
        );

        // Nothing to subtract against, so the shape change is the report.
        $this->assertSame(['examples.models_source' => 'changed to a list'], $differ->getDiff());
    }
}
