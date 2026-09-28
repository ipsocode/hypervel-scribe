<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Writing;

use Ipsocode\Camel\Output\OutputEndpointData;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Ipsocode\Scribe\Writing\OpenAPISpecWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class OpenAPISpecWriterTest extends TestCase
{
    use CreatesMockEndpoints;

    protected array $config = [
        'title' => 'Example API',
        'description' => 'All about the API.',
        'base_url' => 'http://api.test',
    ];

    private function generate(array $groups): array
    {
        return (new OpenAPISpecWriter(new DocumentationConfig($this->config)))
            ->generateSpecContent($groups);
    }

    #[Test]
    public function followsCorrectSpecStructure(): void
    {
        $groups = [$this->createGroup([$this->createMockEndpointData(), $this->createMockEndpointData()])];

        $results = $this->generate($groups);

        $this->assertSame(OpenAPISpecWriter::SPEC_VERSION, $results['openapi']);
        $this->assertSame($this->config['title'], $results['info']['title']);
        $this->assertSame($this->config['description'], $results['info']['description']);
        $this->assertNotEmpty($results['info']['version']);
        $this->assertSame($this->config['base_url'], $results['servers'][0]['url']);
        $this->assertIsArray($results['paths']);
        $this->assertGreaterThan(0, count($results['paths']));
    }

    #[Test]
    public function addsEndpointsCorrectlyAsOperationsUnderPaths(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['GET'], 'metadata.title' => 'Get path1']),
            $this->createMockEndpointData(['uri' => 'path1', 'httpMethods' => ['POST'], 'metadata.title' => 'Post path1']),
            $this->createMockEndpointData(['uri' => 'path1/path2', 'httpMethods' => ['GET'], 'metadata.title' => 'Nested']),
        ])];

        $results = $this->generate($groups);

        $this->assertCount(2, $results['paths']);
        $this->assertArrayHasKey('get', $results['paths']['/path1']);
        $this->assertArrayHasKey('post', $results['paths']['/path1']);
        $this->assertArrayHasKey('get', $results['paths']['/path1/path2']);
        $this->assertSame('Get path1', $results['paths']['/path1']['get']['summary']);
        $this->assertSame(['Users'], $results['paths']['/path1']['get']['tags']);
    }

    #[Test]
    public function anEndpointListedInTwoGroupsIsTaggedWithTheFirst(): void
    {
        $endpoint = $this->createMockEndpointData(['uri' => 'things', 'httpMethods' => ['GET']]);

        $spec = $this->generate([
            $this->createGroup([$endpoint], 'First'),
            $this->createGroup([$endpoint], 'Second'),
        ]);

        $this->assertSame(['First'], $spec['paths']['/things']['get']['tags']);
    }

    #[Test]
    public function oneWriterTagsEachSpecByTheGroupsThatSpecWasBuiltFrom(): void
    {
        // The group of each endpoint is looked up in an index built once per
        // set of groups; handed a different set, the writer must index that
        // one rather than answer from the last.
        $writer = new OpenAPISpecWriter(new DocumentationConfig($this->config));
        $endpoint = $this->createMockEndpointData(['uri' => 'things', 'httpMethods' => ['GET']]);

        $first = $writer->generateSpecContent([$this->createGroup([$endpoint], 'First')]);
        $second = $writer->generateSpecContent([$this->createGroup([$endpoint], 'Second')]);

        $this->assertSame(['First'], $first['paths']['/things']['get']['tags']);
        $this->assertSame(['Second'], $second['paths']['/things']['get']['tags']);
    }

    #[Test]
    public function addsUrlAndQueryParameters(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id}',
                'httpMethods' => ['GET'],
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'User id.', 'required' => true, 'example' => 1],
                ],
                'queryParameters' => [
                    'page' => ['name' => 'page', 'type' => 'integer', 'description' => 'Page.', 'required' => false, 'example' => 2],
                ],
            ]),
        ])];

        $path = $this->generate($groups)['paths']['/users/{id}'];

        // URL parameters are emitted at the path-item level (shared across operations).
        $pathParams = collect($path['parameters'])->keyBy('name');
        $this->assertSame('path', $pathParams['id']['in']);
        $this->assertTrue($pathParams['id']['required']);

        // Query parameters are emitted at the operation level.
        $queryParams = collect($path['get']['parameters'])->keyBy('name');
        $this->assertSame('query', $queryParams['page']['in']);
        $this->assertFalse($queryParams['page']['required']);
    }

    #[Test]
    public function addsRequestBodyForBodyParameters(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'name' => ['name' => 'name', 'type' => 'string', 'description' => 'Name.', 'required' => true, 'example' => 'Ada'],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertContains('name', $schema['required']);
    }

    #[Test]
    public function documentsHeadersAsOperationParameters(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'headers' => ['Api-Version' => 'v1', 'Content-Type' => 'application/json'],
            ]),
        ])];

        $params = collect($this->generate($groups)['paths']['/users']['get']['parameters'])->keyBy('name');

        $this->assertSame('header', $params['Api-Version']['in']);
        $this->assertSame('v1', $params['Api-Version']['example']);
        $this->assertSame('string', $params['Api-Version']['schema']['type']);
        // Content-Type is expressed by the request body's media type, not as a
        // header parameter; duplicating it makes clients send it twice.
        $this->assertArrayNotHasKey('Content-Type', $params->all());
    }

    #[Test]
    public function documentsADeprecatedAndNullableQueryParameter(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'queryParameters' => [
                    'sort' => [
                        'name' => 'sort', 'type' => 'string', 'description' => 'Sort key.',
                        'required' => false, 'example' => 'name', 'nullable' => true, 'deprecated' => true,
                        'enumValues' => ['name', 'created_at'],
                    ],
                ],
            ]),
        ])];

        $param = collect($this->generate($groups)['paths']['/users']['get']['parameters'])->keyBy('name')['sort'];

        $this->assertTrue($param['deprecated']);
        $this->assertTrue($param['schema']['nullable']);
        $this->assertSame(['name', 'created_at'], $param['schema']['enum']);
    }

    #[Test]
    public function documentsAnArrayQueryParameterWithAnItemsSchema(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'queryParameters' => [
                    'ids' => ['name' => 'ids', 'type' => 'integer[]', 'description' => 'Ids.', 'required' => false, 'example' => [1, 2]],
                ],
            ]),
        ])];

        $schema = collect($this->generate($groups)['paths']['/users']['get']['parameters'])->keyBy('name')['ids']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame('integer', $schema['items']['type']);
    }

    #[Test]
    public function documentsAFileBodyParameterAsMultipart(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'avatars',
                'httpMethods' => ['POST'],
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'bodyParameters' => [
                    'avatar' => ['name' => 'avatar', 'type' => 'file', 'description' => 'The avatar.', 'required' => true],
                ],
            ]),
        ])];

        $body = $this->generate($groups)['paths']['/avatars']['post']['requestBody'];

        $this->assertArrayHasKey('multipart/form-data', $body['content']);
        $schema = $body['content']['multipart/form-data']['schema'];
        $this->assertSame('string', $schema['properties']['avatar']['type']);
        $this->assertSame('binary', $schema['properties']['avatar']['format']);
    }

    #[Test]
    public function documentsNestedObjectBodyParameters(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'address' => ['name' => 'address', 'type' => 'object', 'description' => 'Address.', 'required' => true],
                    'address.city' => ['name' => 'address.city', 'type' => 'string', 'description' => 'City.', 'required' => true, 'example' => 'Lovelace'],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertSame('object', $schema['properties']['address']['type']);
        $this->assertSame('string', $schema['properties']['address']['properties']['city']['type']);
    }

    #[Test]
    public function anEndpointWithNoBodyHasNoRequestBody(): void
    {
        $groups = [$this->createGroup([$this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['GET']])])];

        $this->assertArrayNotHasKey('requestBody', $this->generate($groups)['paths']['/users']['get']);
    }

    #[Test]
    public function derivesAResponseSchemaFromTheResponseBody(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [[
                    'status' => 200,
                    'description' => 'OK',
                    'content' => '{"id":1,"name":"Ada","active":true,"score":1.5,"tags":["a"],"meta":{"page":1},"deleted_at":null}',
                ]],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('string', $schema['properties']['name']['type']);
        $this->assertSame('boolean', $schema['properties']['active']['type']);
        $this->assertSame('number', $schema['properties']['score']['type']);
        $this->assertSame('array', $schema['properties']['tags']['type']);
        $this->assertSame('object', $schema['properties']['meta']['type']);
        // A null value carries no type information of its own.
        $this->assertSame('string', $schema['properties']['deleted_at']['type']);
        $this->assertTrue($schema['properties']['deleted_at']['nullable']);
    }

    #[Test]
    public function derivesAResponseSchemaFromATopLevelArrayBody(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '[{"id":1}]']],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame('integer', $schema['items']['properties']['id']['type']);
    }

    #[Test]
    public function responseFieldsSupplyDescriptionsForTheResponseSchema(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '{"id":1}']],
                'responseFields' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'The user id.', 'required' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('The user id.', $schema['properties']['id']['description']);
        $this->assertContains('id', $schema['required']);
    }

    #[Test]
    public function aNonJsonResponseIsDocumentedAsPlainText(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'ping',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => 'pong']],
            ]),
        ])];

        $response = $this->generate($groups)['paths']['/ping']['get']['responses'][200];

        $this->assertArrayHasKey('text/plain', $response['content']);
    }

    #[Test]
    public function anEmptyResponseBodyIsDocumentedWithoutContent(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id}',
                'httpMethods' => ['DELETE'],
                'responses' => [['status' => 204, 'description' => 'No content', 'content' => '']],
            ]),
        ])];

        $response = $this->generate($groups)['paths']['/users/{id}']['delete']['responses'][204];

        $this->assertArrayNotHasKey('content', $response);
    }

    #[Test]
    public function operationIdsComeFromTheEndpointTitle(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['GET'], 'metadata.title' => 'List all users!']),
        ])];

        // Camel-cased and stripped of anything a tool-generated client method
        // name could not contain.
        $this->assertSame('listAllUsers', $this->generate($groups)['paths']['/users']['get']['operationId']);
    }

    #[Test]
    public function anUntitledEndpointGetsAnOperationIdFromItsMethodAndPath(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id}/posts',
                'httpMethods' => ['POST'],
                'metadata.title' => '',
            ]),
        ])];

        $this->assertSame(
            'postUsersIdPosts',
            $this->generate($groups)['paths']['/users/{id}/posts']['post']['operationId'],
        );
    }

    #[Test]
    public function aDeprecatedEndpointIsMarkedDeprecated(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData(['uri' => 'legacy', 'httpMethods' => ['GET'], 'metadata.deprecated' => true]),
        ])];

        $this->assertTrue($this->generate($groups)['paths']['/legacy']['get']['deprecated']);
    }

    #[Test]
    public function anOptionalUrlParameterBecomesOnePathItemPerUriTheRouteServes(): void
    {
        // OpenAPI has no optional path parameters, so a route that has one is
        // published as both of the URIs it answers to rather than as the longer
        // one with a caveat attached.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id?}',
                'httpMethods' => ['GET'],
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'Id.', 'required' => false, 'example' => 1],
                ],
            ]),
        ])];

        $paths = $this->generate($groups)['paths'];

        $this->assertSame(['/users', '/users/{id}'], array_keys($paths));

        // The bare URI takes no parameters at all...
        $this->assertArrayNotHasKey('parameters', $paths['/users']);

        // ...and the one whose template names the parameter declares it
        // required, with no "Optional parameter." caveat and no `omitted`
        // example standing in for a URI that now has a path item of its own.
        $id = $paths['/users/{id}']['parameters'][0];
        $this->assertTrue($id['required']);
        $this->assertSame('Id.', $id['description']);
        $this->assertSame(1, $id['example']);
        $this->assertArrayNotHasKey('examples', $id);

        // Both operations are in the one document, so their IDs have to differ.
        $this->assertSame('listUsers', $paths['/users/{id}']['get']['operationId']);
        $this->assertSame('listUsersWithoutId', $paths['/users']['get']['operationId']);
    }

    #[Test]
    public function chainedOptionalSegmentsAreExpandedOneUriAtATime(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'boards/{board}/{batch?}/{device?}',
                'httpMethods' => ['GET'],
                'metadata.title' => '',
                'urlParameters' => [
                    'board' => ['name' => 'board', 'type' => 'integer', 'description' => 'Board.', 'required' => true, 'example' => 1],
                    'batch' => ['name' => 'batch', 'type' => 'integer', 'description' => 'Batch.', 'required' => false, 'example' => 2],
                    'device' => ['name' => 'device', 'type' => 'integer', 'description' => 'Device.', 'required' => false, 'example' => 3],
                ],
            ]),
        ])];

        $paths = $this->generate($groups)['paths'];

        $this->assertSame([
            '/boards/{board}',
            '/boards/{board}/{batch}',
            '/boards/{board}/{batch}/{device}',
        ], array_keys($paths));

        // Each path item carries exactly the parameters its own template names.
        $names = fn (string $path) => collect($paths[$path]['parameters'])->pluck('name')->all();
        $this->assertSame(['board'], $names('/boards/{board}'));
        $this->assertSame(['board', 'batch'], $names('/boards/{board}/{batch}'));
        $this->assertSame(['board', 'batch', 'device'], $names('/boards/{board}/{batch}/{device}'));

        // Untitled, so the IDs are built from the URI — and still say what they
        // leave out, since two routes could otherwise build the same one.
        $this->assertSame('getBoardsBoardWithoutBatchDevice', $paths['/boards/{board}']['get']['operationId']);
        $this->assertSame('getBoardsBoardBatchWithoutDevice', $paths['/boards/{board}/{batch}']['get']['operationId']);
        $this->assertSame('getBoardsBoardBatchDevice', $paths['/boards/{board}/{batch}/{device}']['get']['operationId']);
    }

    #[Test]
    public function aVariantGivesWayToAUriTheApplicationRegisteredItself(): void
    {
        // `users` is served by both routes, and the one registered for it says
        // more about it than a copy of its neighbour would.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id?}',
                'httpMethods' => ['GET'],
                'metadata.title' => 'Show user',
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'Id.', 'required' => false, 'example' => 1],
                ],
            ]),
            $this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['GET'], 'metadata.title' => 'List users']),
        ])];

        $paths = $this->generate($groups)['paths'];

        $this->assertSame('listUsers', $paths['/users']['get']['operationId']);
        $this->assertSame('showUser', $paths['/users/{id}']['get']['operationId']);
    }

    #[Test]
    public function aVariantStillTakesAMethodTheRegisteredUriLeavesFree(): void
    {
        // Two endpoints share a path item whenever their methods differ, so a
        // registered POST on `users` does not stand in the way of the GET the
        // optional segment expands into.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id?}',
                'httpMethods' => ['GET'],
                'metadata.title' => 'Show user',
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'Id.', 'required' => false, 'example' => 1],
                ],
            ]),
            $this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['POST'], 'metadata.title' => 'Create user']),
        ])];

        $paths = $this->generate($groups)['paths'];

        $this->assertSame('createUser', $paths['/users']['post']['operationId']);
        $this->assertSame('showUserWithoutId', $paths['/users']['get']['operationId']);
    }

    #[Test]
    public function securitySchemesAreEmittedWhenAuthIsEnabled(): void
    {
        $this->config = array_merge($this->config, [
            'auth' => ['enabled' => true, 'in' => 'bearer', 'name' => 'Authorization', 'extra_info' => 'Get a token first.'],
        ]);

        $groups = [$this->createGroup([
            $this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['GET'], 'metadata.authenticated' => true]),
            $this->createMockEndpointData(['uri' => 'ping', 'httpMethods' => ['GET'], 'metadata.authenticated' => false]),
        ])];

        $spec = $this->generate($groups);

        $this->assertSame([
            'type' => 'http',
            'scheme' => 'bearer',
            'description' => 'Get a token first.',
        ], $spec['components']['securitySchemes']['default']);
        $this->assertSame([['default' => []]], $spec['security']);

        // An unauthenticated endpoint has to opt out explicitly, or the
        // top-level `security` would apply to it too.
        $this->assertSame([], $spec['paths']['/ping']['get']['security']);
        $this->assertArrayNotHasKey('security', $spec['paths']['/users']['get']);
    }

    #[Test]
    public function anApiKeyAuthSchemeNamesItsParameterAndLocation(): void
    {
        $this->config = array_merge($this->config, [
            'auth' => ['enabled' => true, 'in' => 'query', 'name' => 'api_key', 'extra_info' => ''],
        ]);

        $scheme = $this->generate([$this->createGroup([$this->createMockEndpointData()])])['components']['securitySchemes']['default'];

        $this->assertSame('apiKey', $scheme['type']);
        $this->assertSame('api_key', $scheme['name']);
        $this->assertSame('query', $scheme['in']);
    }

    #[Test]
    public function noSecurityIsEmittedWhenAuthIsDisabled(): void
    {
        $spec = $this->generate([$this->createGroup([$this->createMockEndpointData()])]);

        $this->assertArrayNotHasKey('security', $spec);
    }

    #[Test]
    public function configOverridesAreAppliedOverTheGeneratedSpec(): void
    {
        $this->config = array_merge($this->config, [
            'openapi' => ['overrides' => ['info.version' => '2.0.0', 'info.contact.name' => 'API team']],
        ]);

        $info = $this->generate([$this->createGroup([$this->createMockEndpointData()])])['info'];

        $this->assertSame('2.0.0', $info['version']);
        $this->assertSame('API team', $info['contact']['name']);
        // Overriding one key must not drop the rest of `info`.
        $this->assertSame($this->config['title'], $info['title']);
    }

    #[Test]
    public function generatingForOpenapi31UsesThe31Generator(): void
    {
        $this->config = array_merge($this->config, ['openapi' => ['version' => '3.1.0']]);

        $spec = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'queryParameters' => [
                    'page' => ['name' => 'page', 'type' => 'integer', 'description' => 'Page.', 'required' => false, 'example' => 2, 'nullable' => true],
                ],
            ]),
        ])]);

        $this->assertSame('3.1.0', $spec['openapi']);

        $schema = collect($spec['paths']['/users']['get']['parameters'])->keyBy('name')['page']['schema'];

        // 3.1 follows JSON Schema: nullability is a type union, and `example`
        // becomes the plural `examples`.
        $this->assertSame(['integer', 'null'], $schema['type']);
        $this->assertSame([2], $schema['examples']);
        $this->assertArrayNotHasKey('example', $schema);
        $this->assertArrayNotHasKey('nullable', $schema);
    }

    #[Test]
    public function theSpecVersionDefaultsTo303(): void
    {
        $this->assertSame(
            OpenAPISpecWriter::SPEC_VERSION,
            (new OpenAPISpecWriter(new DocumentationConfig($this->config)))->getSpecVersion(),
        );
    }

    #[Test]
    public function enumValuesOnAnArrayParameterConstrainItsItems(): void
    {
        // The enum belongs on the items, not on the array: it is each element
        // that has to be one of the listed values.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'queryParameters' => [
                    'roles' => [
                        'name' => 'roles', 'type' => 'string[]', 'description' => 'Roles.',
                        'required' => false, 'example' => ['admin'], 'enumValues' => ['admin', 'editor'],
                    ],
                ],
            ]),
        ])];

        $schema = collect($this->generate($groups)['paths']['/users']['get']['parameters'])->keyBy('name')['roles']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame(['admin', 'editor'], $schema['items']['enum']);
    }

    #[Test]
    public function documentsAnArrayOfArraysParameter(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'matrices',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'rows' => ['name' => 'rows', 'type' => 'string[][]', 'description' => 'Rows.', 'required' => true, 'example' => [['a', 'b']]],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/matrices']['post']['requestBody']['content']['application/json']['schema'];

        $rows = $schema['properties']['rows'];
        $this->assertSame('array', $rows['type']);
        $this->assertSame('array', $rows['items']['type']);
        $this->assertSame('string', $rows['items']['items']['type']);
    }

    #[Test]
    public function aFileArrayParameterIsBinaryAndCarriesNoExample(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'attachments',
                'httpMethods' => ['POST'],
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'bodyParameters' => [
                    'files' => ['name' => 'files', 'type' => 'file[]', 'description' => 'The files.', 'required' => true],
                ],
            ]),
        ])];

        $body = $this->generate($groups)['paths']['/attachments']['post']['requestBody'];

        $schema = $body['content']['multipart/form-data']['schema']['properties']['files'];
        $this->assertSame('array', $schema['type']);
        $this->assertSame('string', $schema['items']['type']);
        $this->assertSame('binary', $schema['items']['format']);
        // There is no honest way to render a file as a JSON example, so none is given.
        $this->assertArrayNotHasKey('example', $schema);
    }

    #[Test]
    public function documentsAnArrayOfObjectsBodyParameter(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'orders',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'items' => ['name' => 'items', 'type' => 'object[]', 'description' => 'Line items.', 'required' => true],
                    'items[].sku' => ['name' => 'items[].sku', 'type' => 'string', 'description' => 'SKU.', 'required' => true, 'example' => 'A-1'],
                    'items[].qty' => ['name' => 'items[].qty', 'type' => 'integer', 'description' => 'Quantity.', 'required' => false, 'example' => 2],
                ],
            ]),
        ])];

        $items = $this->generate($groups)['paths']['/orders']['post']['requestBody']['content']['application/json']['schema']['properties']['items'];

        $this->assertSame('array', $items['type']);
        $this->assertSame('object', $items['items']['type']);
        $this->assertSame('string', $items['items']['properties']['sku']['type']);
        $this->assertSame('integer', $items['items']['properties']['qty']['type']);
        // Required-ness is per subfield, and lives on the item schema.
        $this->assertSame(['sku'], $items['items']['required']);
    }

    #[Test]
    public function anObjectParameterWithNoRequiredSubfieldsOmitsRequired(): void
    {
        // The spec has no way to spell "required: []" — an empty list is invalid,
        // so the key has to be dropped rather than emitted empty.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'address' => ['name' => 'address', 'type' => 'object', 'description' => 'Address.', 'required' => false],
                    'address.city' => ['name' => 'address.city', 'type' => 'string', 'description' => 'City.', 'required' => false, 'example' => 'Lovelace'],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('required', $schema['properties']['address']);
    }

    #[Test]
    public function aTopLevelArrayRequestBodyIsDocumentedAsAnArraySchema(): void
    {
        // Body parameters named `[].x` mean the request body is itself an array;
        // the schema replaces the usual object-with-properties wholesale.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/bulk',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    '[].name' => ['name' => '[].name', 'type' => 'string', 'description' => 'Name.', 'required' => true, 'example' => 'Ada'],
                ],
            ]),
        ])];

        $body = $this->generate($groups)['paths']['/users/bulk']['post']['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        $this->assertTrue($body['required']);
        $this->assertSame('array', $schema['type']);
        $this->assertArrayHasKey('name', $schema['items']['properties']);
        $this->assertArrayNotHasKey('properties', $schema);
    }

    #[Test]
    public function aDeprecatedBodyParameterIsMarkedDeprecated(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['POST'],
                'bodyParameters' => [
                    'nickname' => ['name' => 'nickname', 'type' => 'string', 'description' => 'Old name.', 'required' => false, 'example' => 'ada', 'deprecated' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['post']['requestBody']['content']['application/json']['schema'];

        $this->assertTrue($schema['properties']['nickname']['deprecated']);
    }

    #[Test]
    public function aRequestBodyWithNoContentTypeHeaderDefaultsToJson(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['POST'],
                'headers' => [],
                'bodyParameters' => [
                    'name' => ['name' => 'name', 'type' => 'string', 'description' => 'Name.', 'required' => true, 'example' => 'Ada'],
                ],
            ]),
        ])];

        $this->assertArrayHasKey(
            'application/json',
            $this->generate($groups)['paths']['/users']['post']['requestBody']['content'],
        );
    }

    #[Test]
    public function severalResponsesSharingAStatusBecomeAOneOfSchema(): void
    {
        // OpenAPI allows one schema per status and content type, so alternative
        // shapes have to be merged rather than the last one winning.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [
                    ['status' => 200, 'description' => 'A user.', 'content' => '{"id":1}'],
                    ['status' => 200, 'description' => 'A guest.', 'content' => '{"token":"x"}'],
                    ['status' => 200, 'description' => 'Nobody.', 'content' => '{"anonymous":true}'],
                ],
            ]),
        ])];

        $response = $this->generate($groups)['paths']['/users']['get']['responses'][200];
        $schema = $response['content']['application/json']['schema'];

        $this->assertCount(3, $schema['oneOf']);
        // Each alternative keeps its own description; the response's own is
        // cleared, because it now describes only the first of three.
        $this->assertSame(['A user.', 'A guest.', 'Nobody.'], array_column($schema['oneOf'], 'description'));
        $this->assertSame('', $response['description']);
    }

    #[Test]
    public function aBinaryResponseIsDocumentedAsAnOctetStream(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'invoices/{id}',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => '', 'content' => '<<binary>> The invoice PDF.']],
            ]),
        ])];

        $response = $this->generate($groups)['paths']['/invoices/{id}']['get']['responses'][200];

        $this->assertSame('binary', $response['content']['application/octet-stream']['schema']['format']);
        // The marker is a directive, not prose — what follows it is the description.
        $this->assertSame('The invoice PDF.', $response['description']);
    }

    #[Test]
    public function aResponseDescriptionDropsARedundantStatusCode(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [
                    ['status' => 200, 'description' => '200, Success', 'content' => '{"id":1}'],
                    ['status' => 404, 'description' => '404', 'content' => '{"message":"Not found"}'],
                ],
            ]),
        ])];

        $responses = $this->generate($groups)['paths']['/users']['get']['responses'];

        // The status is already the key; repeating it in the description is noise.
        $this->assertSame('Success', $responses[200]['description']);
        $this->assertSame('', $responses[404]['description']);
    }

    #[Test]
    #[DataProvider('scalarResponseBodies')]
    public function aScalarResponseBodyGetsAScalarSchema(string $content, string $type, mixed $example): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'count',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => $content]],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/count']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame($type, $schema['type']);
        $this->assertSame($example, $schema['example']);
    }

    /**
     * A JSON body that decodes to a scalar, and the OpenAPI type it maps to.
     *
     * PHP's `double` is the spec's `number`; the rest carry their own names.
     */
    public static function scalarResponseBodies(): array
    {
        return [
            'string' => ['"ok"', 'string', 'ok'],
            'integer' => ['7', 'integer', 7],
            'boolean' => ['true', 'boolean', true],
            'float' => ['1.5', 'number', 1.5],
        ];
    }

    #[Test]
    public function anEmptyArrayResponseBodyStillDeclaresAnItemType(): void
    {
        // Nothing in `[]` says what it would have held, but the spec requires
        // `items`, so it falls back to a bare object.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '[]']],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame('object', $schema['items']['type']);
        $this->assertSame([], $schema['example']);
    }

    #[Test]
    public function anArrayOfScalarsResponseBodyTakesItsItemTypeFromTheFirstElement(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'ids',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '[1,2,3]']],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/ids']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('array', $schema['type']);
        $this->assertSame('integer', $schema['items']['type']);
        $this->assertArrayNotHasKey('properties', $schema['items']);
    }

    #[Test]
    public function anArrayOfObjectsResponseMarksItsRequiredFields(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '[{"id":1,"name":"Ada"}]']],
                'responseFields' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'The id.', 'required' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame(['id'], $schema['items']['required']);
    }

    #[Test]
    public function aNestedResponseObjectCarriesItsOwnRequiredFields(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '{"data":{"id":1,"name":"Ada"}}']],
                'responseFields' => [
                    'data.id' => ['name' => 'data.id', 'type' => 'integer', 'description' => 'The id.', 'required' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame(['id'], $schema['properties']['data']['required']);
    }

    #[Test]
    public function anArrayOfObjectsInsideAResponseCarriesItsRequiredFields(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '{"items":[{"id":1,"name":"Ada"}]}']],
                'responseFields' => [
                    'items.id' => ['name' => 'items.id', 'type' => 'integer', 'description' => 'The id.', 'required' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame('object', $schema['properties']['items']['items']['type']);
        $this->assertSame(['id'], $schema['properties']['items']['items']['required']);
    }

    #[Test]
    public function aResponseFieldsEnumValuesReachTheSchema(): void
    {
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'posts',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '{"status":"draft"}']],
                'responseFields' => [
                    'status' => [
                        'name' => 'status', 'type' => 'string', 'description' => 'The status.',
                        'required' => false, 'enumValues' => ['draft', 'published'],
                    ],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/posts']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertSame(['draft', 'published'], $schema['properties']['status']['enum']);
    }

    #[Test]
    public function aResponseFieldMarkedNullableIsNullableDespiteANonNullExample(): void
    {
        // Nullability is derived from the example when nothing says otherwise;
        // an explicit `nullable` has to win over what the example happens to hold.
        $groups = [$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users',
                'httpMethods' => ['GET'],
                'responses' => [['status' => 200, 'description' => 'OK', 'content' => '{"deleted_at":"2020-01-01"}']],
                'responseFields' => [
                    'deleted_at' => ['name' => 'deleted_at', 'type' => 'string', 'description' => 'When deleted.', 'required' => false, 'nullable' => true],
                ],
            ]),
        ])];

        $schema = $this->generate($groups)['paths']['/users']['get']['responses'][200]['content']['application/json']['schema'];

        $this->assertTrue($schema['properties']['deleted_at']['nullable']);
    }

    #[Test]
    public function theOpenapi31GeneratorConvertsExamplesOutsideSchemasToo(): void
    {
        // 3.1's parameter objects carry JSON Schema, so every `example` becomes
        // an `examples` array — including the ones the parent builds inline
        // rather than through `generateFieldData`.
        $this->config = array_merge($this->config, ['openapi' => ['version' => '3.1.0']]);

        $spec = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'users/{id}/{slug?}',
                'httpMethods' => ['GET'],
                'headers' => ['Api-Version' => 'v1'],
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'The id.', 'required' => true, 'example' => 1],
                    'slug' => ['name' => 'slug', 'type' => 'string', 'description' => 'The slug.', 'required' => false, 'example' => 'ada'],
                ],
                'queryParameters' => [
                    'tags' => ['name' => 'tags', 'type' => 'string[]', 'description' => 'Tags.', 'required' => false, 'example' => ['a']],
                    'filters' => [
                        'name' => 'filters', 'type' => 'object', 'description' => 'Filters.', 'required' => false,
                        'example' => ['status' => 'draft'],
                        '__fields' => [
                            'status' => ['name' => 'filters.status', 'type' => 'string', 'description' => 'The status.', 'required' => false, 'example' => 'draft'],
                        ],
                    ],
                ],
            ]),
        ])]);

        $path = $spec['paths']['/users/{id}/{slug}'];
        $operationParams = collect($path['get']['parameters'])->keyBy('name');
        $pathParams = collect($path['parameters'])->keyBy('name');

        // A header's example sits outside its schema and is moved into it.
        $this->assertSame(['v1'], $operationParams['Api-Version']['schema']['examples']);
        $this->assertArrayNotHasKey('example', $operationParams['Api-Version']);

        // A required URL parameter gets the same treatment...
        $this->assertSame([1], $pathParams['id']['schema']['examples']);
        // ...and so does one that is only required in this path item because
        // the route's optional segment was expanded into a URI of its own.
        $this->assertSame(['ada'], $pathParams['slug']['schema']['examples']);
        $this->assertArrayNotHasKey('example', $pathParams['slug']);

        // An array parameter's schema is converted recursively, items and all.
        $this->assertSame([['a']], $operationParams['tags']['schema']['examples']);
        $this->assertArrayNotHasKey('example', $operationParams['tags']['schema']['items']);

        // ...and so is an object parameter's, property by property.
        $this->assertSame(
            ['draft'],
            $operationParams['filters']['schema']['properties']['status']['examples'],
        );
        $this->assertArrayNotHasKey('example', $operationParams['filters']['schema']['properties']['status']);
    }

    #[Test]
    public function anUnrecognisedAuthLocationProducesNoScheme(): void
    {
        // `auth.in` is an enum in the shipped config, but nothing stops an
        // application writing a string of its own into it.
        $this->config = array_merge($this->config, [
            'auth' => ['enabled' => true, 'in' => 'somewhere-else', 'name' => 'key'],
        ]);

        $spec = $this->generate([$this->createGroup([$this->createMockEndpointData()])]);

        $this->assertSame([], $spec['components']['securitySchemes']['default']);
    }

    #[Test]
    public function aCustomGeneratorCanExtendTheSpecWithoutOverridingEverySection(): void
    {
        // `OpenApiGenerator`'s methods return what they were handed, so a
        // generator only implements the section it cares about.
        $this->config = array_merge($this->config, [
            'openapi' => ['generators' => [PathItemOnlyGenerator::class]],
        ]);

        $spec = $this->generate([$this->createGroup([$this->createMockEndpointData(['uri' => 'users', 'httpMethods' => ['GET']])])]);

        $this->assertSame('users.list', $spec['paths']['/users']['get']['x-internal-id']);
        // The sections it did not implement are untouched rather than emptied.
        $this->assertSame($this->config['title'], $spec['info']['title']);
    }
}

/**
 * A generator that implements one section and inherits the rest.
 */
class PathItemOnlyGenerator extends OpenApiGenerator
{
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $pathItem['x-internal-id'] = 'users.list';

        return $pathItem;
    }
}
