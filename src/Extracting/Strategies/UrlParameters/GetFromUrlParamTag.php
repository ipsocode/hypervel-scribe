<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\UrlParameters;

use Hypervel\Support\Str;
use Ipsocode\Scribe\Extracting\Strategies\GetFieldsFromTagStrategy;

class GetFromUrlParamTag extends GetFieldsFromTagStrategy
{
    protected string $tagName = 'urlParam';

    protected function parseTag(string $tagContent): array
    {
        // Format:
        // @urlParam <name> <type (optional)> <"required" (optional)> <description>
        // Examples:
        // @urlParam id string required The id of the post.
        // @urlParam user_id The ID of the user.

        // The type alternation lists every type a URL parameter can have, so
        // any other word in the type's place stays in the description.
        preg_match('/(\w+?)\s+((int|integer|string|float|double|number)\s+)?(required\s+)?([\s\S]*)/', $tagContent, $content);
        if (empty($content)) {
            // Just a name.
            $name = $tagContent;
            $required = false;
            $description = '';
            $type = 'string';
        } else {
            [$_, $name, $__, $type, $required, $description] = $content;
            $description = mb_trim(str_replace(['No-example.', 'No-example'], '', $description));
            if ($description === 'required') {
                $required = true;
                $description = '';
            } else {
                $required = mb_trim($required) === 'required';
            }

            if (empty($type) && $this->isSupportedTypeInDocBlocks($description)) {
                // What was read as the description is the type.
                $type = $description;
                $description = '';
            }

            $type = empty($type)
                ? (Str::contains($description, ['number', 'count', 'page']) ? 'integer' : 'string')
                : static::normalizeTypeName($type);
        }

        [$description, $example, $enumValues, $exampleWasSpecified]
            = $this->getDescriptionAndExample($description, $type, $tagContent, $name);

        return compact('name', 'description', 'required', 'example', 'type', 'enumValues', 'exampleWasSpecified');
    }
}
