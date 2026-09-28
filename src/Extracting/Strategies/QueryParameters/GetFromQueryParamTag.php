<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\QueryParameters;

use Hypervel\Support\Str;
use Ipsocode\Scribe\Extracting\Strategies\GetFieldsFromTagStrategy;

class GetFromQueryParamTag extends GetFieldsFromTagStrategy
{
    protected string $tagName = 'queryParam';

    public function parseTag(string $tagContent): array
    {
        // Format:
        // @queryParam <name> <type (optional)> <"required" (optional)> <"deprecated" (optional)> <description>
        // Examples:
        // @queryParam text required The text.
        // @queryParam user_id integer The ID of the user.
        // @queryParam sort string deprecated Use `order` parameter instead.
        preg_match('/(.+?)\s+([a-zA-Z\[\]]+\s+)?(required\s+)?(deprecated\s+)?([\s\S]*)/', $tagContent, $content);

        if (empty($content)) {
            // This means only name was supplied
            $name = $tagContent;
            $required = false;
            $deprecated = false;
            $description = '';
            $type = 'string';
        } else {
            [$_, $name, $type, $required, $deprecated, $description] = $content;

            $description = mb_trim(str_replace(['No-example.', 'No-example'], '', $description));
            if ($description === 'required') {
                // No description was supplied
                $required = $description;
                $description = '';
            } elseif ($description === 'deprecated') {
                $deprecated = $description;
                $description = '';
            }

            // Normalised unconditionally — the branches above each set one flag
            // and left the other as its raw regex capture, so a tag like
            // `@queryParam a required` reached Parameter::$deprecated (typed
            // bool) as a string.
            $required = mb_trim((string) $required) === 'required';
            $deprecated = mb_trim((string) $deprecated) === 'deprecated';

            $type = mb_trim($type);
            if ($type) {
                if ($type === 'required') {
                    // Type wasn't supplied
                    $type = 'string';
                    $required = true;
                } elseif ($type === 'deprecated') {
                    // Type wasn't supplied but deprecated was
                    $type = 'string';
                    $deprecated = true;
                } else {
                    $type = static::normalizeTypeName($type);
                    // Type in annotation is optional
                    if (! $this->isSupportedTypeInDocBlocks($type)) {
                        // Then that wasn't a type, but part of the description
                        $description = mb_trim("{$type} {$description}");
                        $type = '';
                    }
                }
            } elseif ($this->isSupportedTypeInDocBlocks($description)) {
                // Only type was supplied
                $type = $description;
                $description = '';
            }

            $type = empty($type)
                ? (Str::contains(mb_strtolower($description), ['number', 'count', 'page']) ? 'integer' : 'string')
                : static::normalizeTypeName($type);
        }

        [$description, $example, $enumValues, $exampleWasSpecified]
            = $this->getDescriptionAndExample($description, $type, $tagContent, $name);

        return compact('name', 'description', 'required', 'deprecated', 'example', 'type', 'enumValues', 'exampleWasSpecified');
    }
}
