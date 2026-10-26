<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\ListGroupsTool;
use Ipsocode\Scribe\Mcp\Tools\SearchEndpointsTool;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Coroutine\parallel;

class ScribeServerTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/openapi.yaml';

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('scribe');

        parent::tearDown();
    }

    private function writeSpec(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', (string) file_get_contents(self::FIXTURE));
    }

    private function server(): ScribeServer
    {
        return $this->app->make(ScribeServer::class, ['transport' => m::mock(Transport::class)]);
    }

    private function context(): ServerContext
    {
        return $this->server()->createContext();
    }

    #[Test]
    #[WithConfig('scribe.title', 'Acme API')]
    public function takesItsNameVersionAndInstructionsFromTheApplication(): void
    {
        $context = $this->context();

        $this->assertSame('Acme API', $context->implementation->name);
        $this->assertSame(Scribe::version(), $context->implementation->version);
        $this->assertStringContainsString('Search the endpoints first', $context->instructions);
    }

    #[Test]
    #[WithConfig('scribe.title', null)]
    #[WithConfig('app.name', 'Workbench')]
    public function fallsBackToTheApplicationNameWithoutATitle(): void
    {
        $this->assertSame('Workbench', $this->context()->implementation->name);
    }

    #[Test]
    public function advertisesEveryTool(): void
    {
        $tools = (fn (): array => $this->tools)->call($this->server());

        $this->assertSame(
            ['search-endpoints', 'list-groups', 'describe-endpoint', 'get-authentication', 'call-sandbox'],
            array_map(fn (string $tool): string => $this->app->make($tool)->name(), $tools),
        );
    }

    #[Test]
    public function describesEveryToolAsReadOnlyWithItsInputAndOutput(): void
    {
        $tools = (fn (): array => $this->tools)->call($this->server());

        foreach ($tools as $tool) {
            $definition = $this->app->make($tool)->toArray();

            $this->assertTrue($definition['annotations']['readOnlyHint'], $definition['name']);
            $this->assertNotEmpty($definition['description'], $definition['name']);
            $this->assertArrayHasKey('outputSchema', $definition, $definition['name']);
        }

        $search = $this->app->make(SearchEndpointsTool::class)->toArray();

        $this->assertSame(['query'], $search['inputSchema']['required']);
        $this->assertSame(['query', 'group', 'method'], array_keys($search['inputSchema']['properties']));
    }

    #[Test]
    public function hintsThatTheToolListCanBeCachedByEveryone(): void
    {
        $hints = (fn (): array => $this->cacheHints())->call($this->server());

        $this->assertSame(['ttlMs' => 60_000, 'cacheScope' => 'public'], $hints['tools/list']->toArray());
    }

    #[Test]
    public function answersConcurrentCallsToDifferentToolsEachWithItsOwnResults(): void
    {
        $this->writeSpec();

        [$search, $groups] = parallel([
            function (): array {
                $response = ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'health']);
                usleep(5000);

                return [$response];
            },
            function (): array {
                $response = ScribeServer::tool(ListGroupsTool::class);
                usleep(5000);

                return [$response];
            },
        ]);

        $search[0]->assertStructuredContent(fn ($json) => $json->has('endpoints', 1)->etc());
        $groups[0]->assertStructuredContent(fn ($json) => $json->has('groups', 2)->etc());
    }
}
