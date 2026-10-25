<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Mcp\Prompts\IntegrateEndpointPrompt;
use Ipsocode\Scribe\Mcp\Resources\OpenApiSpecResource;
use Ipsocode\Scribe\Mcp\Resources\PostmanCollectionResource;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ResourcesAndPromptTest extends TestCase
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
    public function readsTheGeneratedSpecAsAResource(): void
    {
        $this->writeSpec();

        ScribeServer::resource(OpenApiSpecResource::class)
            ->assertOk()
            ->assertSee(['operationId: listUsers', 'operationId: createUser']);
    }

    #[Test]
    public function readsTheGeneratedPostmanCollectionAsAResource(): void
    {
        Storage::disk('local')->put('scribe/collection.json', '{"info":{"name":"Acme"}}');

        ScribeServer::resource(PostmanCollectionResource::class)
            ->assertOk()
            ->assertSee('"name":"Acme"');
    }

    #[Test]
    #[WithConfig('scribe.postman.enabled', false)]
    public function leavesThePostmanResourceOutWhenPostmanOutputIsOff(): void
    {
        Storage::disk('local')->put('scribe/collection.json', '{}');

        ScribeServer::resource(PostmanCollectionResource::class)->assertNotRegistered();
    }

    #[Test]
    public function returnsAnErrorWhenTheGeneratedFilesAreMissing(): void
    {
        ScribeServer::resource(OpenApiSpecResource::class)->assertHasErrors(['scribe:generate']);
        ScribeServer::resource(PostmanCollectionResource::class)->assertHasErrors(['postman.enabled']);
    }

    #[Test]
    public function fillsInTheOperationsSummaryInThePrompt(): void
    {
        $this->writeSpec();

        ScribeServer::prompt(IntegrateEndpointPrompt::class, ['operationId' => 'createUser', 'language' => 'PHP'])
            ->assertOk()
            ->assertSee(['PHP client call', 'Create a user.', 'post /api/users', 'createUser']);
    }

    #[Test]
    public function rejectsAnUnknownOperationIdInThePrompt(): void
    {
        $this->writeSpec();

        ScribeServer::prompt(IntegrateEndpointPrompt::class, ['operationId' => 'nothing', 'language' => 'PHP'])
            ->assertHasErrors(['search-endpoints']);
    }

    #[Test]
    public function requiresBothPromptArguments(): void
    {
        $this->writeSpec();

        ScribeServer::prompt(IntegrateEndpointPrompt::class, ['operationId' => 'createUser'])->assertHasErrors();
    }

    #[Test]
    public function returnsAnErrorWhenThePromptCannotReadTheSpec(): void
    {
        ScribeServer::prompt(IntegrateEndpointPrompt::class, ['operationId' => 'createUser', 'language' => 'PHP'])
            ->assertHasErrors(['scribe:generate']);
    }

    #[Test]
    public function describesThePromptArguments(): void
    {
        $definition = $this->app->make(IntegrateEndpointPrompt::class)->toArray();

        $this->assertSame('integrate-endpoint', $definition['name']);
        $this->assertSame(['operationId', 'language'], array_column($definition['arguments'], 'name'));
        $this->assertSame([true, true], array_column($definition['arguments'], 'required'));
    }
}
