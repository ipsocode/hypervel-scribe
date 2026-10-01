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
            // Just a name.
            $name = $tagContent;
            $required = false;
            $deprecated = false;
            $description = '';
            $type = 'string';
        } else {
            [$_, $name, $type, $required, $deprecated, $description] = $content;

            $description = mb_trim(str_replace(['No-example.', 'No-example'], '', $description));
            if ($description === 'required') {
                $required = $description;
                $description = '';
            } elseif ($description === 'deprecated') {
                $deprecated = $description;
                $description = '';
            }

            // Each branch above sets one flag and leaves the other as its raw
            // regex capture, a string, so both are normalised to bool here.
            $required = mb_trim((string) $required) === 'required';
            $deprecated = mb_trim((string) $deprecated) === 'deprecated';

            $type = mb_trim($type);
            if ($type) {
                if ($type === 'required') {
                    // No type, just "required".
                    $type = 'string';
                    $required = true;
                } elseif ($type === 'deprecated') {
                    // No type, just "deprecated".
                    $type = 'string';
                    $deprecated = true;
                } else {
                    $type = static::normalizeTypeName($type);
                    // The type is optional: a word in its place that isn't
                    // a supported type belongs to the description.
                    if (! $this->isSupportedTypeInDocBlocks($type)) {
                        $description = mb_trim("{$type} {$description}");
                        $type = '';
                    }
                }
            } elseif ($this->isSupportedTypeInDocBlocks($description)) {
                // What was read as the description is the type.
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
