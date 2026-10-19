<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
class Unauthenticated
{
    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return ['authenticated' => false];
    }
}
