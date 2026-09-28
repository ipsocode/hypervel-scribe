<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\GroupedEndpoints;

use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Tools\PathConfig;

class GroupedEndpointsFactory
{
    public function make(
        GenerateDocumentation $command,
        RouteMatcherInterface $routeMatcher,
        PathConfig $paths,
    ): GroupedEndpointsContract {
        if ($command->isForcing()) {
            return static::fromApp(
                command: $command,
                routeMatcher: $routeMatcher,
                preserveUserChanges: false,
                paths: $paths
            );
        }

        if ($command->shouldExtract()) {
            return static::fromApp(
                command: $command,
                routeMatcher: $routeMatcher,
                preserveUserChanges: true,
                paths: $paths
            );
        }

        return static::fromCamelDir($paths);
    }

    public static function fromApp(
        GenerateDocumentation $command,
        RouteMatcherInterface $routeMatcher,
        bool $preserveUserChanges,
        PathConfig $paths,
    ): GroupedEndpointsFromApp {
        return new GroupedEndpointsFromApp($command, $routeMatcher, $paths, $preserveUserChanges);
    }

    public static function fromCamelDir(PathConfig $paths): GroupedEndpointsFromCamelDir
    {
        return new GroupedEndpointsFromCamelDir($paths);
    }
}
