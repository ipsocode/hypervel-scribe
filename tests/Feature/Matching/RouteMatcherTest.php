<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Matching;

use Hypervel\Telescope\Telescope;
use Ipsocode\Scribe\Matching\MatchedRoute;
use Ipsocode\Scribe\Matching\RouteMatcher;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use TypeError;

/**
 * The matcher runs against the real router, so everything here is asserted
 * against the Workbench application's own routes rather than a hand-built
 * RouteCollection that might disagree with it.
 */
class RouteMatcherTest extends TestCase
{
    /**
     * @return string[] the URIs of the matched routes
     */
    private function match(array $rules): array
    {
        return array_map(
            fn (MatchedRoute $matched) => $matched->getRoute()->uri(),
            (new RouteMatcher)->getRoutes($rules),
        );
    }

    #[Test]
    public function theContainerResolvesTheConfiguredRouteMatcher(): void
    {
        // The provider binds the interface from `scribe.routeMatcher`, so an
        // application can swap the implementation. If this binding breaks,
        // generation still runs — against the wrong matcher.
        $this->assertInstanceOf(RouteMatcher::class, $this->app->get(RouteMatcherInterface::class));
    }

    #[Test]
    public function matchesOnlyTheRoutesUnderTheConfiguredPrefix(): void
    {
        $matched = $this->match([['match' => ['prefixes' => ['api/*'], 'domains' => ['*']]]]);

        $this->assertContains('api/users', $matched);
        $this->assertContains('api/posts', $matched);
        // Registered in workbench/routes/web.php, outside the `api/*` prefix.
        $this->assertNotContains('/', $matched);
    }

    #[Test]
    public function aNarrowerPrefixExcludesTheRestOfTheApi(): void
    {
        $matched = $this->match([['match' => ['prefixes' => ['api/posts*'], 'domains' => ['*']]]]);

        $this->assertContains('api/posts', $matched);
        $this->assertNotContains('api/users', $matched);
    }

    #[Test]
    public function excludedRoutesAreDroppedEvenWhenTheyMatchThePrefix(): void
    {
        $rules = [[
            'match' => ['prefixes' => ['api/*'], 'domains' => ['*']],
            'exclude' => ['health'],
        ]];

        $matched = $this->match($rules);

        $this->assertNotContains('api/health', $matched);
        $this->assertContains('api/users', $matched);
    }

    #[Test]
    public function includedRoutesAreMatchedEvenWhenTheyMissThePrefix(): void
    {
        $rules = [[
            'match' => ['prefixes' => ['api/posts*'], 'domains' => ['*']],
            'include' => ['users.index'],
        ]];

        $matched = $this->match($rules);

        $this->assertContains('api/users', $matched);
    }

    #[Test]
    public function anExclusionWinsOverAnInclusion(): void
    {
        // shouldExcludeRoute() is consulted before shouldIncludeRoute(), so a
        // route named in both lists stays out. Worth pinning down: the opposite
        // precedence would quietly re-document something a consumer had
        // deliberately excluded.
        $rules = [[
            'match' => ['prefixes' => ['api/*'], 'domains' => ['*']],
            'include' => ['health'],
            'exclude' => ['health'],
        ]];

        $this->assertNotContains('api/health', $this->match($rules));
    }

    #[Test]
    public function noRulesMeansNoRoutes(): void
    {
        $this->assertSame([], $this->match([]));
    }

    #[Test]
    public function aMatchedRouteIsReadableAsAnArrayToo(): void
    {
        // The strategies index into a match as `$match['route']`, so the
        // ArrayAccess surface is part of the contract rather than a convenience.
        $match = new MatchedRoute($this->workbenchRoute('posts.index'));

        $this->assertTrue(isset($match['route']));
        $this->assertFalse(isset($match['nonsense']));

        $this->assertSame($match->getRoute(), $match['route']);

        $replacement = $this->workbenchRoute('posts.show');
        $match['route'] = $replacement;
        $this->assertSame($replacement, $match['route']);
    }

    #[Test]
    public function aMatchedRouteCannotBeUnset(): void
    {
        // ArrayAccess is implemented for reading a match, not for taking it
        // apart: the route it exposes is typed and non-nullable.
        $match = new MatchedRoute($this->workbenchRoute('posts.index'));

        $this->expectException(TypeError::class);

        unset($match['route']);
    }

    #[Test]
    public function telescopesRoutesAreExcludedWhenTelescopeIsInstalled(): void
    {
        // Telescope registers its own UI under `telescope/*`; an application
        // that has it should not find it in its API documentation. The suite
        // installs the hypervel/components monorepo, Telescope included.
        $this->assertTrue(class_exists(Telescope::class));

        $this->assertSame([], $this->match([
            ['match' => ['prefixes' => ['telescope/*'], 'domains' => ['*']]],
        ]));
    }
}
