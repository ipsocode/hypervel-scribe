<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Mcp\OpenApiDocument;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\CallSandboxTool;
use Ipsocode\Scribe\Mcp\Tools\DescribeEndpointTool;
use Ipsocode\Scribe\Mcp\Tools\SearchEndpointsTool;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Test;

class GeneratedSpecTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('.scribe'));
        File::deleteDirectory($this->app->viewPath('scribe'));
        File::deleteDirectory($this->app->publicPath('vendor/scribe'));
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

    #[Test]
    #[WithConfig('scribe.mcp.sandbox', ['enabled' => true, 'mode' => 'examples', 'base_url' => null, 'timeout' => 10.0, 'max_response_bytes' => 65536, 'methods' => ['GET']])]
    public function theSandboxAnswersFromTheExamplesTheWriterPutsInTheSpec(): void
    {
        $this->artisan('scribe:generate')->assertExitCode(0)->run();

        $this->assertEverySandboxCallAnswers();
    }

    #[Test]
    #[WithConfig('scribe.openapi.version', '3.1.0')]
    #[WithConfig('scribe.mcp.sandbox', ['enabled' => true, 'mode' => 'examples', 'base_url' => null, 'timeout' => 10.0, 'max_response_bytes' => 65536, 'methods' => ['GET']])]
    public function theSandboxAnswersFromTheExamplesOfASpecInVersion31(): void
    {
        $this->artisan('scribe:generate')->assertExitCode(0)->run();

        $this->assertEverySandboxCallAnswers();
    }

    private function assertEverySandboxCallAnswers(): void
    {
        $operations = $this->app->make(OpenApiDocument::class)->operations();

        $answered = 0;

        foreach ($operations as $operation) {
            $operation = $this->app->make(OpenApiDocument::class)->operation($operation['id']);
            if (collect(array_keys($operation['responses'] ?? []))->doesntContain(fn (int|string $status): bool => $status >= 200 && $status < 300)) {
                continue;
            }

            ++$answered;
            $inputs = ['path' => 'pathParams', 'query' => 'query', 'header' => 'headers'];
            $arguments = ['operationId' => $operation['operationId'], 'body' => ['name' => 'x']];

            foreach ($operation['parameters'] ?? [] as $parameter) {
                $arguments[$inputs[$parameter['in']]][$parameter['name']] = '1';
            }

            ScribeServer::tool(CallSandboxTool::class, $arguments)->assertOk();
        }

        $this->assertGreaterThan(10, $answered);
    }
}
