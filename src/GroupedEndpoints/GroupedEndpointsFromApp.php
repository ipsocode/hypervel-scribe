<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\GroupedEndpoints;

use Closure;
use Exception;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Ipsocode\Camel\Camel;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Commands\GenerateDocumentation;
use Ipsocode\Scribe\Exceptions\CouldntGetRouteDetails;
use Ipsocode\Scribe\Extracting\ApiDetails;
use Ipsocode\Scribe\Extracting\DocBlocks\DocBlockTag;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Matching\MatchedRoute;
use Ipsocode\Scribe\Matching\RouteMatcherInterface;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils as e;
use Ipsocode\Scribe\Tools\PathConfig;
use Ipsocode\Scribe\Tools\Utils;
use Ipsocode\Scribe\Tools\Utils as u;
use ReflectionClass;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class GroupedEndpointsFromApp implements GroupedEndpointsContract
{
    public string $camelDir;

    public string $cacheDir;

    private DocumentationConfig $docConfig;

    private bool $encounteredErrors = false;

    /**
     * The previous entries of the routes whose extraction failed this run, as
     * they stand in the Camel files (`latest`, with the user's edits) and in
     * the cache (`cached`), so that writing the run's output doesn't delete them.
     *
     * @var array{latest: list<array<string, mixed>>, cached: list<array<string, mixed>>}
     */
    private array $carriedForward = ['latest' => [], 'cached' => []];

    public function __construct(
        private GenerateDocumentation $command,
        private RouteMatcherInterface $routeMatcher,
        protected PathConfig $paths,
        private bool $preserveUserChanges = true,
    ) {
        $this->docConfig = $command->getDocConfig();

        $this->camelDir = Camel::camelDir($this->paths);
        $this->cacheDir = Camel::cacheDir($this->paths);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        $groupedEndpoints = $this->extractEndpointsInfoAndWriteToDisk($this->routeMatcher, $this->preserveUserChanges);

        $this->extractAndWriteApiDetailsToDisk();

        return $groupedEndpoints;
    }

    public function hasEncounteredErrors(): bool
    {
        return $this->encounteredErrors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function extractEndpointsInfoAndWriteToDisk(RouteMatcherInterface $routeMatcher, bool $preserveUserChanges): array
    {
        $latestEndpointsData = [];
        $cachedEndpoints = [];
        $this->carriedForward = ['latest' => [], 'cached' => []];

        if ($preserveUserChanges && is_dir($this->camelDir) && is_dir($this->cacheDir)) {
            $latestEndpointsData = Camel::loadEndpointsToFlatPrimitivesArray($this->camelDir);
            $cachedEndpoints = Camel::loadEndpointsToFlatPrimitivesArray($this->cacheDir);
        }

        $routes = $routeMatcher->getRoutes($this->docConfig->get('routes', []));
        $endpoints = $this->extractEndpointsInfoFromHypervelApp($routes, $cachedEndpoints, $latestEndpointsData);

        $groupedEndpoints = collect($endpoints)->groupBy('metadata.groupName')->map(function (Collection $endpointsInGroup) {
            return [
                'name' => $endpointsInGroup->first(function (ExtractedEndpointData $endpointData) {
                    return ! empty($endpointData->metadata->groupName);
                })->metadata->groupName ?? '',
                'description' => $endpointsInGroup->first(function (ExtractedEndpointData $endpointData) {
                    return ! empty($endpointData->metadata->groupDescription);
                })->metadata->groupDescription ?? '',
                'endpoints' => $endpointsInGroup->toArray(),
            ];
        })->all();
        $this->writeEndpointsToDisk($groupedEndpoints);

        return $groupedEndpoints;
    }

    /**
     * @param array<string, array<string, mixed>> $grouped
     */
    protected function writeEndpointsToDisk(array $grouped): void
    {
        $camelGroups = self::withEntries($grouped, $this->carriedForward['latest']);
        $cacheGroups = self::withEntries($grouped, $this->carriedForward['cached']);

        Utils::deleteFilesMatching(
            $this->camelDir,
            fn (SplFileInfo $file) => ! Str::startsWith($file->getFilename(), 'custom.'),
        );
        Utils::deleteDirectoryAndContents($this->cacheDir);

        if (! is_dir($this->camelDir)) {
            mkdir($this->camelDir, 0o777, true);
        }

        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o777, true);
        }

        // Two digits, so a plain name sort in a file browser lists them in
        // order too. Camel reads them back in natural order either way.
        foreach (array_values($camelGroups) as $index => $group) {
            file_put_contents($this->camelDir . sprintf('/%02d.yaml', $index), self::dumpGroup($group));
        }

        foreach (array_values($cacheGroups) as $index => $group) {
            file_put_contents($this->cacheDir . sprintf('/%02d.yaml', $index), "## Autogenerated by Scribe. DO NOT MODIFY.\n\n" . self::dumpGroup($group));
        }
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function dumpGroup(array $group): string
    {
        return Yaml::dump(
            $group,
            20,
            2,
            Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );
    }

    /**
     * Adds previously written endpoint entries to the group they were in,
     * starting that group if this run produced none of it.
     *
     * @param array<string, array<string, mixed>> $grouped
     * @param list<array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private static function withEntries(array $grouped, array $entries): array
    {
        foreach ($entries as $entry) {
            $name = (string) ($entry['metadata']['groupName'] ?? '');
            $grouped[$name] ??= [
                'name' => $name,
                'description' => (string) ($entry['metadata']['groupDescription'] ?? ''),
                'endpoints' => [],
            ];
            $grouped[$name]['endpoints'][] = $entry;
        }

        return $grouped;
    }

    protected function extractAndWriteApiDetailsToDisk(): void
    {
        $apiDetails = $this->makeApiDetails();

        $apiDetails->writeMarkdownFiles();
    }

    protected function makeApiDetails(): ApiDetails
    {
        return new ApiDetails($this->paths, $this->docConfig, ! $this->command->option('force'));
    }

    protected function makeExtractor(): Extractor
    {
        return new Extractor($this->docConfig);
    }

    /**
     * @param MatchedRoute[] $matches
     * @param array<int, array<string, mixed>> $cachedEndpoints
     * @param array<int, array<string, mixed>> $latestEndpointsData
     * @return list<ExtractedEndpointData>
     *
     * @throws Exception
     */
    private function extractEndpointsInfoFromHypervelApp(array $matches, array $cachedEndpoints, array $latestEndpointsData): array
    {
        $extractor = $this->makeExtractor();

        // Indexed so each route's lookup below is not a linear scan.
        $cachedEndpoints = self::indexByUriAndMethods($cachedEndpoints);
        $latestEndpointsData = self::indexByUriAndMethods($latestEndpointsData);

        $parsedEndpoints = [];

        foreach ($matches as $routeItem) {
            $route = $routeItem->getRoute();

            $routeControllerAndMethod = u::getRouteClassAndMethodNames($route);
            // @codeCoverageIgnoreStart
            // getRouteClassAndMethodNames() either returns a usable pair or
            // throws, so isValidRoute() has nothing left to reject here.
            if (! $this->isValidRoute($routeControllerAndMethod)) {
                c::warn('Skipping invalid route: ' . c::getRouteRepresentation($route));

                continue;
            }
            // @codeCoverageIgnoreEnd

            if (! $this->doesControllerMethodExist($routeControllerAndMethod)) {
                c::warn('Skipping route: ' . c::getRouteRepresentation($route) . ' - Controller method does not exist.');

                continue;
            }

            if ($this->isRouteHiddenFromDocumentation($routeControllerAndMethod)) {
                c::warn('Skipping route: ' . c::getRouteRepresentation($route) . ': @hideFromAPIDocumentation was specified.');

                continue;
            }

            try {
                // Buffer warnings so they don't break the task output.
                c::startWarningBuffer();

                $this->command->outputComponents()->task(
                    c::getRouteRepresentation($route),
                    function () use ($extractor, $route, $cachedEndpoints, $latestEndpointsData, &$parsedEndpoints) {
                        $currentEndpointData = $extractor->processRoute($route);
                        // Carry over sections the user edited in the Camel files.
                        $currentEndpointData = $this->mergeAnyEndpointDataUpdates($currentEndpointData, $cachedEndpoints, $latestEndpointsData);
                        $parsedEndpoints[] = $currentEndpointData;

                        return true;
                    }
                );

                c::flushWarningBuffer();
            } catch (Throwable $exception) {
                $this->encounteredErrors = true;
                c::flushWarningBuffer();
                e::dumpExceptionIfVerbose($exception);

                // Without this the failed route's entry, and any edits the user
                // made to it, would be deleted along with the old files.
                $key = self::uriAndMethodsKey($route->uri(), ExtractedEndpointData::getMethods($route));
                $latest = $latestEndpointsData[$key] ?? null;
                $cached = $cachedEndpoints[$key] ?? null;
                if ($latest !== null) {
                    $this->carriedForward['latest'][] = $latest;
                }
                if ($cached !== null) {
                    $this->carriedForward['cached'][] = $cached;
                }
                if ($latest !== null || $cached !== null) {
                    c::warn('Kept the previous documentation of ' . c::getRouteRepresentation($route) . '.');
                }
            }
        }

        return $parsedEndpoints;
    }

    /**
     * @param array<string, array<string, mixed>> $cachedEndpoints indexed by {@see self::indexByUriAndMethods()}
     * @param array<string, array<string, mixed>> $latestEndpointsData indexed by {@see self::indexByUriAndMethods()}
     */
    private function mergeAnyEndpointDataUpdates(ExtractedEndpointData $endpointData, array $cachedEndpoints, array $latestEndpointsData): ExtractedEndpointData
    {
        $key = self::uriAndMethodsKey($endpointData->uri, $endpointData->httpMethods);

        $thisEndpointCached = $cachedEndpoints[$key] ?? null;
        if (! $thisEndpointCached) {
            return $endpointData;
        }

        $thisEndpointLatest = $latestEndpointsData[$key] ?? null;
        if (! $thisEndpointLatest) {
            return $endpointData;
        }

        // A section that differs between the cache and the Camel file was edited by the user.
        $properties = [
            'metadata',
            'headers',
            'urlParameters',
            'queryParameters',
            'bodyParameters',
            'responses',
            'responseFields',
        ];

        $changed = [];
        foreach ($properties as $property) {
            // A section the user deleted from the file reads as absent, not as an error.
            if (($thisEndpointCached[$property] ?? null) !== ($thisEndpointLatest[$property] ?? null)) {
                $changed[] = $property;
            }
        }

        $thisEndpointLatest = OutputEndpointData::create($thisEndpointLatest);
        foreach ($changed as $property) {
            $endpointData->{$property} = $thisEndpointLatest->{$property};
        }

        return $endpointData;
    }

    /**
     * Keys a flat list of endpoint arrays by URI and HTTP methods. The first
     * endpoint with a given pair wins.
     *
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<string, array<string, mixed>>
     */
    private static function indexByUriAndMethods(array $endpoints): array
    {
        $index = [];
        foreach ($endpoints as $endpoint) {
            $index[self::uriAndMethodsKey($endpoint['uri'] ?? null, $endpoint['httpMethods'] ?? null)] ??= $endpoint;
        }

        return $index;
    }

    /**
     * A key two endpoints share exactly when their URIs and method lists are
     * identical (`===`): serialize() distinguishes a URI's type and a method
     * list's keys and order, as `===` does.
     */
    private static function uriAndMethodsKey(mixed $uri, mixed $httpMethods): string
    {
        return serialize([$uri, $httpMethods]);
    }

    /**
     * @param null|array<int, mixed> $routeControllerAndMethod
     */
    private function isValidRoute(?array $routeControllerAndMethod): bool
    {
        if (is_array($routeControllerAndMethod)) {
            if (count($routeControllerAndMethod) < 2) {
                throw CouldntGetRouteDetails::new();
            }
            [$classOrObject, $method] = $routeControllerAndMethod;
            if (u::isInvokableObject($classOrObject)) {
                return true;
            }
            $routeControllerAndMethod = $classOrObject . '@' . $method;
        }

        return ! is_callable($routeControllerAndMethod) && ! is_null($routeControllerAndMethod);
    }

    /**
     * @param array<int, mixed> $routeControllerAndMethod
     */
    private function doesControllerMethodExist(array $routeControllerAndMethod): bool
    {
        // @codeCoverageIgnoreStart
        // isValidRoute() has already thrown on a pair this short.
        if (count($routeControllerAndMethod) < 2) {
            throw CouldntGetRouteDetails::new();
        }
        // @codeCoverageIgnoreEnd
        [$class, $method] = $routeControllerAndMethod;
        if (is_string($class) && ! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->hasMethod($method)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, mixed> $routeControllerAndMethod
     */
    private function isRouteHiddenFromDocumentation(array $routeControllerAndMethod): bool
    {
        if (! ($class = $routeControllerAndMethod[0]) instanceof Closure) {
            $classDocBlock = RouteDocBlocker::forClass($class);
            $shouldIgnoreClass = collect($classDocBlock->getTags())
                ->filter(function (DocBlockTag $tag) {
                    return Str::lower($tag->getName()) === 'hidefromapidocumentation';
                })->isNotEmpty();

            if ($shouldIgnoreClass) {
                return true;
            }
        }

        $methodDocBlock = RouteDocBlocker::forMethod(u::getReflectedRouteMethod($routeControllerAndMethod));
        $shouldIgnoreMethod = collect($methodDocBlock->getTags())
            ->filter(function (DocBlockTag $tag) {
                return Str::lower($tag->getName()) === 'hidefromapidocumentation';
            })->isNotEmpty();

        return $shouldIgnoreMethod;
    }
}
