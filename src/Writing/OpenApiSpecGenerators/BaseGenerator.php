<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter;
use Ipsocode\Scribe\Writing\OpenAPISpecWriter;
use stdClass;

/**
 * Builds the core of the OpenAPI spec: info, servers, tags, and each
 * operation's parameters, request body and responses.
 */
class BaseGenerator extends OpenApiGenerator
{
    /**
     * The grouped endpoints {@see self::$groupNameByEndpointId} was built from.
     *
     * @var null|array<int, array<string, mixed>>
     */
    private ?array $groupNameIndexSource = null;

    /**
     * @var array<string, string> endpoint ID => name of the first group holding it
     */
    private array $groupNameByEndpointId = [];

    /**
     * @var array<string, true> the operation IDs handed out so far in this spec
     */
    private array $usedOperationIds = [];

    private ?SchemaBuilder $schemaBuilder = null;

    public function root(array $root, array $groupedEndpoints): array
    {
        return array_merge($root, [
            'openapi' => $this->config->get('openapi.version', OpenAPISpecWriter::SPEC_VERSION),
            'info' => [
                'title' => $this->config->get('title') ?: config('app.name', ''),
                'description' => $this->config->get('description', ''),
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => mb_rtrim($this->config->get('base_url') ?? config('app.url'), '/'),
                ],
            ],
            'tags' => array_values(array_map(function (array $group) {
                return [
                    'name' => $group['name'],
                    'description' => $group['description'],
                ];
            }, $groupedEndpoints)),
        ]);
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $spec = [
            'summary' => $endpoint->metadata->title,
            'operationId' => $this->operationId($endpoint),
            'description' => $endpoint->metadata->description,
            'parameters' => $this->generateEndpointParametersSpec($endpoint),
            'responses' => $this->generateEndpointResponsesSpec($endpoint),
            'tags' => [$this->groupNameFor($groupedEndpoints, $endpoint)],
        ];

        if ($endpoint->metadata->deprecated) {
            $spec['deprecated'] = true;
        }

        if (count($endpoint->bodyParameters)) {
            $spec['requestBody'] = $this->generateEndpointRequestBodySpec($endpoint);
        }

