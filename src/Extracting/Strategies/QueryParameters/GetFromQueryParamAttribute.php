<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting\Strategies\QueryParameters;

use Ipsocode\Scribe\Attributes\QueryParam;
use Ipsocode\Scribe\Extracting\Strategies\GetParamsFromAttributeStrategy;

/**
 * @extends GetParamsFromAttributeStrategy<QueryParam>
 */
class GetFromQueryParamAttribute extends GetParamsFromAttributeStrategy
{
    protected static array $attributeNames = [QueryParam::class];
}
