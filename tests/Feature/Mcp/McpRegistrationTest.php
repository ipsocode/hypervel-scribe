<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class McpRegistrationTest extends TestCase
{
    #[Test]
    public function registersNeitherServerByDefault(): void
    {
        $this->assertFalse(config('scribe.mcp.enabled'));
        $this->assertNull(Mcp::getWebServer('docs/mcp'));
        $this->assertNull(Mcp::getLocalServer('scribe'));
    }

    #[Test]
    #[WithConfig('scribe.mcp.enabled', true)]
    #[WithConfig('scribe.mcp.middleware', ['throttle:60,1'])]
    public function registersTheWebAndLocalServersWhenEnabled(): void
    {
        $route = Mcp::getWebServer('docs/mcp');

        $this->assertNotNull($route);
        $this->assertSame('scribe.mcp', $route->getName());
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
        $this->assertNotNull(Mcp::getLocalServer('scribe'));
    }

    #[Test]
    #[WithConfig('scribe.mcp.enabled', true)]
    #[WithConfig('scribe.mcp.path', '/api-docs/mcp')]
    #[WithConfig('scribe.mcp.local_name', 'acme-docs')]
    public function usesTheConfiguredPathAndLocalName(): void
    {
        $this->assertNotNull(Mcp::getWebServer('api-docs/mcp'));
        $this->assertNull(Mcp::getWebServer('docs/mcp'));
        $this->assertNotNull(Mcp::getLocalServer('acme-docs'));
        $this->assertNull(Mcp::getLocalServer('scribe'));
    }
}
