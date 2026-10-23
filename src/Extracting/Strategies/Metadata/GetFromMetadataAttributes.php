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
use Ipsocode\Scribe\Extracting\DocBlocks\ParsedDocBlock;
use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
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
        // This strategy runs after the docblock one and would overwrite it, but a
        // tag on the method outranks an attribute on the controller.
        $setByMethodTags = $this->metadataSetByMethodDocBlock($endpointData);
        foreach ($attributesOnController as $attributeInstance) {
            $metadata = array_merge($metadata, array_diff_key($attributeInstance->toArray(), $setByMethodTags));
        }
        foreach ([...$attributesOnFormRequest, ...$attributesOnMethod] as $attributeInstance) {
            $metadata = array_merge($metadata, $attributeInstance->toArray());
        }

        return $metadata;
    }

    /**
     * The metadata keys the method's own docblock sets, by key.
     *
     * @return array<string, true>
     */
    protected function metadataSetByMethodDocBlock(ExtractedEndpointData $endpointData): array
    {
        $methodDocBlock = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route)['method'];
        $fromTags = (new GetFromDocBlocks($this->config))
            ->getMetadataFromDocBlock($methodDocBlock, new ParsedDocBlock(''));

        $keys = [];
        if (! is_null($fromTags['groupName'])) {
            $keys += ['groupName' => true, 'groupDescription' => true];
        }
        if (! is_null($fromTags['subgroup'])) {
            $keys += ['subgroup' => true, 'subgroupDescription' => true];
        }
        if (array_key_exists('authenticated', $fromTags)) {
            $keys['authenticated'] = true;
        }
        if ($fromTags['deprecated'] !== false) {
            $keys['deprecated'] = true;
        }

        return $keys;
    }
}
