<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\DocBlocks\DocBlockTag;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Shared\ResponseFileTools;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Tools\AnnotationParser as a;
use Ipsocode\Scribe\Tools\Utils;

/**
 * Reads responses from the files named in @responseFile tags.
 */
class UseResponseFileTag extends Strategy
{
    /**
     * @param array<string, mixed> $routeRules
     * @return null|array<int, array{content: string, status: int, description: string}>
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route);

        return $this->getFileResponses($docBlocks['method']->getTags());
    }

    /**
     * @param DocBlockTag[] $tags
     * @return null|array<int, array{content: string, status: int, description: string}>
     */
    public function getFileResponses(array $tags): ?array
    {
        $responseFileTags = Utils::filterDocBlockTags($tags, 'responsefile');

        if (empty($responseFileTags)) {
            return null;
        }

        return array_map(function (DocBlockTag $responseFileTag) {
            preg_match('/^(\d{3})?\s*(.*?)({.*})?$/', $responseFileTag->getContent(), $result);
            $status = $result[1]; // @phpstan-ignore offsetAccess.notFound (the pattern matches any string, so the group is set)
            $mainContent = $result[2]; // @phpstan-ignore offsetAccess.notFound (the pattern matches any string, so the group is set)
            $json = $result[3] ?? null;

            ['fields' => $fields, 'content' => $filePath] = a::parseIntoContentAndFields($mainContent, ['status', 'scenario']);

            $status = $fields['status'] ?: ($status ?: 200);
            $description = $fields['scenario'] ?: '';
            $content = ResponseFileTools::getResponseContents($filePath, $json);

            return [
                'content' => $content,
                'status' => (int) $status,
                'description' => $description,
            ];
        }, $responseFileTags);
    }
}
