<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DocBlocks\DocBlockTag;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Tools\AnnotationParser as a;
use Ipsocode\Scribe\Tools\Utils;

/**
 * Reads responses from @response tags.
 */
class UseResponseTag extends Strategy
{
    /**
     * @param array<string, mixed> $routeRules
     * @return null|array<int, array{content: string, status: int, description: string}>
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route);

        return $this->getDocBlockResponses($docBlocks['method']->getTags());
    }

    /**
     * @param DocBlockTag[] $tags
     * @return null|array<int, array{content: string, status: int, description: string}>
     */
    public function getDocBlockResponses(array $tags): ?array
    {
        $responseTags = Utils::filterDocBlockTags($tags, 'response');

        if (empty($responseTags)) {
            return null;
        }

        return array_map(function (DocBlockTag $responseTag) {
            // An optional status code, then the response.
            preg_match('/^(\d{3})?\s?([\s\S]*)$/', $responseTag->getContent(), $result);

            // @phpstan-ignore offsetAccess.notFound (the pattern matches any string, so both groups are set)
            $status = $result[1] ?: 200;
            $content = $result[2] ?: '{}';

            ['fields' => $fields, 'content' => $content] = a::parseIntoContentAndFields($content, ['status', 'scenario']);

            $status = $fields['status'] ?: $status;
            $description = $fields['scenario'] ?: '';

            return ['content' => $content, 'status' => (int) $status, 'description' => $description];
        }, $responseTags);
    }
}
