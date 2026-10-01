<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Writing\OpenApiSpecGenerators;

use Ipsocode\Camel\Output\Parameter;

/**
 * BaseGenerator for OpenAPI 3.1, which follows JSON Schema: nullable types are
 * type arrays, and examples go in an `examples` array.
 */
class Base31Generator extends BaseGenerator
{
    protected function applyNullable(array &$schema, bool $nullable): void
    {
        if (! $nullable) {
            return;
        }

        // 'string' becomes ['string', 'null'].
        if (isset($schema['type'])) {
            $currentType = $schema['type'];
            if (! is_array($currentType)) {
                $schema['type'] = [$currentType, 'null'];
            }
        }
    }

    public function generateFieldData($field): array
    {
        $fieldData = parent::generateFieldData($field);
        $this->convertExampleInSchemaToExamples($fieldData);

        return $fieldData;
    }

    public function generateSchemaForResponseValue(mixed $value, \Ipsocode\Camel\Output\OutputEndpointData $endpoint, string $path): array
    {
        $schema = parent::generateSchemaForResponseValue($value, $endpoint, $path);
        $this->convertExampleInSchemaToExamples($schema);

        return $schema;
    }

    protected function generateResponseContentSpec(?string $responseContent, \Ipsocode\Camel\Output\OutputEndpointData $endpoint): array
    {
        $contentSpec = parent::generateResponseContentSpec($responseContent, $endpoint);

        foreach ($contentSpec as $contentType => &$content) {
            if (isset($content['schema'])) {
                $this->convertExampleInSchemaToExamples($content['schema']);
            }
        }

        return $contentSpec;
    }

    /**
     * Replace a schema's 'example' with an 'examples' array, recursing into
     * properties and items. An existing 'examples' wins.
     */
    protected function convertExampleInSchemaToExamples(array &$schema): void
    {
        if (array_key_exists('example', $schema)) {
            if (! array_key_exists('examples', $schema)) {
                $schema['examples'] = [$schema['example']];
            }
            unset($schema['example']);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as &$property) {
                $this->convertExampleInSchemaToExamples($property);
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $this->convertExampleInSchemaToExamples($schema['items']);
        }
    }

    /**
     * Move a parameter object's 'example' into its schema's 'examples' (unless
     * the schema already has some), and convert the schema's properties and items.
     */
    protected function convertExampleOutsideSchemaToExamples(array &$data): void
    {
        if (isset($data['example'])) {
            if (! isset($data['schema']['examples'])) {
                $data['schema']['examples'] = [$data['example']];
            }
            unset($data['example']);
        }

        if (isset($data['schema']['properties']) && is_array($data['schema']['properties'])) {
            foreach ($data['schema']['properties'] as &$property) {
                $this->convertExampleInSchemaToExamples($property);
            }
        }

        if (isset($data['schema']['items']) && is_array($data['schema']['items'])) {
            $this->convertExampleInSchemaToExamples($data['schema']['items']);
        }
    }

    protected function headerToOpenApiParameterObject(string $name, string $value): array
    {
        $data = parent::headerToOpenApiParameterObject($name, $value);
        $this->convertExampleOutsideSchemaToExamples($data);

        return $data;
    }

    protected function queryParamToOpenApiParameterObject(string $name, Parameter $details): array
    {
        $data = parent::queryParamToOpenApiParameterObject($name, $details);
        $this->convertExampleOutsideSchemaToExamples($data);

        return $data;
    }

    protected function urlParamToOpenApiParameterObject(string $name, Parameter $details): array
    {
        $data = parent::urlParamToOpenApiParameterObject($name, $details);
        $this->convertExampleOutsideSchemaToExamples($data);

        return $data;
    }
}
