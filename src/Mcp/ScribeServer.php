<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Mcp;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Mcp\Enums\CacheScope;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Mcp\Server\Tool;
use Ipsocode\Scribe\Mcp\Prompts\IntegrateEndpointPrompt;
use Ipsocode\Scribe\Mcp\Resources\OpenApiSpecResource;
use Ipsocode\Scribe\Mcp\Resources\PostmanCollectionResource;
use Ipsocode\Scribe\Mcp\Tools\CallSandboxTool;
use Ipsocode\Scribe\Mcp\Tools\DescribeEndpointTool;
use Ipsocode\Scribe\Mcp\Tools\GetAuthenticationTool;
use Ipsocode\Scribe\Mcp\Tools\ListGroupsTool;
use Ipsocode\Scribe\Mcp\Tools\SearchEndpointsTool;
use Ipsocode\Scribe\Scribe;

class ScribeServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchEndpointsTool::class,
        ListGroupsTool::class,
        DescribeEndpointTool::class,
        GetAuthenticationTool::class,
        CallSandboxTool::class,
    ];

    /**
     * @var array<int, class-string<McpResource>>
     */
    protected array $resources = [
        OpenApiSpecResource::class,
        PostmanCollectionResource::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        IntegrateEndpointPrompt::class,
    ];

    /**
     * Create the server, named after the documented API.
     */
    public function __construct(Transport $transport, Repository $config)
    {
        parent::__construct($transport);

        $this->name = $config->get('scribe.title') ?: $config->string('app.name');
        $this->version = Scribe::version();
        $this->instructions = <<<'MARKDOWN'
            This server describes an API from its generated documentation. Search the endpoints first,
            then describe the one you need to read its parameters, bodies and responses, and get the
            authentication scheme and base URL before you write the client code.
        MARKDOWN;
    }

    /**
     * Get the cache hints for individual MCP methods.
     *
     * @return array<string, Cacheable>
     */
    protected function cacheHints(): array
    {
        return [
            'tools/list' => new Cacheable(ttlMs: 60_000, scope: CacheScope::Public),
        ];
    }
}
