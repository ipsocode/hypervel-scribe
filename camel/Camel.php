<?php

declare(strict_types=1);

namespace Ipsocode\Camel;

use Closure;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Str;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Tools\Utils;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class Camel
{
    public static function cacheDir(PathConfig $paths): string
    {
        return $paths->intermediateOutputPath('endpoints.cache');
    }

    public static function camelDir(PathConfig $paths): string
    {
        return $paths->intermediateOutputPath('endpoints');
    }

    /**
     * Load endpoints from the Camel files into groups (arrays).
     *
     * @return array[] each array is a group with keys including `name` and `endpoints`
     */
    public static function loadEndpointsIntoGroups(string $folder): array
    {
        $groups = [];
        self::loadEndpointsFromCamelFiles($folder, function (array $group) use (&$groups) {
            $groups[$group['name']] = $group;
        });

        return $groups;
    }

    /**
     * Load endpoints from the Camel files into a flat list of endpoint arrays.
     * Useful when we don't care about groups, but simply want to compare endpoints contents
     * to see if anything changed.
     *
     * @return array[] list of endpoint arrays
     */
    public static function loadEndpointsToFlatPrimitivesArray(string $folder): array
    {
        $endpoints = [];
        self::loadEndpointsFromCamelFiles($folder, function (array $group) use (&$endpoints) {
            foreach ($group['endpoints'] as $endpoint) {
                $endpoints[] = $endpoint;
            }
        });

        return $endpoints;
    }

    public static function loadEndpointsFromCamelFiles(string $folder, callable $callback): void
    {
        foreach (self::yamlFilesIn($folder) as $file) {
            if (! Str::startsWith($file->getFilename(), 'custom.')) {
                $callback(Yaml::parseFile($file->getPathname()));
            }
        }
    }

    public static function loadUserDefinedEndpoints(string $folder): array
    {
        $userDefinedEndpoints = [];
        foreach (self::yamlFilesIn($folder) as $file) {
            if (Str::startsWith($file->getFilename(), 'custom.')) {
                $endpoints = Yaml::parseFile($file->getPathname());
                foreach (($endpoints ?: []) as $endpoint) {
                    $userDefinedEndpoints[] = $endpoint;
                }
            }
        }

        return $userDefinedEndpoints;
    }

    /**
     * The YAML files directly inside $folder, in natural name order.
     *
     * Group files are numbered `00.yaml`, `01.yaml`… in the order the groups
     * were written, and reading them back has to keep that order. Natural
     * order keeps it past `99.yaml` too, where the numbers gain a digit.
     *
     * @return SplFileInfo[]
     */
    private static function yamlFilesIn(string $folder): array
    {
        if (! File::isDirectory($folder)) {
            return [];
        }

        $files = array_filter(
            File::files($folder, hidden: true),
            fn (SplFileInfo $file) => $file->getExtension() === 'yaml',
        );
        usort($files, fn (SplFileInfo $a, SplFileInfo $b) => strnatcmp($a->getFilename(), $b->getFilename()));

        return $files;
    }

    public static function doesGroupContainEndpoint(array $group, OutputEndpointData $endpoint): bool
    {
        return (bool) Arr::first($group['endpoints'], function ($e) use ($endpoint) {
            return $e->endpointId() === $endpoint->endpointId();
        });
    }

    /**
     * @param array[] $groupedEndpoints
     * @param array $configFileOrder the order for groups that users specified in their config file
     * @return array[]
     */
    public static function sortByConfigFileOrder(array $groupedEndpoints, array $configFileOrder): array
    {
        if (empty($configFileOrder)) {
            ksort($groupedEndpoints, SORT_NATURAL);

            return $groupedEndpoints;
        }

        // First, sort groups
        $groupsOrder = Utils::getTopLevelItemsFromMixedConfigList($configFileOrder);
        $groupsCollection = collect($groupedEndpoints);
        $wildcardPosition = array_search('*', $groupsOrder);
        if ($wildcardPosition !== false) {
            $promotedGroups = array_splice($groupsOrder, 0, $wildcardPosition);
            $demotedGroups = array_splice($groupsOrder, 1);

            $promotedOrderedGroups = $groupsCollection->filter(fn ($group, $groupName) => in_array($groupName, $promotedGroups))
                ->sortKeysUsing(self::getOrderListComparator($promotedGroups));
            $demotedOrderedGroups = $groupsCollection->filter(fn ($group, $groupName) => in_array($groupName, $demotedGroups))
                ->sortKeysUsing(self::getOrderListComparator($demotedGroups));

            $nonWildcardGroups = array_merge($promotedGroups, $demotedGroups);
            $wildCardOrderedGroups = $groupsCollection->filter(fn ($group, $groupName) => ! in_array($groupName, $nonWildcardGroups))
                ->sortKeysUsing(self::getOrderListComparator($demotedGroups));

            $groupedEndpoints = $promotedOrderedGroups->merge($wildCardOrderedGroups)
                ->merge($demotedOrderedGroups);
        } else {
            $groupedEndpoints = $groupsCollection->sortKeysUsing(self::getOrderListComparator($groupsOrder));
        }

        return $groupedEndpoints->map(function (array $group, string $groupName) use ($configFileOrder) {
            $sortedEndpoints = collect($group['endpoints']);

            if (isset($configFileOrder[$groupName])) {
                // Second-level order list. Can contain endpoint or subgroup names
                $level2Order = Utils::getTopLevelItemsFromMixedConfigList($configFileOrder[$groupName]);
                $sortedEndpoints = $sortedEndpoints->sortBy(
                    function (OutputEndpointData $e) use ($configFileOrder, $level2Order) {
                        $endpointIdentifier = $e->httpMethods[0] . ' /' . $e->uri;

                        // First, check if there's an ordering specified for the endpoint itself
                        $indexOfEndpointInL2Order = array_search($endpointIdentifier, $level2Order);
                        if ($indexOfEndpointInL2Order !== false) {
                            return $indexOfEndpointInL2Order;
                        }

                        // Check if there's an ordering for the endpoint's subgroup
                        $indexOfSubgroupInL2Order = array_search($e->metadata->subgroup, $level2Order);
                        if ($indexOfSubgroupInL2Order !== false) {
                            // There's a subgroup order; check if there's an endpoints order within that
                            $orderOfEndpointsInSubgroup = $configFileOrder[$e->metadata->groupName][$e->metadata->subgroup] ?? [];
                            $indexOfEndpointInSubGroup = array_search($endpointIdentifier, $orderOfEndpointsInSubgroup);

                            return ($indexOfEndpointInSubGroup === false)
                                ? $indexOfSubgroupInL2Order
                                : ($indexOfSubgroupInL2Order + ($indexOfEndpointInSubGroup * 0.1));
                        }

                        return INF;
                    },
                );
            }

            return [
                'name' => $groupName,
                'description' => $group['description'],
                'endpoints' => $sortedEndpoints->all(),
            ];
        })->values()->all();
    }

    /**
     * Prepare endpoints to be turned into HTML.
     * Map them into OutputEndpointData DTOs, and sort them by the specified order in the config file.
     *
     * @param array<string,array[]> $groupedEndpoints
     */
    public static function prepareGroupedEndpointsForOutput(array $groupedEndpoints, array $configFileOrder = []): array
    {
        $groups = array_map(function (array $group) {
            return [
                'name' => $group['name'],
                'description' => $group['description'],
                'endpoints' => array_map(
                    fn (array $endpoint) => OutputEndpointData::fromExtractedEndpointArray($endpoint),
                    $group['endpoints']
                ),
            ];
        }, $groupedEndpoints);

        return self::sortByConfigFileOrder($groups, $configFileOrder);
    }

    /**
     * Given an $order list like ['first', 'second', ...], return a compare function that can be used to sort
     * a list of strings based on the order of items in $order.
     * Any strings not in the list are sorted with natural sort.
     */
    protected static function getOrderListComparator(array $order): Closure
    {
        return function ($a, $b) use ($order) {
            $indexOfA = array_search($a, $order);
            $indexOfB = array_search($b, $order);

            // If both are in the $order list, compare them normally based on their position in the list
            if ($indexOfA !== false && $indexOfB !== false) {
                return $indexOfA <=> $indexOfB;
            }

            // If only A is in the $order list, then it must come before B.
            if ($indexOfA !== false) {
                return -1;
            }

            // If only B is in the $order list, then it must come before A.
            if ($indexOfB !== false) {
                return 1;
            }

            // If neither is present, fall back to natural sort
            return strnatcmp($a, $b);
        };
    }
}
