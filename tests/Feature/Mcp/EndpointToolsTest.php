<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\DescribeEndpointTool;
use Ipsocode\Scribe\Mcp\Tools\GetAuthenticationTool;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EndpointToolsTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/openapi.yaml';

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('scribe');

        parent::tearDown();
    }

    private function writeSpec(?string $contents = null): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', $contents ?? (string) file_get_contents(self::FIXTURE));
    }

    #[Test]
    public function describesAnEndpointByOperationIdWithItsReferencesResolved(): void
    {
        $this->writeSpec();

        ScribeServer::tool(DescribeEndpointTool::class, ['operationId' => 'createUser'])
            ->assertOk()
            ->assertSee(['operationId: createUser', 'city:'])
            ->assertStructuredContent(function ($json): void {
                $json->where('operation.operationId', 'createUser')
                    ->where('operation.method', 'post')
                    ->where('operation.path', '/api/users')
                    ->where('operation.requestBody.content.application/json.schema.properties.address.properties.city.type', 'string')
                    ->etc();
            });
    }

    #[Test]
    public function declaresAnOutputSchemaTheStructuredContentSatisfies(): void
    {
        $schema = $this->app->make(DescribeEndpointTool::class)->toArray()['outputSchema'];

        $this->assertSame(['operation'], $schema['required']);
        $this->assertSame(['operationId', 'method', 'path'], $schema['properties']['operation']['required']);
    }

    #[Test]
    public function describesAnEndpointByMethodAndPath(): void
    {
        $this->writeSpec();

        ScribeServer::tool(DescribeEndpointTool::class, ['method' => 'GET', 'path' => '/api/users/{id}'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('operation.operationId', 'showUser')->etc());
    }

    #[Test]
    public function returnsAToolErrorForAnUnknownEndpoint(): void
    {
        $this->writeSpec();

        ScribeServer::tool(DescribeEndpointTool::class, ['operationId' => 'nothing'])->assertHasErrors(['search-endpoints']);
        ScribeServer::tool(DescribeEndpointTool::class, ['method' => 'get', 'path' => '/nothing'])->assertHasErrors(['search-endpoints']);
    }

    #[Test]
    public function asksForAnOperationIdOrAMethodAndPath(): void
    {
        $this->writeSpec();

        ScribeServer::tool(DescribeEndpointTool::class)->assertHasErrors();
        ScribeServer::tool(DescribeEndpointTool::class, ['method' => 'get'])->assertHasErrors();
    }

    #[Test]
    public function returnsAToolErrorWhenTheSpecIsMissing(): void
    {
        ScribeServer::tool(DescribeEndpointTool::class, ['operationId' => 'listUsers'])->assertHasErrors(['scribe:generate']);
    }

    #[Test]
    #[WithConfig('scribe.auth.extra_info', 'Generate a token on your dashboard.')]
    #[WithConfig('scribe.auth.placeholder', '{YOUR_AUTH_KEY}')]
    public function describesTheSchemesServersAndInstructions(): void
    {
        $this->writeSpec();

        ScribeServer::tool(GetAuthenticationTool::class)
            ->assertOk()
            ->assertStructuredContent(function ($json): void {
                $json->where('schemes.default.scheme', 'bearer')
                    ->where('schemes.key.name', 'X-Api-Key')
                    ->where('requirements', [['default' => []]])
                    ->where('servers', [['url' => 'https://api.example.test']])
                    ->where('instructions', 'Generate a token on your dashboard.')
                    ->where('placeholder', '{YOUR_AUTH_KEY}');
            });
    }

    #[Test]
    #[WithConfig('scribe.auth.extra_info', null)]
    #[WithConfig('scribe.auth.placeholder', null)]
    public function describesASpecWithNoSecurity(): void
    {
        $this->writeSpec("openapi: 3.0.3\n");

        ScribeServer::tool(GetAuthenticationTool::class)
            ->assertOk()
            ->assertStructuredContent(function ($json): void {
                $json->where('schemes', [])
                    ->where('requirements', [])
                    ->where('servers', [])
                    ->where('instructions', '')
                    ->where('placeholder', '');
            });
    }

    #[Test]
    #[WithConfig('scribe.auth.use_value', 'secret-token-value')]
    public function neverRevealsTheValueScribeUsesForResponseCalls(): void
    {
        $this->writeSpec();

        ScribeServer::tool(GetAuthenticationTool::class)->assertOk()->assertDontSee('secret-token-value');
    }

    #[Test]
    public function returnsAToolErrorForAMissingSpecWhenAskedForAuthentication(): void
    {
        ScribeServer::tool(GetAuthenticationTool::class)->assertHasErrors(['scribe:generate']);
    }
}