        return array_merge($pathItem, $spec);
    }

    /**
     * The name of the first group that holds the endpoint.
     *
     * Asked once per operation; scanning every group each time would make the
     * spec quadratic in the number of endpoints. The writer hands every call the
     * same array, which `!==` recognizes by identity in constant time, so the
     * index is built once; a different array rebuilds it.
     *
     * @param array<int, array{description: string, name: string, endpoints: OutputEndpointData[]}> $groupedEndpoints
     */
    protected function groupNameFor(array $groupedEndpoints, OutputEndpointData $endpoint): ?string
    {
        if ($this->groupNameIndexSource !== $groupedEndpoints) {
            $this->groupNameByEndpointId = [];
            foreach ($groupedEndpoints as $group) {
                foreach ($group['endpoints'] as $groupEndpoint) {
                    $this->groupNameByEndpointId[$groupEndpoint->endpointId()] ??= $group['name'];
                }
            }
            $this->groupNameIndexSource = $groupedEndpoints;
        }

        return $this->groupNameByEndpointId[$endpoint->endpointId()] ?? null;
    }

    public function pathParameters(array $parameters, array $endpoints, array $urlParameters): array
    {
        foreach ($urlParameters as $name => $details) {
            $parameterData = $this->urlParamToOpenApiParameterObject($name, $details);
            $parameters[$name] = $parameterData;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed>|Parameter $field
     * @return array<string, mixed>
     */
    public function generateFieldData($field): array
    {
        return $this->schemas()->field($field);
    }

    /**
     * Given a value, generate the schema for it. The schema consists of: {type:, example:, properties: (if value is an
     * object)}, and possibly a description for each property. The $endpoint and $path are used for looking up response
     * field descriptions.
     *
     * @return array<string, mixed>
     */
    public function generateSchemaForResponseValue(mixed $value, OutputEndpointData $endpoint, string $path): array
    {
        return $this->schemas()->schemaForResponseValue($value, $endpoint, $path);
    }

    /**
     * Given an endpoint and a set of object keys at a path, return the properties that are specified as required.
     *
     * @param array<int|string, mixed> $properties
     * @return array<int, string>
     */
    public function filterRequiredResponseFields(OutputEndpointData $endpoint, array $properties, string $path = ''): array
    {
        return $this->schemas()->requiredResponseFields($endpoint, $properties, $path);
    }

    protected function operationId(OutputEndpointData $endpoint): string
    {
        if ($endpoint->metadata->title) {
            $operationId = preg_replace('/[^\w]/', '', Str::camel($endpoint->metadata->title));
        } else {
            $parts = preg_split('/[^\w]/', $endpoint->uri, -1, PREG_SPLIT_NO_EMPTY);
            // @phpstan-ignore argument.type (preg_split() fails only on a PCRE error, and the pattern is a constant)
            $operationId = Str::lower($endpoint->httpMethods[0]) . implode('', array_map(fn ($part) => ucfirst($part), $parts));
        }

        // A route with an optional segment is published as one path item per URI
        // it serves, and operation IDs have to stay unique across the spec, so
        // the shorter URIs say what they leave out. The URI as registered keeps
        // the plain ID.
        if ($endpoint->omittedUrlParameters !== []) {
            $operationId .= 'Without' . implode('', array_map(
                fn (string $name) => Str::studly($name),
                $endpoint->omittedUrlParameters,
            ));
        }

        // OpenAPI requires operation IDs to be unique, and two endpoints can share
        // a title. The generator is built fresh for each spec, so the IDs taken
        // so far are those of this spec.
        $unique = $operationId;
        for ($suffix = 2; isset($this->usedOperationIds[$unique]); ++$suffix) {
            $unique = $operationId . $suffix;
        }
        $this->usedOperationIds[$unique] = true;

        return $unique;
    }

    /**
     * Add query parameters and headers.
     *
     * @return array<int, array<string,mixed>>
     */
    protected function generateEndpointParametersSpec(OutputEndpointData $endpoint): array
    {
        $parameters = [];

        $parameters = $this->generateQueryParams($endpoint, $parameters);

        return $this->generateHeaders($endpoint, $parameters);
    }

    /**
     * @return array<string, mixed>|stdClass
     */
    protected function generateEndpointRequestBodySpec(OutputEndpointData $endpoint): array|stdClass
    {
        $body = [];

        if (count($endpoint->bodyParameters)) {
            $schema = [
                'type' => 'object',
                'properties' => [],
            ];

            $hasRequiredParameter = false;
            $hasFileParameter = false;

            foreach ($endpoint->nestedBodyParameters as $name => $details) {
                if ($name === '[]') { // Request body is an array
                    $hasRequiredParameter = true;
                    $schema = $this->generateFieldData($details);

                    break;
                }

                if ($details['required']) {
                    $hasRequiredParameter = true;
                    // Created on the first required parameter, since the spec
                    // doesn't allow an empty `required` array.
                    $schema['required'][] = $name;
                }

                if ($details['type'] === 'file') {
                    $hasFileParameter = true;
                }

                $fieldData = $this->generateFieldData($details);
                if ($details['deprecated']) {
                    $fieldData['deprecated'] = true;
                }

                $schema['properties'][$name] = $fieldData;
            }

            // An array body replaced the schema above and has no 'properties'.
            if (array_key_exists('properties', $schema)) {
                $schema['properties'] = $this->objectIfEmpty($schema['properties']);
            }
            $body['required'] = $hasRequiredParameter;

            if ($hasFileParameter) {
                $contentType = 'multipart/form-data';
            } elseif (isset($endpoint->headers['Content-Type'])) {
                $contentType = $endpoint->headers['Content-Type'];
            } else {
                $contentType = 'application/json';
            }

            $body['content'][$contentType]['schema'] = $schema;
        }

        return $this->objectIfEmpty($body);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function generateEndpointResponsesSpec(OutputEndpointData $endpoint)
    {
        // See https://swagger.io/docs/specification/describing-responses/
        $responses = [];

        foreach ($endpoint->responses as $response) {
            $code = $response->status; // OpenAPI spec requires status codes to be integers
            // OpenAPI keys responses by status code. A later response for a code
            // already seen joins a `oneOf` when it shares that content type, and
            // is dropped otherwise. A 204 has no content, so the last one wins.
            if ($code === 204) {
                // Must not add content for 204
                $responses[$code] = [
                    'description' => $this->getResponseDescription($response),
                ];
            } elseif (isset($responses[$code])) {
                $content = $this->generateResponseContentSpec($response->content, $endpoint);
                $contentType = array_keys($content)[0];
                if (isset($responses[$code]['content'][$contentType])) {
                    $newResponseExample = array_replace([
                        'description' => $this->getResponseDescription($response),
                    ], $content[$contentType]['schema']);

                    if (isset($responses[$code]['content'][$contentType]['schema']['oneOf'])) {
                        $responses[$code]['content'][$contentType]['schema']['oneOf'][] = $newResponseExample;
                    } else {
                        $existingResponseExample = array_replace([
                            'description' => $responses[$code]['description'],
                        ], $responses[$code]['content'][$contentType]['schema']);

                        $responses[$code]['description'] = '';
                        $responses[$code]['content'][$contentType]['schema'] = [
                            'oneOf' => [$existingResponseExample, $newResponseExample],
                        ];
                    }
                }
            } else {
                $responses[$code] = [
                    'description' => $this->getResponseDescription($response),
                    'content' => $this->generateResponseContentSpec($response->content, $endpoint),
                ];
            }
        }

        // OpenAPI requires an operation to have at least one response.
        return $responses ?: ['default' => ['description' => '']];
    }

    protected function getResponseDescription(Response $response): string
    {
        if ($response->isBinary()) {
            return mb_trim(str_replace('<<binary>>', '', $response->content));
        }

        $description = (string) $response->description;
        // The status code is recorded separately, so drop it from a "200, OK" style description.
        if (preg_match('/\d{3},\s+(.+)/', $description, $matches)) {
            $description = $matches[1];
        } elseif ($description === (string) $response->status) {
            $description = '';
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    protected function generateResponseContentSpec(?string $responseContent, OutputEndpointData $endpoint)
    {
        return $this->schemas()->responseContent($responseContent, $endpoint);
    }

    /**
     * Turn an empty array into an object, for fields the spec requires to be
     * objects: an empty array would serialize as [].
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|stdClass
     */
    protected function objectIfEmpty(array $field): array|stdClass
    {
        return $this->schemas()->objectIfEmpty($field);
    }

    protected function convertScribeOrPHPTypeToOpenAPIType(mixed $type): mixed
    {
        return $this->schemas()->openApiType($type);
    }

    /**
     * Mark a schema nullable the OpenAPI 3.0 way, with `nullable: true`.
     * Base31Generator overrides this with 3.1's type array.
     *
     * @param array<string, mixed> $schema
     */
    protected function applyNullable(array &$schema, bool $nullable): void
    {
        if (! $nullable) {
            return;
        }

        $schema['nullable'] = true;
    }

    /**
     * The schema builder, wired to this generator's overridable methods.
     */
    private function schemas(): SchemaBuilder
    {
        return $this->schemaBuilder ??= new SchemaBuilder(
            $this->applyNullable(...),
            $this->generateFieldData(...),
            $this->generateSchemaForResponseValue(...),
            static::normalizeTypeName(...),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     * @return array<int, array<string, mixed>>
     */
    protected function generateQueryParams(OutputEndpointData $endpoint, array $parameters): array
    {
        if (count($endpoint->queryParameters)) {
            /**
             * @var string $name
             * @var Parameter $details
             */
            foreach ($endpoint->queryParameters as $name => $details) {
                $parameterData = $this->queryParamToOpenApiParameterObject($name, $details);
                $parameters[] = $parameterData;
            }
        }

        return $parameters;
    }

    protected function generateHeaders(OutputEndpointData $endpoint, mixed $parameters): mixed
    {
        if (count($endpoint->headers)) {
            foreach ($endpoint->headers as $name => $value) {
                if (in_array(mb_strtolower($name), ['content-type', 'accept', 'authorization'])) {
                    // These headers are not allowed in the spec.
                    // https://swagger.io/docs/specification/describing-parameters/#header-parameters
                    continue;
                }

                $parameters[] = $this->headerToOpenApiParameterObject($name, $value);
            }
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>
     */
    protected function headerToOpenApiParameterObject(string $name, string $value): array
    {
        return [
            'in' => 'header',
            'name' => $name,
            'description' => '',
            'example' => $value,
            'schema' => [
                'type' => 'string',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function queryParamToOpenApiParameterObject(string $name, Parameter $details): array
    {
        $parameterData = [
            'in' => 'query',
            'name' => $name,
            'description' => $details->description,
            'example' => $details->example,
            'required' => $details->required,
            'schema' => $this->generateFieldData($details),
        ];
        if ($details->deprecated) {
            $parameterData['deprecated'] = true;
        }

        return $parameterData;
    }

    /**
     * @return array<string, mixed>
     */
    protected function urlParamToOpenApiParameterObject(string $name, Parameter $details): array
    {
        return [
            'in' => 'path',
            'name' => $name,
            'description' => $details->description,
            'example' => $details->example,
            // OAS requires path parameters to be required, and they are: a
            // parameter only reaches a path item whose template names it, and
            // the URIs that leave an optional one out are published as path
            // items of their own rather than as a caveat on this one.
            'required' => true,
            'schema' => [
                'type' => $details->type,
            ],
        ];
    }
}
