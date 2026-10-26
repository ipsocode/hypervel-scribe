<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\CallSandboxTool;
use Ipsocode\Scribe\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('scribe.mcp.sandbox.enabled', true)]
class SandboxToolTest extends TestCase
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

    /**
     * @return array<int, string>
     */
    private function tools(): array
    {
        $server = $this->app->make(ScribeServer::class, ['transport' => m::mock(Transport::class)]);

        return (fn (): array => $this->tools)->call($server);
    }

    #[Test]
    #[WithConfig('scribe.mcp.sandbox.enabled', false)]
    public function isAbsentUnlessTheSandboxIsEnabled(): void
    {
        $this->assertNotContains(CallSandboxTool::class, $this->tools());
    }

    #[Test]
    public function isAdvertisedWhenTheSandboxIsEnabled(): void
    {
        $this->assertContains(CallSandboxTool::class, $this->tools());
        $this->assertSame('call-sandbox', $this->app->make(CallSandboxTool::class)->name());
    }

    #[Test]
    public function returnsTheFirstDocumentedSuccessExampleByDefault(): void
    {
        $this->writeSpec();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])
            ->assertOk()
            ->assertSee('Ada')
            ->assertStructuredContent(fn ($json) => $json->where('status', 200)->has('example')->etc());
    }

    #[Test]
    public function returnsTheExampleOfTheRequestedStatus(): void
    {
        $this->writeSpec(<<<'YAML'
            openapi: 3.0.3
            paths:
              /api/orders:
                get:
                  operationId: listOrders
                  responses:
                    200:
                      content:
                        application/json:
                          example: { ok: true }
                    404:
                      content:
                        application/json:
                          examples:
                            missing: { value: { message: 'No orders.' } }
            YAML);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listOrders', 'status' => 404])
            ->assertOk()
            ->assertSee('No orders.')
            ->assertStructuredContent(fn ($json) => $json->where('status', 404)->etc());
    }

    #[Test]
    public function returnsAToolErrorForAStatusWithNoExample(): void
    {
        $this->writeSpec();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'createUser', 'body' => ['name' => 'Ada']])
            ->assertHasErrors(['no example for 201']);
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'getApiHealth'])
            ->assertHasErrors(['no example for 200']);
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers', 'status' => 500])
            ->assertHasErrors(['no response for 500']);
    }

    #[Test]
    public function returnsAToolErrorWhenTheEndpointDocumentsNoSuccessStatus(): void
    {
        $this->writeSpec(<<<'YAML'
            openapi: 3.0.3
            paths:
              /api/orders:
                get:
                  operationId: listOrders
                  responses:
                    500:
                      description: ''
            YAML);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listOrders'])
            ->assertHasErrors(['no response for a 2xx status']);
    }

    #[Test]
    public function returnsAToolErrorForAnUnknownOperation(): void
    {
        $this->writeSpec();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'nothing'])->assertHasErrors(['search-endpoints']);
    }

    #[Test]
    public function returnsAToolErrorForMissingRequiredPathParametersAndBody(): void
    {
        $this->writeSpec();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'showUser'])
            ->assertHasErrors(['path parameter id']);
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'createUser'])
            ->assertHasErrors(['the request body']);
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'showUser', 'pathParams' => ['id' => 1]])
            ->assertOk();
    }

    #[Test]
    public function returnsAToolErrorForAMissingRequiredQueryOrHeader(): void
    {
        $this->writeSpec(<<<'YAML'
            openapi: 3.0.3
            paths:
              /api/orders:
                get:
                  operationId: listOrders
                  parameters:
                    - { in: query, name: page, required: true }
                    - { in: header, name: X-Tenant, required: true }
                    - { in: cookie, name: session, required: true }
                    - { in: query, name: sort }
                  responses:
                    200:
                      content:
                        application/json:
                          example: []
            YAML);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listOrders'])
            ->assertHasErrors(['query parameter page', 'header parameter X-Tenant']);
        ScribeServer::tool(CallSandboxTool::class, [
            'operationId' => 'listOrders',
            'query' => ['page' => 1],
            'headers' => ['X-Tenant' => 'acme'],
        ])->assertOk();
    }

    #[Test]
    public function returnsAToolErrorWhenTheSpecIsMissing(): void
    {
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])->assertHasErrors(['scribe:generate']);
    }

    #[Test]
    public function declaresItsInputAndOutput(): void
    {
        $definition = $this->app->make(CallSandboxTool::class)->toArray();

        $this->assertSame(['operationId'], $definition['inputSchema']['required']);
        $this->assertSame(
            ['operationId', 'pathParams', 'query', 'headers', 'body', 'status'],
            array_keys($definition['inputSchema']['properties']),
        );
        $this->assertSame(['status', 'example'], $definition['outputSchema']['required']);
        $this->assertTrue($definition['annotations']['readOnlyHint']);
    }
}
