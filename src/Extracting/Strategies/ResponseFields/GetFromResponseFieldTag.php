<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\ResponseFields;

use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Extracting\Shared\ResponseFieldTools;
use Ipsocode\Scribe\Extracting\Strategies\GetFieldsFromTagStrategy;
use Ipsocode\Scribe\Extracting\Strategies\Responses\UseApiResourceTags;
use Ipsocode\Scribe\Tools\AnnotationParser as a;
use Ipsocode\Scribe\Tools\Utils as u;

class GetFromResponseFieldTag extends GetFieldsFromTagStrategy
{
    protected string $tagName = 'responseField';

    /**
     * Reads @responseField tags from the controller class and method, and from
     * the toArray() docblock of the resource named by the method's
     * `@apiResource` or `@apiResourceCollection` tag.
     */
    public function getFromTags(array $tagsOnMethod, array $tagsOnClass = []): array
    {
        $nonApiResourceFields = parent::getFromTags($tagsOnMethod, $tagsOnClass);
        $apiResourceFields = $this->getApiResourceFields($tagsOnMethod);

        return [...$nonApiResourceFields, ...$apiResourceFields];
    }

    public function getClassNameFromApiResourceTag(string $apiResourceTag): string
    {
        ['content' => $className] = a::parseIntoContentAndFields($apiResourceTag, UseApiResourceTags::apiResourceAllowedFields());

        return $className;
    }

    protected function parseTag(string $tagContent): array
    {
        // Format:
        // @responseField <name> <type> <"required" (optional)> <description>
        // Examples:
        // @responseField text string required The text.
        // @responseField user_id integer The ID of the user.
        preg_match('/(.+?)\s+(.+?)\s+(.+?)\s+([\s\S]*)/', $tagContent, $content);
        if (empty($content)) {
            // Fewer than four words: the first two are the name and type.
            [$name, $type] = preg_split('/\s+/', $tagContent);
            $description = '';
            $required = false;
        } else {
            [$_, $name, $type, $required, $description] = $content;
            if ($required !== 'required') {
                $description = $required . ' ' . $description;
            }

            $required = $required === 'required';
            $description = mb_trim($description);
        }

        $type = static::normalizeTypeName($type);
        $data = compact('name', 'type', 'required', 'description');

        // The type is optional, and may be nullable or a union (?string,
        // string|null). A word in its place that isn't a supported type belongs
        // to the description, and the type is inferred.
        if (! $this->isSupportedTypeInDocBlocks(explode('|', mb_trim($type, '?'))[0])) {
            $data['description'] = mb_trim("{$type} {$description}");
            $data['type'] = '';

            $data['type'] = ResponseFieldTools::inferTypeOfResponseField($data, $this->endpointData);
        }

        return $data;
    }

    protected function getApiResourceFields(array $tagsOnMethod): array
    {
        $apiResourceClassName = $this->getApiResourceClassName($tagsOnMethod);

        if (empty($apiResourceClassName)) {
            return [];
        }

        return $this->extractFieldsFromApiResource($apiResourceClassName);
    }

    protected function getApiResourceClassName(array $tagsOnMethod): ?string
    {
        $apiResourceTags = array_values(
            array_filter($tagsOnMethod, function ($tag) {
                return in_array(mb_strtolower($tag->getName()), ['apiresource', 'apiresourcecollection']);
            })
        );

        if (empty($apiResourceTags)) {
            return null;
        }

        return $this->getClassNameFromApiResourceTag($apiResourceTags[0]->getContent());
    }

    protected function extractFieldsFromApiResource(string $className): array
    {
        $method = u::getReflectedRouteMethod([$className, 'toArray']);
        $docBlock = RouteDocBlocker::forMethod($method);
        $tagsOnApiResource = $docBlock->getTags();

        $wrapKey = $className::$wrap ?? null;
        $fields = parent::getFromTags($tagsOnApiResource, []);

        return $this->applyWrapKeyPrefix($fields, $wrapKey);
    }

    protected function applyWrapKeyPrefix(array $fields, ?string $wrapKey): array
    {
        if ($wrapKey === null) {
            return $fields;
        }

        $wrappedFields = [];
        foreach ($fields as $fieldName => $fieldData) {
            $fieldData['name'] = $wrapKey . '.' . $fieldData['name'];
            $wrappedFields[$fieldData['name']] = $fieldData;
        }

        return $wrappedFields;
    }
}
