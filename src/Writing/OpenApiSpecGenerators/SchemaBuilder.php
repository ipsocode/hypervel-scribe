<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Closure;
use Hypervel\Support\Str;
use Ipsocode\Camel\Extraction\Response;
use Ipsocode\Camel\Extraction\ResponseField;
use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Camel\Output\Parameter;
use Ipsocode\Scribe\Tools\Utils;
use stdClass;

/**
 * Turns parameters and response values into JSON-schema fragments for
 * BaseGenerator and Base31Generator.
 *
 * Whatever a generator may override stays with the generator and is reached
 * through the closures it hands in, so a custom generator still shapes every
 * schema: how a schema is marked nullable, how a nested field or response value
 * is built, and how a type name is normalised.
 */
final class SchemaBuilder
{
    /**
     * @param Closure(array<string, mixed>, bool): void $applyNullable takes the schema by reference
     * @param Closure(array<string, mixed>|Parameter): array<string, mixed> $generateFieldData
     * @param Closure(mixed, OutputEndpointData, string): array<string, mixed> $generateSchemaForResponseValue
     * @param Closure(?string): string $normalizeTypeName
     */
    public function __construct(
        private readonly Closure $applyNullable,
        private readonly Closure $generateFieldData,
        private readonly Closure $generateSchemaForResponseValue,
        private readonly Closure $normalizeTypeName,
    ) {
    }

    /**
     * @param array<string, mixed>|Parameter $field
     * @return array<string, mixed>
     */
    public function field(array|Parameter $field): array
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
            ($this->applyNullable)($fieldData, $field->nullable);

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

            ($this->applyNullable)($baseItem, $field->nullable);

            $fieldData = [
                'type' => 'array',
                'description' => $field->description ?: '',
                'example' => $field->example,
                'items' => Utils::isArrayType($baseType)
                    ? ($this->generateFieldData)([
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
                    $fieldData['items']['properties'][$fieldSimpleName] = ($this->generateFieldData)($subfield);
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
                    return [$subfieldName => ($this->generateFieldData)($subfield)];
                })->all()),
                'required' => collect($field->__fields)->filter(fn ($f) => $f['required'])->keys()->toArray(),
            ];
            ($this->applyNullable)($data, $field->nullable);
            // The spec doesn't allow an empty `required` array.
            if (empty($data['required'])) {
                unset($data['required']);
            }

            return $data;
        }
        $schema = [
            'type' => ($this->normalizeTypeName)($field->type),
            'description' => $field->description ?: '',
            'example' => $field->example,
        ];
        if (! empty($field->enumValues)) {
            $schema['enum'] = $field->enumValues;
        }
        ($this->applyNullable)($schema, $field->nullable);

        return $schema;
    }

    /**
     * Given a value, generate the schema for it. The schema consists of: {type:, example:, properties: (if value is an
     * object)}, and possibly a description for each property. The $endpoint and $path are used for looking up response
     * field descriptions.
     *
     * @return array<string, mixed>
     */
    public function schemaForResponseValue(mixed $value, OutputEndpointData $endpoint, string $path): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
            $properties = [];
            foreach ($value as $subField => $subValue) {
                $subFieldPath = sprintf('%s.%s', $path, $subField);
                $properties[$subField] = ($this->generateSchemaForResponseValue)($subValue, $endpoint, $subFieldPath);
            }
            $required = $this->requiredResponseFields($endpoint, array_keys($properties), $path);

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
            'type' => $this->openApiType(gettype($value)),
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
            $typeOfEachItem = $this->openApiType(gettype($sample));
            $schema['items']['type'] = $typeOfEachItem;

            if ($typeOfEachItem === 'object') {
                $schema['items']['properties'] = collect((array) $sample)->mapWithKeys(function ($v, $k) use ($endpoint, $path) {
                    return [$k => ($this->generateSchemaForResponseValue)($v, $endpoint, "{$path}.{$k}")];
                })->toArray();

                $required = $this->requiredResponseFields(
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
     *
     * @param array<int|string, mixed> $properties
     * @return array<int, string>
     */
    public function requiredResponseFields(OutputEndpointData $endpoint, array $properties, string $path = ''): array
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

    /**
     * @return array<string, mixed>
     */
    public function responseContent(?string $responseContent, OutputEndpointData $endpoint): array
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
            ($this->applyNullable)($schema, true);

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
                    $properties = collect((array) $decoded[0])->mapWithKeys(function ($value, $key) use ($endpoint) {
                        return [$key => ($this->generateSchemaForResponseValue)($value, $endpoint, $key)];
                    })->toArray();

                    $requiredFields = $this->requiredResponseFields($endpoint, array_keys($properties));

                    $items = [
                        'type' => $this->openApiType(gettype($decoded[0])),
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
                                'type' => $this->openApiType(gettype($decoded[0])),
                            ],
                            'example' => $decoded,
                        ],
                    ],
                ];

            case 'object':
                $properties = collect((array) $decoded)->mapWithKeys(function ($value, $key) use ($endpoint) {
                    return [$key => ($this->generateSchemaForResponseValue)($value, $endpoint, $key)];
                })->toArray();
                $required = $this->requiredResponseFields($endpoint, array_keys($properties));

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
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|stdClass
     */
    public function objectIfEmpty(array $field): array|stdClass
    {
        return count($field) > 0 ? $field : new stdClass;
    }

    public function openApiType(mixed $type): mixed
    {
        return match ($type) {
            'float', 'double' => 'number',
            'NULL' => 'string',
            default => $type,
        };
    }

    /**
     * Copy the response field's description, if it has one, onto the schema.
     *
     * @param array<string, mixed> $schema
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
     *
     * @param array<string, mixed> $schema
     */
    private function setNullable(array &$schema, OutputEndpointData $endpoint, string $path, mixed $value): void
    {
        /** @var null|ResponseField $field */
        $field = $endpoint->responseFields[$path] ?? null;

        // prefer explicit values
        if ($field !== null && $field->nullable !== null) {
            if ($field->nullable) {
                ($this->applyNullable)($schema, true);
            }

            // false => do not set and do not use example
            return;
        }

        // example is null
        if ($value === null) {
            ($this->applyNullable)($schema, true);
        }
    }
}
