<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\Storage;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\ListGroupsTool;
use Ipsocode\Scribe\Mcp\Tools\SearchEndpointsTool;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DiscoveryToolsTest extends TestCase
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

    #[Test]
    public function searchesEveryWordInThePathSummaryDescriptionAndGroup(): void
    {
        $this->writeSpec();

        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'users'])->assertOk()->assertStructuredContent([
            'endpoints' => [
                ['operationId' => 'listUsers', 'method' => 'get', 'path' => '/api/users', 'summary' => 'List users.', 'group' => 'Users'],
                ['operationId' => 'createUser', 'method' => 'post', 'path' => '/api/users', 'summary' => 'Create a user.', 'group' => 'Users'],
                ['operationId' => 'showUser', 'method' => 'get', 'path' => '/api/users/{id}', 'summary' => 'Show a user.', 'group' => 'Users'],
            ],
        ]);
        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'every user'])
            ->assertStructuredContent(fn ($json) => $json->has('endpoints', 1)->etc());
        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'user every nothing'])
            ->assertStructuredContent(['endpoints' => []]);
    }

    #[Test]
    public function narrowsTheSearchByGroupAndMethod(): void
    {
        $this->writeSpec();

        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'api', 'group' => 'Endpoints'])
            ->assertStructuredContent(fn ($json) => $json->has('endpoints', 1)->where('endpoints.0.operationId', 'getApiHealth')->etc());
        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'api', 'method' => 'POST'])
            ->assertStructuredContent(fn ($json) => $json->has('endpoints', 1)->where('endpoints.0.operationId', 'createUser')->etc());
        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'api', 'group' => 'Users', 'method' => 'delete'])
            ->assertStructuredContent(['endpoints' => []]);
    }

    #[Test]
    public function asksForAQuery(): void
    {
        $this->writeSpec();

        ScribeServer::tool(SearchEndpointsTool::class)->assertHasErrors();
    }

    #[Test]
    public function listsTheGroupsWithTheirEndpointCounts(): void
    {
        $this->writeSpec();

        ScribeServer::tool(ListGroupsTool::class)->assertOk()->assertStructuredContent([
            'groups' => [
                ['name' => 'Users', 'description' => 'Manage users.', 'endpoints' => 3],
                ['name' => 'Endpoints', 'description' => '', 'endpoints' => 1],
            ],
        ]);
    }

    #[Test]
    public function namesTheCommandThatCreatesAMissingSpec(): void
    {
        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'users'])->assertHasErrors(['scribe:generate']);
        ScribeServer::tool(ListGroupsTool::class)->assertHasErrors(['scribe:generate']);
    }

    #[Test]
    public function readsEmptyFiltersAsNotGiven(): void
    {
        $this->writeSpec();

        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'users', 'group' => '', 'method' => ''])
            ->assertOk()
            ->assertSee('/api/users');
    }

    #[Test]
    public function listsAnOperationWithoutAnOperationId(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', "openapi: 3.0.3\npaths:\n  /health:\n    get:\n      summary: Health.\n");

        ScribeServer::tool(SearchEndpointsTool::class, ['query' => 'health'])
            ->assertOk()
            ->assertSee('get /health');
        ScribeServer::tool(ListGroupsTool::class)->assertOk();
    }
}
