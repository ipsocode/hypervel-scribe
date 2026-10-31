<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;

class UnsafeMcpSandbox extends RuntimeException implements ScribeException
{
    /**
     * Create the exception for a live sandbox with no URL of its own.
     */
    public static function missingBaseUrl(): self
    {
        return new self('The MCP sandbox in `http` mode calls only `scribe.mcp.sandbox.base_url`, which is not set. Point it at a sandbox, or use the `examples` mode.');
    }

    /**
     * Create the exception for a live sandbox that would call the production application.
     */
    public static function productionUrl(string $url): self
    {
        return new self("The MCP sandbox would call {$url}, the production application. Set `scribe.mcp.sandbox.base_url` to a sandbox, or use the `examples` mode.");
    }
}
