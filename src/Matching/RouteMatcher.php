<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Matching;

use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Route as RouteFacade;
use Hypervel\Support\Str;
use Hypervel\Telescope\Telescope;
use Ipsocode\Scribe\Tools\RoutePatternMatcher;

class RouteMatcher implements RouteMatcherInterface
{
    public function getRoutes(array $routeRules = []): array
    {
        return $this->getRoutesToBeDocumented($routeRules);
    }

    private function getRoutesToBeDocumented(array $routeRules): array
    {
        $allRoutes = $this->getAllRoutes();

        $matchedRoutes = [];

        foreach ($routeRules as $routeRule) {
            $includes = $routeRule['include'] ?? [];

            foreach ($allRoutes as $route) {
                if ($this->shouldExcludeRoute($route, $routeRule)) {
                    continue;
                }

                if ($this->shouldIncludeRoute($route, $routeRule, $includes)) {
                    $matchedRoutes[] = new MatchedRoute($route, $routeRule['apply'] ?? []);
                }
            }
        }

        return $matchedRoutes;
    }

    private function getAllRoutes()
    {
        return RouteFacade::getRoutes();
    }

    private function shouldIncludeRoute(Route $route, array $routeRule, array $mustIncludes): bool
    {
        if (RoutePatternMatcher::matches($route, $mustIncludes)) {
            return true;
        }

        $domainsToMatch = $routeRule['match']['domains'] ?? [];
        $pathsToMatch = $routeRule['match']['prefixes'] ?? [];

        return Str::is($domainsToMatch, $route->getDomain()) && Str::is($pathsToMatch, $route->uri());
    }

    private function shouldExcludeRoute(Route $route, array $routeRule): bool
    {
        $excludes = $routeRule['exclude'] ?? [];

        // Exclude this package's routes
        $excludes[] = 'scribe';
        $excludes[] = 'scribe.*';

        // Exclude Telescope's routes, when the application has it. Hypervel's
        // Telescope is this class; the upstream check named Laravel's, which a
        // Hypervel application never has, so it never excluded anything.
        if (class_exists(Telescope::class)) {
            $excludes[] = 'telescope/*';
        }

        return RoutePatternMatcher::matches($route, $excludes);
    }
}
