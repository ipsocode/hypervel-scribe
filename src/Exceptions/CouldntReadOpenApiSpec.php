<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;
use Throwable;

class CouldntReadOpenApiSpec extends RuntimeException implements ScribeException
{
    /**
     * Create the exception for a spec that does not exist.
     */
    public static function missing(string $path): self
    {
        return new self("The OpenAPI spec was not found at {$path}. Run `php artisan scribe:generate` with `openapi.enabled` on to create it.");
    }

    /**
     * Create the exception for a Postman collection that does not exist.
     */
    public static function missingCollection(string $path): self
    {
        return new self("The Postman collection was not found at {$path}. Run `php artisan scribe:generate` with `postman.enabled` on to create it.");
    }

    /**
     * Create the exception for a spec that is not a YAML mapping.
     */
    public static function unreadable(string $path, ?Throwable $previous = null): self
    {
        return new self("The OpenAPI spec at {$path} is not a YAML mapping. Run `php artisan scribe:generate` to regenerate it.", previous: $previous);
    }

    /**
     * Create the exception for a `$ref` the spec does not define.
     */
    public static function unresolvedReference(string $reference): self
    {
        return new self("The OpenAPI spec refers to {$reference}, which it does not define.");
    }
}
