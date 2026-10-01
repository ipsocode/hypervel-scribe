<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\Headers;

use Ipsocode\Scribe\Extracting\ParamHelpers;
use Ipsocode\Scribe\Extracting\Strategies\TagStrategyWithFormRequestFallback;
use Ipsocode\Scribe\Reflection\DocBlock\Tag;
use Ipsocode\Scribe\Tools\Utils;

class GetFromHeaderTag extends TagStrategyWithFormRequestFallback
{
    use ParamHelpers;

    /**
     * @param Tag[] $tagsOnMethod
     * @param Tag[] $tagsOnClass
     */
    public function getFromTags(array $tagsOnMethod, array $tagsOnClass = []): array
    {
        $headerTags = Utils::filterDocBlockTags([...$tagsOnClass, ...$tagsOnMethod], 'header');
        $headers = collect($headerTags)->mapWithKeys(function (Tag $tag) {
            // Format:
            // @header <name> <example>
            // Examples:
            // @header X-Custom An API header
            preg_match('/([\S]+)(.*)?/', $tag->getContent(), $content);

            [$_, $name, $example] = $content;
            $example = mb_trim($example);
            if (empty($example)) {
                $example = $this->generateDummyValue('string');
            }

            return [$name => $example];
        })->toArray();

        return $headers;
    }
}
