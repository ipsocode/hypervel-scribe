<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;

class CouldntFindFactory extends RuntimeException implements ScribeException
{
    public static function forModel(string $modelName): self
    {
        return new self("Couldn't find the Eloquent model factory. Did you add the HasFactory trait to your {$modelName} model?");
    }
}
