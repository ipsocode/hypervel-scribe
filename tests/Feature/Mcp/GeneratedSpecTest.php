<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\DescribeEndpointTool;
use Ipsocode\Scribe\Mcp\Tools\SearchEndpointsTool;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

class GeneratedSpecTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('.scribe'));
        Storage::disk('local')->deleteDirectory('scribe');

        parent::tearDown();
    }

    #[Test]
    public function theToolsAnswerFromWhatGenerateWroteForTheWorkbenchApp(): void
    {
        $this->artisan('scribe:generate')->assertExitCode(0)->run();

        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'posts'])
            ->assertOk()
            ->assertSee('/api/posts');

        ScribeServer::tool(DescribeEndpointTool::class, ['method' => 'GET', 'path' => '/api/users/{id}'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('operation.method', 'get')
                ->where('operation.path', '/api/users/{id}')
                ->etc());
    }
}
