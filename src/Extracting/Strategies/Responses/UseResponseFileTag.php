<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Responses;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Shared\ResponseFileTools;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\AnnotationParser as a;
use Ipsocode\Scribe\Tools\Utils;

/**
 * Reads responses from the files named in @responseFile tags.
 */
class UseResponseFileTag extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($endpointData->route);

        return $this->getFileResponses($docBlocks['method']->getTags());
    }

    /**
     * @param Tag[] $tags
     */
    public function getFileResponses(array $tags): ?array
    {
        $responseFileTags = Utils::filterDocBlockTags($tags, 'responsefile');

        if (empty($responseFileTags)) {
            return null;
        }

        return array_map(function (Tag $responseFileTag) {
            preg_match('/^(\d{3})?\s*(.*?)({.*})?$/', $responseFileTag->getContent(), $result);
            [$_, $status, $mainContent] = $result;
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
