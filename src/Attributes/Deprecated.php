<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Deprecated
{
    public function __construct(
        public bool|string|null $deprecated = true,
    ) {
    }

    public function toArray()
    {
        return ['deprecated' => $this->deprecated];
    }
}
