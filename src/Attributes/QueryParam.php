<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class QueryParam extends GenericParam
{
}
