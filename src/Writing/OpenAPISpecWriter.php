<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing;

use Hypervel\Support\Collection;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Utils;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\Base31Generator;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\BaseGenerator;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OverridesGenerator;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\SecurityGenerator;

class OpenAPISpecWriter
{
    use ParamHelpers;

    public const SPEC_VERSION = '3.0.3';

    private DocumentationConfig $config;

    /**
     * @var Collection<int, OpenApiGenerator>
     */
    private Collection $generators;

    public function __construct(?DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $generators = [
            $this->isOpenApi31OrLater() ? Base31Generator::class : BaseGenerator::class,
            SecurityGenerator::class,
            OverridesGenerator::class,
        ];
        $this->generators = collect($generators)
            ->merge($this->config->get('openapi.generators', []))
            ->map(fn ($generatorClass) => app()->makeWith($generatorClass, ['config' => $this->config]));
    }

    /**
     * Get the OpenAPI spec version to use from config, defaulting to 3.0.3.
     * Supported versions: '3.0.3', '3.1.0'.
     *
     * @return string The OpenAPI version
     */
    public function getSpecVersion(): string
    {
        return $this->config->get('openapi.version', self::SPEC_VERSION);
    }

    /**
     * See https://swagger.io/specification/.
     *
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     */
    public function generateSpecContent(array $groupedEndpoints): array
    {
        $paths = ['paths' => $this->generatePathsSpec($groupedEndpoints)];

        $content = [];
        foreach ($this->generators as $generator) {
            $content = $generator->root($content, $groupedEndpoints);
        }

        return array_replace_recursive($content, $paths);
    }

    /**
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     */
    protected function generatePathsSpec(array $groupedEndpoints): array
    {
        $groupedEndpoints = $this->expandOptionalPathVariants($groupedEndpoints);

        $allEndpoints = collect($groupedEndpoints)->map->endpoints->flatten(1);
        // OpenAPI groups endpoints by path, then method
        $groupedByPath = $allEndpoints->groupBy(fn (OutputEndpointData $endpoint) => $this->pathTemplate($endpoint->uri));

        return $groupedByPath->mapWithKeys(function (Collection $endpoints, $path) use ($groupedEndpoints) {
            $operations = $endpoints->mapWithKeys(function (OutputEndpointData $endpoint) use ($groupedEndpoints) {
                $spec = [];

                foreach ($this->generators as $generator) {
                    $spec = $generator->pathItem($spec, $groupedEndpoints, $endpoint);
                }

                return [mb_strtolower($endpoint->httpMethods[0]) => $spec];
            });

            $pathItem = $operations;

            // Placing all URL parameters at the path level, since it's the same path anyway
            /** @var OutputEndpointData $urlParameterEndpoint */
            $urlParameterEndpoint = $endpoints[0];

            $parameters = [];

            foreach ($this->generators as $generator) {
                $parameters = $generator->pathParameters($parameters, $endpoints->all(), $urlParameterEndpoint->urlParameters);
            }
            if (! empty($parameters)) {
                $pathItem['parameters'] = array_values($parameters);
            }

            return [$path => $pathItem];
        })->toArray();
    }

    /**
     * OpenAPI has no way to say that a path segment is optional, so a route with
     * one — `countries/list/{id?}` — is published as one path item per URI it
     * actually serves: `/countries/list` and `/countries/list/{id}`, each taking
     * only the parameters its own template names. Collapsing the two into a
     * single `{id}` path item, as the marker's removal alone would, publishes an
     * optional parameter as a required one and never documents the bare call.
     *
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     * @return array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}>
     */
    protected function expandOptionalPathVariants(array $groupedEndpoints): array
    {
        // A variant is only ever the second-best source for a path item, so
        // every URI the application registered itself is claimed up front.
        $taken = [];
        foreach ($groupedEndpoints as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                $taken[$this->pathItemKey($endpoint, $endpoint->uri)] = true;
            }
        }

        foreach ($groupedEndpoints as $index => $group) {
            $groupedEndpoints[$index]['endpoints'] = collect($group['endpoints'])
                ->flatMap(function (OutputEndpointData $endpoint) use (&$taken) {
                    $variants = [];

                    foreach ($this->pathVariants($endpoint->uri) as [$uri, $omitted]) {
                        // The URI as registered is the endpoint itself, untouched.
                        if ($omitted === []) {
                            $variants[] = $endpoint;

                            continue;
                        }

                        $key = $this->pathItemKey($endpoint, $uri);
                        if (isset($taken[$key])) {
                            continue;
                        }

                        $taken[$key] = true;
                        $variants[] = $this->endpointWithout($endpoint, $uri, $omitted);
                    }

                    return $variants;
                })->all();
        }

        return $groupedEndpoints;
    }

    /**
     * Every URI the route behind $uri serves, shortest first, as
     * [uri, names of the optional parameters that URI leaves out].
     *
     * Laravel only lets the trailing segments be optional, but several of them
     * may be, so the droppable ones are the optional run at the end.
     *
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    protected function pathVariants(string $uri): array
    {
        $segments = explode('/', $uri);

        $optional = [];
        for ($index = count($segments) - 1; $index >= 0; --$index) {
            if (! preg_match('/^\{([^}]+)\?\}$/', $segments[$index], $matches)) {
                break;
            }

            array_unshift($optional, $matches[1]);
        }

        $variants = [];
        $required = count($segments) - count($optional);

        for ($kept = 0; $kept < count($optional); ++$kept) {
            $variants[] = [
                str_replace('?}', '}', implode('/', array_slice($segments, 0, $required + $kept))),
                array_slice($optional, $kept),
            ];
        }

        $variants[] = [str_replace('?}', '}', $uri), []];

        return $variants;
    }

    /**
     * A copy of the endpoint at one of the shorter URIs its route also serves,
     * with the parameters that URI does not name taken off it.
     *
     * @param array<int, string> $omitted
     */
    protected function endpointWithout(OutputEndpointData $endpoint, string $uri, array $omitted): OutputEndpointData
    {
        $drop = array_flip($omitted);

        $variant = clone $endpoint;
        $variant->uri = $uri;
        $variant->omittedUrlParameters = $omitted;
        $variant->urlParameters = array_diff_key($endpoint->urlParameters, $drop);
        $variant->cleanUrlParameters = array_diff_key($endpoint->cleanUrlParameters, $drop);
        $variant->boundUri = Utils::getUrlWithBoundParameters($uri, $variant->cleanUrlParameters);

        return $variant;
    }

    /**
     * The slot an endpoint at $uri occupies in the spec: one method of one path
     * item, which is the granularity at which two of them would collide.
     */
    protected function pathItemKey(OutputEndpointData $endpoint, string $uri): string
    {
        return mb_strtolower($endpoint->httpMethods[0]) . ' ' . $this->pathTemplate($uri);
    }

    protected function pathTemplate(string $uri): string
    {
        // Remove optional parameters indicator in path
        return '/' . mb_ltrim(str_replace('?}', '}', $uri), '/');
    }

    protected function isOpenApi31OrLater(): bool
    {
        $version = $this->config->get('openapi.version', self::SPEC_VERSION);

        return version_compare($version, '3.1.0', '>=');
    }
}
