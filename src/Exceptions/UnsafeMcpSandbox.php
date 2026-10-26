<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;

class UnsafeMcpSandbox extends RuntimeException implements ScribeException
{
    public static function productionUrl(string $url): self
    {
        return new self("The MCP sandbox would call {$url}, the production application. Set `scribe.mcp.sandbox.base_url` to a sandbox, or use the `examples` mode.");
    }
}
