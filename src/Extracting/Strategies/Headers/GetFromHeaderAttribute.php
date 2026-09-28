<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Headers;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Attributes\Header;
use Ipsocode\Scribe\Extracting\Strategies\PhpAttributeStrategy;

/**
 * @extends PhpAttributeStrategy<Header>
 */
class GetFromHeaderAttribute extends PhpAttributeStrategy
{
    protected static array $attributeNames = [Header::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $headers = [];
        foreach ([...$attributesOnController, ...$attributesOnFormRequest, ...$attributesOnMethod] as $attributeInstance) {
            $data = $attributeInstance->toArray();
            $data['example'] ??= $this->generateDummyValue('string');
            $headers[$data['name']] = $data['example'];
        }

        return $headers;
    }
}
