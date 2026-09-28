<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;
use Throwable;

class ProblemParsingValidationRules extends RuntimeException implements ScribeException
{
    public static function forParam(string $paramName, Throwable $innerException): self
    {
        return new self(
            "Problem processing validation rules for the param `{$paramName}`: {$innerException->getMessage()}",
            0,
            $innerException
        );
    }
}
