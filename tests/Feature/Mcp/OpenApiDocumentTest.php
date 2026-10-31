<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testing\ParallelTesting;
use Ipsocode\Scribe\Exceptions\CouldntReadOpenApiSpec;
use Ipsocode\Scribe\Mcp\OpenApiDocument;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;

use function Hypervel\Coroutine\parallel;

/**
 * The generated spec read back for the MCP tools. The fixture is a spec in the
 * shape `scribe:generate` writes, with `$ref` components the writer's own
 * output does not use but an `openapi.overrides` entry may.
 */
class OpenApiDocumentTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/openapi.yaml';

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('scribe');

        parent::tearDown();
    }

    private function document(): OpenApiDocument
    {
        return $this->app->make(OpenApiDocument::class);
    }

    private function writeSpec(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', (string) file_get_contents(self::FIXTURE));
    }

    #[Test]
    public function listsEveryOperationWithoutItsDetails(): void
    {
        $this->writeSpec();

        $operations = $this->document()->operations();

        $this->assertSame(
            ['listUsers', 'createUser', 'showUser', 'getApiHealth'],
            array_column($operations, 'id'),
        );
        $this->assertSame([
            'id' => 'createUser',
            'method' => 'post',
            'path' => '/api/users',
            'summary' => 'Create a user.',
            'description' => '',
            'tags' => ['Users'],
        ], $operations[1]);
    }

    #[Test]
    public function readsAnOperationByIdWithItsReferencesResolved(): void
    {
        $this->writeSpec();

        $operation = $this->document()->operation('listUsers');

        $this->assertSame('get', $operation['method']);
        $this->assertSame('/api/users', $operation['path']);
        $this->assertSame([['in' => 'query', 'name' => 'page', 'schema' => ['type' => 'integer']]], $operation['parameters']);

        $schema = $operation['responses'][200]['content']['application/json']['schema'];
        $this->assertSame('array', $schema['properties']['data']['type']);
        $this->assertSame(['id', 'name', 'manager', 'address', 'billing'], array_keys($schema['properties']['data']['items']['properties']));
        $this->assertSame(['id' => 1, 'name' => 'Ada'], $operation['responses'][200]['content']['application/json']['example']['data'][0]);
    }

    #[Test]
    public function readsAnOperationByMethodAndPath(): void
    {
        $this->writeSpec();

        $this->assertSame('createUser', $this->document()->operation('POST', '/api/users')['operationId']);
        $this->assertNull($this->document()->operation('delete', '/api/users'));
        $this->assertNull($this->document()->operation('get', '/api/nothing'));
    }

    #[Test]
    public function resolvesARepeatedReferenceEachTimeAndStopsAtARecursiveOne(): void
    {
        $this->writeSpec();

        $user = $this->document()->operation('createUser')['requestBody']['content']['application/json']['schema'];

        $this->assertSame($user['properties']['address'], $user['properties']['billing']);
        $this->assertSame('string', $user['properties']['address']['properties']['city']['type']);
        $this->assertSame(['$ref' => '#/components/schemas/User'], $user['properties']['manager']);
    }

    #[Test]
    public function mergesTheParametersSharedByThePathIntoTheOperation(): void
    {
        $this->writeSpec();

        $parameters = $this->document()->operation('showUser')['parameters'];

        $this->assertSame(['path:id', 'query:fields'], array_map(fn (array $parameter) => $parameter['in'] . ':' . $parameter['name'], $parameters));
        $this->assertSame('Overrides the shared one.', $parameters[1]['description']);
    }

    #[Test]
    public function givesAnOperationWithoutParametersAnEmptyList(): void
    {
        $this->writeSpec();

        $this->assertSame([], $this->document()->operation('getApiHealth')['parameters']);
    }

    #[Test]
    public function exposesTheTagsServersAndSecurity(): void
    {
        $this->writeSpec();

        $document = $this->document();

        $this->assertSame(['Users', 'Endpoints'], array_column($document->tags(), 'name'));
        $this->assertSame([['url' => 'https://api.example.test']], $document->servers());
        $this->assertSame([['default' => []]], $document->security()['requirements']);
        $this->assertSame('bearer', $document->security()['schemes']['default']['scheme']);
        $this->assertSame('X-Api-Key', $document->security()['schemes']['key']['name']);
    }

    #[Test]
    public function answersWithEmptyListsWhenTheSpecHasNoSuchSections(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', "openapi: 3.0.3\n");

        $document = $this->document();

        $this->assertSame([], $document->operations());
        $this->assertSame([], $document->tags());
        $this->assertSame([], $document->servers());
        $this->assertSame(['schemes' => [], 'requirements' => []], $document->security());
        $this->assertNull($document->operation('listUsers'));
    }

    #[Test]
    public function failsNamingScribeGenerateWhenTheSpecIsMissing(): void
    {
        $this->expectException(CouldntReadOpenApiSpec::class);
        $this->expectExceptionMessage('scribe:generate');

        $this->document()->operations();
    }

    #[Test]
    public function failsWhenTheFileIsNotAYamlMapping(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', 'just text');

        $this->expectException(CouldntReadOpenApiSpec::class);
        $this->expectExceptionMessage('not a YAML mapping');

        $this->document()->operations();
    }

    #[Test]
    public function failsOnAReferenceTheSpecDoesNotDefine(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', <<<'YAML'
            paths:
              /a:
                get:
                  operationId: a
                  responses:
                    200:
                      $ref: '#/components/responses/Gone'
            YAML);

        $this->expectException(CouldntReadOpenApiSpec::class);
        $this->expectExceptionMessage('#/components/responses/Gone');

        $this->document()->operation('a');
    }

    #[Test]
    public function failsOnAReferenceOutsideTheDocument(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', <<<'YAML'
            paths:
              /a:
                get:
                  operationId: a
                  responses:
                    200:
                      $ref: 'other.yaml#/Gone'
            YAML);

        $this->expectException(CouldntReadOpenApiSpec::class);

        $this->document()->operation('a');
    }

    #[Test]
    public function readsTheSpecAgainWhenItsModificationTimeChanges(): void
    {
        $this->writeSpec();
        $path = Storage::disk('local')->path('scribe/openapi.yaml');
        $document = $this->document();

        $this->assertCount(4, $document->operations());

        file_put_contents($path, "paths:\n  /only:\n    get:\n      operationId: only\n");
        touch($path, filemtime($path) + 10);

        $this->assertSame(['only'], array_column($document->operations(), 'id'));
    }

    #[Test]
    public function keepsTheParsedSpecWhileTheFileIsUnchanged(): void
    {
        $this->writeSpec();
        $path = Storage::disk('local')->path('scribe/openapi.yaml');
        $document = $this->document();
        $mtime = filemtime($path);

        $document->operations();
        file_put_contents($path, str_pad("paths: {}\n", filesize($path)));
        touch($path, $mtime);

        $this->assertCount(4, $document->operations());
    }

    #[Test]
    #[WithConfig('scribe.type', 'static')]
    public function readsAStaticTypeFromItsOutputDirectory(): void
    {
        $directory = ParallelTesting::tempDir('openapi-static');
        $this->app->get('config')->set('scribe.static.output_path', $directory . '/');
        File::ensureDirectoryExists($directory);

        try {
            File::copy(self::FIXTURE, $directory . '/openapi.yaml');

            $this->assertCount(4, $this->document()->operations());
        } finally {
            File::deleteDirectory($directory);
        }
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_static')]
    public function readsARelativeStaticOutputPathFromTheBasePath(): void
    {
        $this->app->get('config')->set('scribe.static.output_path', 'public/docs');

        $this->expectException(CouldntReadOpenApiSpec::class);
        $this->expectExceptionMessage(base_path('public/docs/openapi.yaml'));

        $this->document()->operations();
    }

    #[Test]
    #[WithConfig('scribe.type', 'external_hypervel')]
    public function readsAnExternalRoutedTypeFromTheLocalDisk(): void
    {
        $this->writeSpec();

        $this->assertCount(4, $this->document()->operations());
    }

    #[Test]
    public function throwsTheSpecExceptionForYamlThatDoesNotParse(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', "paths: [unclosed\n");

        try {
            $this->document()->operations();
            $this->fail('A spec that is not YAML was read.');
        } catch (CouldntReadOpenApiSpec $exception) {
            $this->assertStringContainsString('scribe:generate', $exception->getMessage());
            $this->assertInstanceOf(ParseException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function keepsAnEmptyMappingApartFromAnEmptyList(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', <<<'YAML'
            paths:
              /api/health:
                get:
                  operationId: health
                  parameters: []
                  security: []
                  responses:
                    200:
                      description: ''
                      content:
                        application/json:
                          schema: { type: object, properties: {} }
            YAML);

        $operation = $this->document()->operation('health');

        $this->assertSame([], $operation['parameters']);
        $this->assertSame([], $operation['security']);
        $this->assertEquals(new stdClass, $operation['responses'][200]['content']['application/json']['schema']['properties']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($operation));
    }

    #[Test]
    public function identifiesAnOperationWithoutAnOperationIdByItsMethodAndPath(): void
    {
        Storage::disk('local')->put('scribe/openapi.yaml', "paths:\n  /health:\n    get:\n      summary: Health.\n");

        $this->assertSame(['get /health'], array_column($this->document()->operations(), 'id'));
        $this->assertSame('/health', $this->document()->operation('get /health')['path']);
    }

    #[Test]
    public function parsesTheSpecOnceForCoroutinesThatFindItStaleTogether(): void
    {
        $this->writeSpec();
        $document = new class($this->app->get('config')) extends OpenApiDocument {
            public int $parses = 0;

            protected function parse(string $path): mixed
            {
                ++$this->parses;
                usleep(5000);

                return parent::parse($path);
            }
        };

        $counts = parallel(array_fill(0, 4, fn (): int => count($document->operations())));

        $this->assertSame([4, 4, 4, 4], $counts);
        $this->assertSame(1, $document->parses);
    }
}
