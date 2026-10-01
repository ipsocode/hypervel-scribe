<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\ResponseFields;

use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Attributes\ResponseField;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Ipsocode\Scribe\Extracting\Shared\ResponseFieldTools;
use Ipsocode\Scribe\Extracting\Strategies\PhpAttributeStrategy;
use Ipsocode\Scribe\Tools\Utils as u;
use ReflectionAttribute;

/**
 * @extends PhpAttributeStrategy<ResponseField>
 */
class GetFromResponseFieldAttribute extends PhpAttributeStrategy
{
    protected static array $attributeNames = [ResponseField::class];

    protected function extractFromAttributes(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest = [],
        array $attributesOnController = [],
    ): ?array {
        return [
            ...$this->getNonApiResourceFields($endpointData, $attributesOnMethod, $attributesOnFormRequest, $attributesOnController),
            ...$this->getApiResourceFields($endpointData),
        ];
    }

    protected function getApiResourceFields(ExtractedEndpointData $endpointData): array
    {
        $apiResourceAttributes = $endpointData->method->getAttributes(ResponseFromApiResource::class);

        return collect($apiResourceAttributes)
            ->flatMap(fn (ReflectionAttribute $attribute) => $this->extractFieldsFromApiResource($attribute, $endpointData))
            ->toArray();
    }

    protected function extractFieldsFromApiResource(ReflectionAttribute $attribute, ExtractedEndpointData $endpointData): array
    {
        $className = $attribute->newInstance()->name;
        $method = u::getReflectedRouteMethod([$className, 'toArray']);
        $wrapKey = $className::$wrap ?? null;

        return collect($method->getAttributes(ResponseField::class))
            ->mapWithKeys(function (ReflectionAttribute $attr) use ($endpointData, $wrapKey) {
                $data = $attr->newInstance()->toArray();
                $data['type'] = ResponseFieldTools::inferTypeOfResponseField($data, $endpointData);

                if ($wrapKey !== null) {
                    $data['name'] = $wrapKey . '.' . $data['name'];
                }

                return [$data['name'] => $data];
            })->toArray();
    }

    protected function getNonApiResourceFields(
        ExtractedEndpointData $endpointData,
        array $attributesOnMethod,
        array $attributesOnFormRequest,
        array $attributesOnController,
    ): array {
        return collect([...$attributesOnController, ...$attributesOnFormRequest, ...$attributesOnMethod])
            ->mapWithKeys(function ($attributeInstance) use ($endpointData) {
                /** @var ResponseField $attributeInstance */
                $data = $attributeInstance->toArray();

                $data['type'] = ResponseFieldTools::inferTypeOfResponseField($data, $endpointData);

                return [$data['name'] => $data];
            })->toArray();
    }
}
