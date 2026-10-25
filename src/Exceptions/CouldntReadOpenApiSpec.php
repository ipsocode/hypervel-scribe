<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;

class CouldntReadOpenApiSpec extends RuntimeException implements ScribeException
{
    public static function missing(string $path): self
    {
        return new self("The OpenAPI spec was not found at {$path}. Run `php artisan scribe:generate` with `openapi.enabled` on to create it.");
    }

    public static function missingCollection(string $path): self
    {
        return new self("The Postman collection was not found at {$path}. Run `php artisan scribe:generate` with `postman.enabled` on to create it.");
    }

    public static function unreadable(string $path): self
    {
        return new self("The OpenAPI spec at {$path} is not a YAML mapping. Run `php artisan scribe:generate` to regenerate it.");
    }

    public static function unresolvedReference(string $reference): self
    {
        return new self("The OpenAPI spec refers to {$reference}, which it does not define.");
    }
}
