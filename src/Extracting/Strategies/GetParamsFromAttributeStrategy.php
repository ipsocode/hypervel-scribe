<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Attributes\GenericParam;
use Ipsocode\Scribe\Extracting\ParamHelpers;

/**
 * @template T of GenericParam
 *
 * @extends PhpAttributeStrategy<T>
 */
class GetParamsFromAttributeStrategy extends PhpAttributeStrategy
{
    use ParamHelpers;

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $parameters = [];
        foreach ([...$attributesOnController, ...$attributesOnFormRequest, ...$attributesOnMethod] as $attributeInstance) {
            $parameters[$attributeInstance->name] = $attributeInstance->toArray();
        }

        return array_map([$this, 'normalizeParameterData'], $parameters);
    }

    protected function normalizeParameterData(array $data): array
    {
        $data['type'] = static::normalizeTypeName($data['type']);
        if (is_null($data['example'])) {
            $data['example'] = $this->generateDummyValue($data['type'], [
                'name' => $data['name'],
                'enumValues' => $data['enumValues'],
            ]);
        } elseif ($data['example'] === 'No-example' || $data['example'] === 'No-example.') {
            $data['example'] = null;
        }

        if ($data['required']) {
            $data['nullable'] = false;
        }

        $data['description'] = mb_trim($data['description'] ?? '');

        return $data;
    }
}
