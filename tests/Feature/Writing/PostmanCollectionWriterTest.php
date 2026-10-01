<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Writing;

use Hypervel\Http\UploadedFile;
use Ipsocode\Scribe\Tests\CreatesMockEndpoints;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Writing\PostmanCollectionWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PostmanCollectionWriterTest extends TestCase
{
    use CreatesMockEndpoints;

    protected array $config = [
        'title' => 'Example API',
        'description' => 'All about the API.',
        'base_url' => 'http://api.test',
    ];

    private function generate(array $groups): array
    {
        return (new PostmanCollectionWriter(new DocumentationConfig($this->config)))
            ->generatePostmanCollection($groups);
    }

    #[Test]
    public function followsV21SchemaAndIncludesInfo(): void
    {
        $collection = $this->generate([$this->createGroup([$this->createMockEndpointData()])]);

        $this->assertStringContainsString('v2.1.0', $collection['info']['schema']);
        $this->assertSame($this->config['title'], $collection['info']['name']);
        $this->assertSame($this->config['description'], $collection['info']['description']);
    }

    #[Test]
    public function groupsBecomeFoldersWithOneRequestPerEndpoint(): void
    {
        $collection = $this->generate([
            $this->createGroup([
                $this->createMockEndpointData(['uri' => 'api/users', 'httpMethods' => ['GET'], 'metadata.title' => 'List users']),
                $this->createMockEndpointData(['uri' => 'api/users', 'httpMethods' => ['POST'], 'metadata.title' => 'Create user']),
            ]),
        ]);

        $this->assertCount(1, $collection['item']);
        $folder = $collection['item'][0];
        $this->assertSame('Users', $folder['name']);
        $this->assertCount(2, $folder['item']);

        $names = collect($folder['item'])->pluck('name')->all();
        $this->assertContains('List users', $names);
        $this->assertContains('Create user', $names);
    }

    #[Test]
    public function buildsRequestUrlAndMethod(): void
    {
        $collection = $this->generate([
            $this->createGroup([
                $this->createMockEndpointData(['uri' => 'api/users/{id}', 'httpMethods' => ['GET'], 'metadata.title' => 'Show user']),
            ]),
        ]);

        $request = $collection['item'][0]['item'][0]['request'];

        $this->assertSame('GET', $request['method']);
        // Postman renders the path with `:param` placeholders, e.g. "api/users/:id".
        $this->assertStringContainsString('api/users/:id', $request['url']['path']);
        $this->assertStringContainsString('{{baseUrl}}', $request['url']['raw']);
    }

    #[Test]
    public function theCollectionIsUnauthenticatedWhenAuthIsDisabled(): void
    {
        $collection = $this->generate([$this->createGroup([$this->createMockEndpointData()])]);

        $this->assertSame(['type' => 'noauth'], $collection['auth']);
    }

    #[Test]
    #[DataProvider('authSchemes')]
    public function describesTheConfiguredAuthScheme(string $in, string $name, array $expected): void
    {
        $this->config['auth'] = ['enabled' => true, 'in' => $in, 'name' => $name];

        $this->assertSame($expected, $this->generate([$this->createGroup([$this->createMockEndpointData()])])['auth']);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function authSchemes(): array
    {
        return [
            'basic' => ['basic', 'Authorization', ['type' => 'basic']],
            'bearer' => ['bearer', 'Authorization', [
                'type' => 'bearer',
                'bearer' => [['key' => 'Authorization', 'type' => 'string']],
            ]],
            'query' => ['query', 'api_key', [
                'type' => 'apikey',
                'apikey' => [
                    ['key' => 'in', 'value' => 'query', 'type' => 'string'],
                    ['key' => 'key', 'value' => 'api_key', 'type' => 'string'],
                ],
            ]],
        ];
    }

    #[Test]
    public function theAuthHeaderIsNotRepeatedOnEveryRequest(): void
    {
        // Postman applies the collection-level auth itself; leaving the header
        // on each request would send it twice, with the placeholder value.
        $this->config['auth'] = ['enabled' => true, 'in' => 'bearer', 'name' => 'Authorization'];

        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData(['headers' => ['Authorization' => 'Bearer {token}', 'Api-Version' => 'v1']]),
        ])]);

        $headers = collect($collection['item'][0]['item'][0]['request']['header'])->pluck('key')->all();

        $this->assertNotContains('Authorization', $headers);
        $this->assertContains('Api-Version', $headers);
    }

    #[Test]
    public function theAuthQueryParameterIsNotRepeatedOnEveryRequest(): void
    {
        $this->config['auth'] = ['enabled' => true, 'in' => 'query', 'name' => 'api_key'];

        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'queryParameters' => [
                    'api_key' => ['name' => 'api_key', 'type' => 'string', 'description' => 'Key.', 'required' => true, 'example' => 'abc'],
                    'page' => ['name' => 'page', 'type' => 'integer', 'description' => 'Page.', 'required' => false, 'example' => 2],
                ],
            ]),
        ])]);

        $query = collect($collection['item'][0]['item'][0]['request']['url']['query'])->pluck('key')->all();

        $this->assertNotContains('api_key', $query);
        $this->assertContains('page', $query);
    }

    #[Test]
    public function everyRequestAcceptsJson(): void
    {
        $collection = $this->generate([$this->createGroup([$this->createMockEndpointData(['headers' => []])])]);

        $headers = collect($collection['item'][0]['item'][0]['request']['header'])->keyBy('key');

        $this->assertSame('application/json', $headers['Accept']['value']);
    }

    #[Test]
    public function aPostmanVariableWrittenWithAnAtPrefixIsUnescaped(): void
    {
        // `['X-Token' => '@{{token}}']` in config is how a user asks for a live
        // Postman variable without the config loader interpolating it first.
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData(['headers' => ['X-Token' => '@{{token}}']]),
        ])]);

        $headers = collect($collection['item'][0]['item'][0]['request']['header'])->keyBy('key');

        $this->assertSame('{{token}}', $headers['X-Token']['value']);
    }

    #[Test]
    public function queryParametersCarryTheirExampleDescriptionAndEnabledState(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'queryParameters' => [
                    'page' => ['name' => 'page', 'type' => 'integer', 'description' => 'The <b>page</b>.', 'required' => false, 'example' => 2],
                    'empty' => ['name' => 'empty', 'type' => 'string', 'description' => 'Nothing.', 'required' => false, 'example' => null],
                ],
            ]),
        ])]);

        $query = collect($collection['item'][0]['item'][0]['request']['url']['query'])->keyBy('key');

        $this->assertSame('2', $query['page']['value']);
        // HTML belongs in the docs page, not in a Postman description field.
        $this->assertSame('The page.', $query['page']['description']);
        $this->assertFalse($query['page']['disabled']);

        // An optional parameter with no example is sent disabled, so importing
        // the collection does not fire requests with empty filters.
        $this->assertTrue($query['empty']['disabled']);
    }

    #[Test]
    public function arrayQueryParametersAreExpandedIntoIndexedKeys(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'queryParameters' => [
                    'filters' => ['name' => 'filters', 'type' => 'string[]', 'description' => 'Filters.', 'required' => true, 'example' => ['name', 'age']],
                ],
            ]),
        ])]);

        $query = collect($collection['item'][0]['item'][0]['request']['url']['query'])->keyBy('key');

        $this->assertSame('name', $query['filters[0]']['value']);
        $this->assertSame('age', $query['filters[1]']['value']);
    }

    #[Test]
    public function anEmptyArrayQueryParameterStillShowsUpOnce(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'queryParameters' => [
                    'filters' => ['name' => 'filters', 'type' => 'string[]', 'description' => 'Filters.', 'required' => false, 'example' => []],
                ],
            ]),
        ])]);

        $query = collect($collection['item'][0]['item'][0]['request']['url']['query'])->keyBy('key');

        $this->assertSame('', $query['filters[]']['value']);
        $this->assertTrue($query['filters[]']['disabled']);
    }

    #[Test]
    public function urlParametersBecomePostmanPathVariables(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'uri' => 'api/users/{id}',
                'urlParameters' => [
                    'id' => ['name' => 'id', 'type' => 'integer', 'description' => 'The user id.', 'required' => true, 'example' => 3],
                ],
            ]),
        ])]);

        $variables = $collection['item'][0]['item'][0]['request']['url']['variable'];

        $this->assertSame('id', $variables[0]['key']);
        $this->assertSame(3, $variables[0]['value']);
        $this->assertSame('The user id.', $variables[0]['description']);
    }

    #[Test]
    public function theRawUrlEncodesItsQueryString(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'queryParameters' => [
                    'q' => ['name' => 'q', 'type' => 'string', 'description' => 'Search.', 'required' => true, 'example' => 'a b&c'],
                ],
            ]),
        ])]);

        $raw = $collection['item'][0]['item'][0]['request']['url']['raw'];

        $this->assertStringContainsString('q=a%20b%26c', $raw);
    }

    #[Test]
    public function aJsonBodyIsSentRaw(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'httpMethods' => ['POST'],
                'headers' => ['Content-Type' => 'application/json'],
                'bodyParameters' => [
                    'name' => ['name' => 'name', 'type' => 'string', 'description' => 'Name.', 'required' => true, 'example' => 'Ada'],
                ],
                'cleanBodyParameters' => ['name' => 'Ada'],
            ]),
        ])]);

        $body = $collection['item'][0]['item'][0]['request']['body'];

        $this->assertSame('raw', $body['mode']);
        $this->assertSame('{"name":"Ada"}', $body['raw']);
    }

    #[Test]
    public function aFormBodyIsSentAsKeyValuePairs(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'httpMethods' => ['POST'],
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                // OutputEndpointData recomputes cleanBodyParameters from these,
                // so the nesting has to be expressed as dotted parameters.
                'bodyParameters' => [
                    'name' => ['name' => 'name', 'type' => 'string', 'description' => 'The name.', 'required' => true, 'example' => 'Ada'],
                    'address' => ['name' => 'address', 'type' => 'object', 'description' => 'Address.', 'required' => true],
                    'address.city' => ['name' => 'address.city', 'type' => 'string', 'description' => 'City.', 'required' => true, 'example' => 'Lovelace'],
                ],
            ]),
        ])]);

        $body = $collection['item'][0]['item'][0]['request']['body'];
        $params = collect($body['urlencoded'])->keyBy('key');

        $this->assertSame('urlencoded', $body['mode']);
        $this->assertSame('Ada', $params['name']['value']);
        $this->assertSame('The name.', $params['name']['description']);
        // Nested values are flattened into bracket notation.
        $this->assertSame('Lovelace', $params['address[city]']['value']);
    }

    #[Test]
    public function fileParametersAreSentAsFormDataFileEntries(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'httpMethods' => ['POST'],
                // A file parameter is one whose *example* is an UploadedFile;
                // OutputEndpointData splits those out itself and sets the
                // multipart content type, so neither is passed in here.
                'bodyParameters' => [
                    'avatar' => ['name' => 'avatar', 'type' => 'file', 'description' => 'Avatar.', 'required' => true, 'example' => UploadedFile::fake()->create('avatar.jpg')],
                    'docs' => ['name' => 'docs', 'type' => 'file[]', 'description' => 'Docs.', 'required' => false, 'example' => [UploadedFile::fake()->create('a.pdf')]],
                    'meta' => ['name' => 'meta', 'type' => 'object', 'description' => 'Meta.', 'required' => false],
                    'meta.scan' => ['name' => 'meta.scan', 'type' => 'file', 'description' => 'Scan.', 'required' => false, 'example' => UploadedFile::fake()->create('scan.png')],
                ],
            ]),
        ])]);

        $body = $collection['item'][0]['item'][0]['request']['body'];
        $files = collect($body['formdata'])->where('type', 'file')->pluck('key')->all();

        $this->assertSame('formdata', $body['mode']);
        $this->assertContains('avatar', $files);
        // A list of files and a nested file both flatten into bracket notation.
        $this->assertContains('docs[]', $files);
        $this->assertContains('meta[scan]', $files);
    }

    #[Test]
    public function aFileUploadOnAPutIsSentAsAPostWithAMethodField(): void
    {
        // Browsers and HTTP clients cannot send multipart bodies on PUT/PATCH,
        // so the collection sends a POST carrying `_method` — the same spoofing
        // the framework itself understands.
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'httpMethods' => ['PUT'],
                'bodyParameters' => [
                    'avatar' => ['name' => 'avatar', 'type' => 'file', 'description' => 'Avatar.', 'required' => true, 'example' => UploadedFile::fake()->create('avatar.jpg')],
                ],
            ]),
        ])]);

        $request = $collection['item'][0]['item'][0]['request'];

        $this->assertSame('POST', $request['method']);
        $this->assertContains(
            ['key' => '_method', 'value' => 'PUT', 'type' => 'text'],
            $request['body']['formdata'],
        );
    }

    #[Test]
    public function aBinaryResponseIsDescribedByWhateverFollowsTheMarker(): void
    {
        // A binary body has nothing to show, so the marker's trailing text is
        // the only description there is.
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'responses' => [
                    ['status' => 200, 'content' => '<<binary>> The exported CSV', 'description' => '200'],
                ],
            ]),
        ])]);

        $this->assertSame(
            'The exported CSV',
            $collection['item'][0]['item'][0]['response'][0]['name'],
        );
    }

    #[Test]
    public function subgroupedEndpointsBecomeNestedFolders(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData(['metadata.title' => 'Plain']),
            $this->createMockEndpointData([
                'metadata.title' => 'First',
                'metadata.subgroup' => 'Profile',
                'metadata.subgroupDescription' => 'Profile endpoints.',
            ]),
            $this->createMockEndpointData(['metadata.title' => 'Second', 'metadata.subgroup' => 'Profile']),
        ])]);

        $items = collect($collection['item'][0]['item'])->keyBy('name');

        $this->assertArrayHasKey('Plain', $items->all());

        $subgroup = $items['Profile'];
        $this->assertSame('Profile endpoints.', $subgroup['description']);
        // Both endpoints land in the one folder rather than creating two.
        $this->assertCount(2, $subgroup['item']);
    }

    #[Test]
    public function responsesAreCarriedIntoTheCollection(): void
    {
        $collection = $this->generate([$this->createGroup([
            $this->createMockEndpointData([
                'responses' => [
                    ['status' => 200, 'content' => '{"ok":true}', 'description' => 'Success', 'headers' => ['X-Rate-Limit' => '60']],
                    // A description that is just the status code carries no
                    // information and is dropped.
                    ['status' => 404, 'content' => '{}', 'description' => '404'],
                    ['status' => 422, 'content' => '{}', 'description' => '422, validation failed'],
                ],
            ]),
        ])]);

        $responses = collect($collection['item'][0]['item'][0]['response'])->keyBy('code');

        $this->assertSame('{"ok":true}', $responses[200]['body']);
        $this->assertSame('Success', $responses[200]['name']);
        $this->assertSame([['key' => 'X-Rate-Limit', 'value' => '60']], $responses[200]['header']);
        $this->assertSame('', $responses[404]['name']);
        $this->assertSame('validation failed', $responses[422]['name']);
    }

    #[Test]
    public function theBaseUrlFallsBackToTheApplicationUrl(): void
    {
        unset($this->config['base_url'], $this->config['title']);

        $collection = $this->generate([$this->createGroup([$this->createMockEndpointData()])]);

        $this->assertSame(config('app.url'), $collection['variable'][0]['value']);
        $this->assertSame(config('app.name'), $collection['info']['name']);
    }
}
