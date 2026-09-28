<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\BodyParameters;

use Ipsocode\Scribe\Attributes\BodyParam;
use Ipsocode\Scribe\Extracting\Strategies\GetParamsFromAttributeStrategy;

/**
 * @extends GetParamsFromAttributeStrategy<BodyParam>
 */
class GetFromBodyParamAttribute extends GetParamsFromAttributeStrategy
{
    protected static array $attributeNames = [BodyParam::class];
}
