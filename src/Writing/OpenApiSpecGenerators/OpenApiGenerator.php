<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Tools\DocumentationConfig;

/**
 * Base for the generators that build the OpenAPI spec.
 *
 * Extend it and list the class in `openapi.generators` to customise the spec.
 * Each method builds one part of the spec from what the earlier generators
 * returned, and its return value replaces that part, so to build on them add to
 * the array and return it.
 */
abstract class OpenApiGenerator
{
    use ParamHelpers;

    public function __construct(protected DocumentationConfig $config)
    {
    }

    /**
     * The root of the OpenAPI document: general info about the API.
     *
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     *
     * @see https://spec.openapis.org/oas/v3.1.1.html#openapi-object
     */
    public function root(array $root, array $groupedEndpoints): array
    {
        return $root;
    }

    /**
     * One operation in a path item: the details of a single endpoint. Called once
     * per endpoint (the GET, the POST, ...).
     *
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     *
     * @see https://spec.openapis.org/oas/v3.1.1.html#path-item-object
     */
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        return $pathItem;
    }

    /**
     * The path item's shared `parameters`, from $urlParameters: the first
     * endpoint's URL parameters, which every endpoint at the path shares. Called
     * once per path (/users, /posts), however many endpoints share it.
     *
     * @param OutputEndpointData[] $endpoints
     * @param Parameter[] $urlParameters
     *
     * @see https://spec.openapis.org/oas/v3.1.1.html#parameter-object
     */
    public function pathParameters(array $parameters, array $endpoints, array $urlParameters): array
    {
        return $parameters;
    }
}
