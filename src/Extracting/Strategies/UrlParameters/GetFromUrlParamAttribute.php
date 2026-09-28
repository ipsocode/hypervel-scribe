<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\UrlParameters;

use Ipsocode\Scribe\Attributes\UrlParam;
use Ipsocode\Scribe\Extracting\Strategies\GetParamsFromAttributeStrategy;

/**
 * @extends GetParamsFromAttributeStrategy<UrlParam>
 */
class GetFromUrlParamAttribute extends GetParamsFromAttributeStrategy
{
    protected static array $attributeNames = [UrlParam::class];
}
