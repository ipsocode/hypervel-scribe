<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Metadata;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Attributes\Authenticated;
use Ipsocode\Scribe\Attributes\Deprecated;
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\Subgroup;
use Ipsocode\Scribe\Attributes\Unauthenticated;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Extracting\Strategies\PhpAttributeStrategy;

/**
 * @extends PhpAttributeStrategy<Authenticated|Endpoint|Group|Subgroup>
 */
class GetFromMetadataAttributes extends PhpAttributeStrategy
{
    use ParamHelpers;

    protected static array $attributeNames = [
        Group::class,
        Subgroup::class,
        Endpoint::class,
        Authenticated::class,
        Unauthenticated::class,
        Deprecated::class,
    ];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        $metadata = [
            'groupName' => '',
            'groupDescription' => '',
            'subgroup' => '',
            'subgroupDescription' => '',
            'title' => '',
            'description' => '',
        ];
        foreach ([...$attributesOnController, ...$attributesOnFormRequest, ...$attributesOnMethod] as $attributeInstance) {
            $metadata = array_merge($metadata, $attributeInstance->toArray());
        }

        return $metadata;
    }
}
