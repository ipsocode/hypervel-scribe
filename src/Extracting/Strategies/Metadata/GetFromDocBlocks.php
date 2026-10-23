<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Metadata;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DocBlocks\ParsedDocBlock;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;

class GetFromDocBlocks extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route);
        $methodDocBlock = $docBlocks['method'];
        $classDocBlock = $docBlocks['class'];

        return $this->getMetadataFromDocBlock($methodDocBlock, $classDocBlock);
    }

    public function getMetadataFromDocBlock(ParsedDocBlock $methodDocBlock, ParsedDocBlock $classDocBlock): array
    {
        [$groupName, $groupDescription, $title] = $this->getEndpointGroupAndTitleDetails($methodDocBlock, $classDocBlock);

        $metadata = [
            'groupName' => $groupName,
            'groupDescription' => $groupDescription,
            'subgroup' => $this->getEndpointSubGroup($methodDocBlock, $classDocBlock),
            'subgroupDescription' => $this->getEndpointSubGroupDescription($methodDocBlock, $classDocBlock),
            'title' => $title ?: $methodDocBlock->getShortDescription(),
            'description' => $methodDocBlock->getLongDescription(),
            'deprecated' => $this->getDeprecatedStatusFromDocBlock($methodDocBlock, $classDocBlock),
        ];
        if (! is_null($authStatus = $this->getAuthStatusFromDocBlock($methodDocBlock, $classDocBlock))) {
            $metadata['authenticated'] = $authStatus;
        }

        return $metadata;
    }

    protected function getAuthStatusFromDocBlock(ParsedDocBlock $methodDocBlock, ?ParsedDocBlock $classDocBlock = null): ?bool
    {
        foreach ($methodDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'authenticated') {
                return true;
            }

            if (mb_strtolower($tag->getName()) === 'unauthenticated') {
                return false;
            }
        }

        return $classDocBlock
            ? $this->getAuthStatusFromDocBlock($classDocBlock)
            : null;
    }

    protected function getDeprecatedStatusFromDocBlock(ParsedDocBlock $methodDocBlock, ?ParsedDocBlock $classDocBlock = null): bool|string
    {
        foreach ($methodDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'deprecated') {
                return $tag->getContent() === '' ? true : $tag->getContent();
            }
        }

        if ($classDocBlock instanceof ParsedDocBlock) {
            return $this->getDeprecatedStatusFromDocBlock($classDocBlock);
        }

        return false;
    }

    /**
     * @return array The endpoint's group name, the group description, and the endpoint title
     */
    protected function getEndpointGroupAndTitleDetails(ParsedDocBlock $methodDocBlock, ParsedDocBlock $controllerDocBlock)
    {
        foreach ($methodDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'group') {
                $endpointGroupParts = explode("\n", mb_trim($tag->getContent()));
                $endpointGroupName = array_shift($endpointGroupParts);
                $endpointGroupDescription = mb_trim(implode("\n", $endpointGroupParts));

                // With no short description above it, the text under @group is the
                // endpoint title rather than the group description:
                // /**
                //  * Fetch cars.      <-- endpoint title
                //  * @group Cars      <-- group name
                //  * APIs for cars.   <-- group description (optional)
                //  */
                // versus
                // /**
                //  * @group Cars      <-- group name
                //  * Fetch cars.      <-- endpoint title
                //  */
                if (empty($methodDocBlock->getShortDescription())) {
                    return [$endpointGroupName, '', $endpointGroupDescription];
                }

                return [$endpointGroupName, $endpointGroupDescription, $methodDocBlock->getShortDescription()];
            }
        }

        // Fall back to the controller
        foreach ($controllerDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'group') {
                $endpointGroupParts = explode("\n", mb_trim($tag->getContent()));
                $endpointGroupName = array_shift($endpointGroupParts);
                $endpointGroupDescription = implode("\n", $endpointGroupParts);

                return [$endpointGroupName, $endpointGroupDescription, $methodDocBlock->getShortDescription()];
            }
        }

        return [null, '', $methodDocBlock->getShortDescription()];
    }

    protected function getEndpointSubGroup(ParsedDocBlock $methodDocBlock, ParsedDocBlock $controllerDocBlock): ?string
    {
        foreach ($methodDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'subgroup') {
                return mb_trim($tag->getContent());
            }
        }

        foreach ($controllerDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'subgroup') {
                return mb_trim($tag->getContent());
            }
        }

        return null;
    }

    protected function getEndpointSubGroupDescription(ParsedDocBlock $methodDocBlock, ParsedDocBlock $controllerDocBlock): ?string
    {
        foreach ($methodDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'subgroupdescription') {
                return mb_trim($tag->getContent());
            }
        }

        foreach ($controllerDocBlock->getTags() as $tag) {
            if (mb_strtolower($tag->getName()) === 'subgroupdescription') {
                return mb_trim($tag->getContent());
            }
        }

        return null;
    }
}
