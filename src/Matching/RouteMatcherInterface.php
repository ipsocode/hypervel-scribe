<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Matching;

interface RouteMatcherInterface
{
    /**
     * Resolve matched routes that should be documented.
     *
     * @param array $routeRules Route rules defined under the "routes" section in config
     * @return MatchedRoute[]
     */
    public function getRoutes(array $routeRules = []): array;
}
