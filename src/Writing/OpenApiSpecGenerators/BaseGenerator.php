<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Camel\Extraction\ResponseField;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter;
use Ipsocode\Scribe\Tools\Utils;
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
     */
    private ?array $groupNameIndexSource = null;

    /**
     * @var array<string, string> endpoint ID => name of the first group holding it
     */
    private array $groupNameByEndpointId = [];

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
     * same array, which `!==` recognises by identity in constant time, so the
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
     * @param array|Parameter $field
     */
    public function generateFieldData($field): array
    {
        if (is_array($field)) {
            $field = new Parameter($field);
        }

        if ($field->type === 'file') {
            // See https://swagger.io/docs/specification/describing-request-body/file-upload/
            $fieldData = [
                'type' => 'string',
                'format' => 'binary',
                'description' => $field->description ?: '',
            ];
            $this->applyNullable($fieldData, $field->nullable);

            return $fieldData;
        }
        if (Utils::isArrayType($field->type)) {
            $baseType = Utils::getBaseTypeFromArrayType($field->type);
            $baseItem = ($baseType === 'file') ? [
                'type' => 'string',
                'format' => 'binary',
            ] : ['type' => $baseType];

            if (! empty($field->enumValues)) {
                $baseItem['enum'] = $field->enumValues;
            }

            $this->applyNullable($baseItem, $field->nullable);

            $fieldData = [
                'type' => 'array',
                'description' => $field->description ?: '',
                'example' => $field->example,
                'items' => Utils::isArrayType($baseType)
                    ? $this->generateFieldData([
                        'name' => '',
                        'type' => $baseType,
                        'example' => ($field->example ?: [null])[0],
                        'nullable' => $field->nullable,
                    ])
                    : $baseItem,
            ];
            if (str_replace('[]', '', $field->type) === 'file') {
                // File parameters get no example: there is no faithful way to express one.
                unset($fieldData['example']);
            }

            if ($baseType === 'object' && ! empty($field->__fields)) {
                if ($fieldData['items']['type'] === 'object') {
                    $fieldData['items']['properties'] = [];
                }
                foreach ($field->__fields as $fieldSimpleName => $subfield) {
                    $fieldData['items']['properties'][$fieldSimpleName] = $this->generateFieldData($subfield);
                    if ($subfield['required']) {
                        $fieldData['items']['required'][] = $fieldSimpleName;
                    }
                }
            }

            return $fieldData;
        }
        if ($field->type === 'object') {
            $data = [
                'type' => 'object',
                'description' => $field->description ?: '',
                'example' => $field->example,
                'properties' => $this->objectIfEmpty(collect($field->__fields)->mapWithKeys(function ($subfield, $subfieldName) {
                    return [$subfieldName => $this->generateFieldData($subfield)];
                })->all()),
                'required' => collect($field->__fields)->filter(fn ($f) => $f['required'])->keys()->toArray(),
            ];
            $this->applyNullable($data, $field->nullable);
            // The spec doesn't allow an empty `required` array.
            if (empty($data['required'])) {
                unset($data['required']);
            }

            return $data;
        }
        $schema = [
            'type' => static::normalizeTypeName($field->type),
            'description' => $field->description ?: '',
            'example' => $field->example,
        ];
        if (! empty($field->enumValues)) {
            $schema['enum'] = $field->enumValues;
        }
        $this->applyNullable($schema, $field->nullable);

        return $schema;
    }

    /**
     * Given a value, generate the schema for it. The schema consists of: {type:, example:, properties: (if value is an
     * object)}, and possibly a description for each property. The $endpoint and $path are used for looking up response
     * field descriptions.
     */
    public function generateSchemaForResponseValue(mixed $value, OutputEndpointData $endpoint, string $path): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
            $properties = [];
            foreach ($value as $subField => $subValue) {
                $subFieldPath = sprintf('%s.%s', $path, $subField);
                $properties[$subField] = $this->generateSchemaForResponseValue($subValue, $endpoint, $subFieldPath);
            }
            $required = $this->filterRequiredResponseFields($endpoint, array_keys($properties), $path);

            $schema = [
                'type' => 'object',
                'properties' => $this->objectIfEmpty($properties),
            ];
            if ($required) {
                $schema['required'] = $required;
            }
            $this->setDescription($schema, $endpoint, $path);
            $this->setNullable($schema, $endpoint, $path, $value);

            return $schema;
        }

        $schema = [
            'type' => $this->convertScribeOrPHPTypeToOpenAPIType(gettype($value)),
            'example' => $value,
        ];
        $this->setDescription($schema, $endpoint, $path);
        $this->setNullable($schema, $endpoint, $path, $value);

        if (! empty($endpoint->responseFields[$path]->enumValues)) {
            $schema['enum'] = $endpoint->responseFields[$path]->enumValues;
        }

        if ($schema['type'] === 'array' && ! empty($value)) {
            $schema['example'] = json_decode(json_encode($schema['example']), true); // Convert stdClass to array

            $sample = $value[0];
            $typeOfEachItem = $this->convertScribeOrPHPTypeToOpenAPIType(gettype($sample));
            $schema['items']['type'] = $typeOfEachItem;

            if ($typeOfEachItem === 'object') {
                $schema['items']['properties'] = collect($sample)->mapWithKeys(function ($v, $k) use ($endpoint, $path) {
                    return [$k => $this->generateSchemaForResponseValue($v, $endpoint, "{$path}.{$k}")];
                })->toArray();

                $required = $this->filterRequiredResponseFields(
                    $endpoint,
                    array_keys($schema['items']['properties']),
                    $path
                );
                if ($required) {
                    $schema['items']['required'] = $required;
                }
            }
        }

        return $schema;
    }

    /**
     * Given an endpoint and a set of object keys at a path, return the properties that are specified as required.
     */
    public function filterRequiredResponseFields(OutputEndpointData $endpoint, array $properties, string $path = ''): array
    {
        $required = [];
        foreach ($properties as $property) {
            $responseField = $endpoint->responseFields["{$path}.{$property}"] ?? $endpoint->responseFields[$property] ?? null;
            if ($responseField && $responseField->required) {
                $required[] = $property;
            }
        }

        return $required;
    }

    protected function operationId(OutputEndpointData $endpoint): string
    {
        if ($endpoint->metadata->title) {
            $operationId = preg_replace('/[^\w+]/', '', Str::camel($endpoint->metadata->title));
        } else {
            $parts = preg_split('/[^\w+]/', $endpoint->uri, -1, PREG_SPLIT_NO_EMPTY);
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

        return $operationId;
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

        return $this->objectIfEmpty($responses);
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

    protected function generateResponseContentSpec(?string $responseContent, OutputEndpointData $endpoint)
    {
        if (Str::startsWith($responseContent, '<<binary>>')) {
            return [
                'application/octet-stream' => [
                    'schema' => [
                        'type' => 'string',
                        'format' => 'binary',
                    ],
                ],
            ];
        }

        if ($responseContent === null) {
            $schema = [
                'type' => 'object',
            ];
            $this->applyNullable($schema, true);

            return [
                'application/json' => [
                    'schema' => $schema,
                ],
            ];
        }

        $decoded = json_decode($responseContent);
        if ($decoded === null) { // Not JSON, or JSON null: return the content as plain text
            return [
                'text/plain' => [
                    'schema' => [
                        'type' => 'string',
                        'example' => $responseContent,
                    ],
                ],
            ];
        }

        $response = $endpoint->responses->where('content', $responseContent)->first();
        $contentType = $response->headers['content-type'] ?? $response->headers['Content-Type'] ?? 'application/json';

        switch ($type = gettype($decoded)) {
            case 'string':
            case 'boolean':
            case 'integer':
            case 'double':
                return [
                    $contentType => [
                        'schema' => [
                            'type' => $type === 'double' ? 'number' : $type,
                            'example' => $decoded,
                        ],
                    ],
                ];

            case 'array':
                if (! count($decoded)) {
                    // empty array
                    return [
                        $contentType => [
                            'schema' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object', // The item type is unknown
                                ],
                                'example' => $decoded,
                            ],
                        ],
                    ];
                }

                // Non-empty array
                if (is_object($decoded[0])) {
                    // The first item stands in for every item.
                    $properties = collect($decoded[0])->mapWithKeys(function ($value, $key) use ($endpoint) {
                        return [$key => $this->generateSchemaForResponseValue($value, $endpoint, $key)];
                    })->toArray();

                    $requiredFields = $this->filterRequiredResponseFields($endpoint, array_keys($properties));

                    $items = [
                        'type' => $this->convertScribeOrPHPTypeToOpenAPIType(gettype($decoded[0])),
                        'properties' => $this->objectIfEmpty($properties),
                    ];

                    if ($requiredFields) {
                        $items['required'] = $requiredFields;
                    }

                    return [
                        $contentType => [
                            'schema' => [
                                'type' => 'array',
                                'items' => $items,
                                'example' => $decoded,
                            ],
                        ],
                    ];
                }

                return [
                    $contentType => [
                        'schema' => [
                            'type' => 'array',
                            'items' => [
                                'type' => $this->convertScribeOrPHPTypeToOpenAPIType(gettype($decoded[0])),
                            ],
                            'example' => $decoded,
                        ],
                    ],
                ];

            case 'object':
                $properties = collect($decoded)->mapWithKeys(function ($value, $key) use ($endpoint) {
                    return [$key => $this->generateSchemaForResponseValue($value, $endpoint, $key)];
                })->toArray();
                $required = $this->filterRequiredResponseFields($endpoint, array_keys($properties));

                $data = [
                    $contentType => [
                        'schema' => [
                            'type' => 'object',
                            'example' => $decoded,
                            'properties' => $this->objectIfEmpty($properties),
                        ],
                    ],
                ];
                if ($required) {
                    $data[$contentType]['schema']['required'] = $required;
                }

                return $data;
                // @codeCoverageIgnoreStart
                // json_decode() returns one of the types above or null, and a
                // null decode has already returned further up.
            default:
                return [];
                // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Turn an empty array into an object, for fields the spec requires to be
     * objects: an empty array would serialise as [].
     */
    protected function objectIfEmpty(array $field): array|stdClass
    {
        return count($field) > 0 ? $field : new stdClass;
    }

    protected function convertScribeOrPHPTypeToOpenAPIType($type)
    {
        return match ($type) {
            'float', 'double' => 'number',
            'NULL' => 'string',
            default => $type,
        };
    }

    /**
     * Mark a schema nullable the OpenAPI 3.0 way, with `nullable: true`.
     * Base31Generator overrides this with 3.1's type array.
     */
    protected function applyNullable(array &$schema, bool $nullable): void
    {
        if (! $nullable) {
            return;
        }

        $schema['nullable'] = true;
    }

    /**
     * Copy the response field's description, if it has one, onto the schema.
     */
    private function setDescription(array &$schema, OutputEndpointData $endpoint, string $path): void
    {
        if (! empty($endpoint->responseFields[$path]->description)) {
            $schema['description'] = $endpoint->responseFields[$path]->description;
        }
    }

    /**
     * Mark the schema nullable when the response field says so or, with no
     * explicit setting, when the example is null.
     */
    private function setNullable(array &$schema, OutputEndpointData $endpoint, string $path, mixed $value): void
    {
        /** @var null|ResponseField $field */
        $field = $endpoint->responseFields[$path] ?? null;

        // prefer explicit values
        if ($field !== null && $field->nullable !== null) {
            if ($field->nullable) {
                $this->applyNullable($schema, true);
            }

            // false => do not set and do not use example
            return;
        }

        // example is null
        if ($value === null) {
            $this->applyNullable($schema, true);
        }
    }

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
