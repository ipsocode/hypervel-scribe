<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Mcp;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Client\Request as ClientRequest;
use Hypervel\Http\Request;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\Attributes\WithConfig;
use Ipsocode\Scribe\Exceptions\UnsafeMcpSandbox;
use Ipsocode\Scribe\Mcp\ScribeServer;
use Ipsocode\Scribe\Mcp\Tools\CallSandboxTool;
use Ipsocode\Scribe\ScribeServiceProvider;
use Ipsocode\Scribe\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;

use function Hypervel\Coroutine\parallel;

#[WithConfig('scribe.mcp.enabled', true)]
class SandboxHttpToolTest extends TestCase
{
    private const string FIXTURE = __DIR__ . '/../../Fixtures/openapi.yaml';

    /**
     * Name the whole sandbox group, as an application does: the group below `mcp` is replaced whole.
     * @param mixed $app
     */
    protected function defineEnvironment($app): void
    {
        $app->get('config')->set('scribe.mcp.sandbox', $this->sandboxConfig());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sandboxConfig(array $overrides = []): array
    {
        return $overrides + [
            'enabled' => true,
            'mode' => 'http',
            'base_url' => 'https://sandbox.test/',
            'timeout' => 10.0,
            'max_response_bytes' => 65536,
            'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function sandbox(array $overrides): void
    {
        $this->app->get('config')->set('scribe.mcp.sandbox', $this->sandboxConfig($overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::disk('local')->put('scribe/openapi.yaml', (string) file_get_contents(self::FIXTURE));
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('scribe');

        parent::tearDown();
    }

    private function withAuthorization(string $header): void
    {
        RequestContext::set(Request::create('/docs/mcp', 'POST', server: ['HTTP_AUTHORIZATION' => $header]));
    }

    #[Test]
    public function sendsTheRequestToTheSandboxOnly(): void
    {
        $options = [];
        Http::fake(function (ClientRequest $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['id' => 7], 200);
        });

        ScribeServer::tool(CallSandboxTool::class, [
            'operationId' => 'showUser',
            'pathParams' => ['id' => 7],
            'query' => ['include' => 'posts'],
            'headers' => ['X-Tenant' => 'acme'],
        ])
            ->assertOk()
            ->assertSee('"id":7')
            ->assertStructuredContent(fn ($json) => $json->where('status', 200)->where('truncated', false)->has('body')->etc());

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sandbox.test/api/users/7?include=posts'
            && $request->header('X-Tenant') === ['acme']
            && ! $request->hasHeader('Authorization'));
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(10.0, $options['timeout']);
        $this->assertTrue($options['stream']);
    }

    #[Test]
    public function sendsTheJsonBodyOfAWrite(): void
    {
        Http::fake(['*' => Http::response(['id' => 1], 201)]);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'createUser', 'body' => ['name' => 'Ada']])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 201)->etc());

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sandbox.test/api/users'
            && $request->data() === ['name' => 'Ada']);
    }

    #[Test]
    public function ignoresAHostTheCallerSupplies(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        ScribeServer::tool(CallSandboxTool::class, [
            'operationId' => 'showUser',
            'pathParams' => ['id' => '//evil.test/../x?y=1'],
            'headers' => ['host' => 'evil.test', 'Authorization' => 'Bearer stolen', 'Content-Length' => '1'],
        ])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://sandbox.test/api/users/%2F%2Fevil.test%2F..%2Fx%3Fy%3D1'
            && $request->header('Host') === ['sandbox.test']
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Content-Length'));
    }

    #[Test]
    public function rejectsAPathParameterThatIsNotAScalar(): void
    {
        Http::fake();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'showUser', 'pathParams' => ['id' => ['a']]])
            ->assertHasErrors(['must be a string or a number']);
    }

    #[Test]
    public function rejectsAPathParameterThatIsADotSegment(): void
    {
        Http::fake();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'showUser', 'pathParams' => ['id' => '..']])
            ->assertHasErrors(['cannot be a dot segment']);

        Http::assertNothingSent();
    }

    #[Test]
    public function doesNotFollowARedirect(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.test/'])]);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('status', 302)->etc());

        Http::assertSentCount(1);
    }

    #[Test]
    public function sendsTheAuthorizationHeaderOfTheRequestToTheServer(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->withAuthorization('Bearer customer-token');

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers', 'token' => 'ignored'])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->header('Authorization') === ['Bearer customer-token']);
    }

    #[Test]
    public function sendsTheTokenInputWhenTheRequestCarriesNoAuthorization(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        RequestContext::set(Request::create('/docs/mcp', 'POST'));

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers', 'token' => 'input-token'])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => $request->header('Authorization') === ['Bearer input-token']);
    }

    #[Test]
    public function sendsEachCoroutinesOwnToken(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $sandbox = $this->app->make(CallSandboxTool::class);

        $calls = array_map(fn (string $token): callable => function () use ($token): void {
            $this->withAuthorization("Bearer {$token}");
            usleep(1000);
            ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])->assertOk();
            usleep(1000);
        }, ['first', 'second']);

        parallel($calls);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => $request->header('Authorization') === ['Bearer first']);
        Http::assertSent(fn (ClientRequest $request): bool => $request->header('Authorization') === ['Bearer second']);
        $this->assertSame($sandbox, $this->app->make(CallSandboxTool::class));
    }

    #[Test]
    public function returnsAToolErrorWhenTheSandboxCannotBeReached(): void
    {
        Http::fake(['*' => Http::failedConnection('timed out')]);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])
            ->assertHasErrors(['The sandbox could not be reached', 'timed out']);
    }

    #[Test]
    public function usesTheConfiguredTimeout(): void
    {
        $this->sandbox(['timeout' => 2.5]);

        $timeout = null;
        Http::fake(function (ClientRequest $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'];

            return Http::response('ok');
        });

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])->assertOk();

        $this->assertSame(2.5, $timeout);
    }

    #[Test]
    public function cutsOffAResponseBodyPastTheLimit(): void
    {
        $this->sandbox(['max_response_bytes' => 5]);

        Http::fake(['*' => Http::response('éééé')]);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])
            ->assertOk()
            ->assertSee('[Truncated at 5 bytes.]')
            ->assertStructuredContent(fn ($json) => $json->where('body', 'éé')->where('truncated', true)->etc());
    }

    #[Test]
    public function keepsABodyAtTheLimit(): void
    {
        $this->sandbox(['max_response_bytes' => 4]);

        Http::fake(['*' => Http::response('éé')]);

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('body', 'éé')->where('truncated', false)->etc());
    }

    #[Test]
    public function refusesAMethodThatIsNotAllowed(): void
    {
        $this->sandbox(['methods' => ['get']]);

        Http::fake();

        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'createUser', 'body' => ['name' => 'Ada']])
            ->assertHasErrors(['does not allow POST']);
        ScribeServer::tool(CallSandboxTool::class, ['operationId' => 'listUsers'])->assertOk();
    }

    #[Test]
    #[WithConfig('scribe.try_it_out.base_url', 'https://try.test')]
    public function fallsBackToTheTryItOutUrl(): void
    {
        $this->sandbox(['base_url' => null]);

        $this->assertSame('https://try.test', $this->app->make(CallSandboxTool::class)->baseUrl());
    }

    #[Test]
    #[WithConfig('scribe.try_it_out.base_url', null)]
    #[WithConfig('scribe.base_url', 'https://docs.test/')]
    public function fallsBackToTheDisplayedUrl(): void
    {
        $this->sandbox(['base_url' => null]);

        $this->assertSame('https://docs.test', $this->app->make(CallSandboxTool::class)->baseUrl());
    }

    #[Test]
    #[WithConfig('scribe.try_it_out.base_url', null)]
    #[WithConfig('scribe.base_url', null)]
    #[WithConfig('app.url', 'https://app.test/')]
    public function fallsBackToTheApplicationUrl(): void
    {
        $this->sandbox(['base_url' => null]);

        $this->assertSame('https://app.test', $this->app->make(CallSandboxTool::class)->baseUrl());
    }

    #[Test]
    public function warnsClientsThatItCallsALiveSandbox(): void
    {
        $definition = $this->app->make(CallSandboxTool::class)->toArray();

        $this->assertFalse($definition['annotations']['readOnlyHint']);
        $this->assertFalse($definition['annotations']['idempotentHint']);
        $this->assertTrue($definition['annotations']['destructiveHint']);
        $this->assertTrue($definition['annotations']['openWorldHint']);
        $this->assertSame(['operationId', 'pathParams', 'query', 'headers', 'body', 'token'], array_keys($definition['inputSchema']['properties']));
        $this->assertSame(['status', 'body', 'truncated'], $definition['outputSchema']['required']);
    }

    #[Test]
    public function keepsItsMockAnnotationsInTheExamplesMode(): void
    {
        $this->sandbox(['mode' => 'examples']);

        $annotations = $this->app->make(CallSandboxTool::class)->toArray()['annotations'];

        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertArrayNotHasKey('destructiveHint', $annotations);
    }

    #[Test]
    public function refusesToBootWhenTheSandboxIsTheProductionUrl(): void
    {
        $this->bootInProduction('https://sandbox.test/', []);
    }

    #[Test]
    public function bootsInProductionWithADifferentSandbox(): void
    {
        $this->bootInProduction('https://app.test', []);

        $this->assertSame('https://sandbox.test', $this->app->make(CallSandboxTool::class)->baseUrl());
    }

    #[Test]
    public function bootsInProductionInTheExamplesMode(): void
    {
        $this->bootInProduction('https://sandbox.test', ['mode' => 'examples']);

        $this->assertFalse($this->app->make(CallSandboxTool::class)->isLive());
    }

    #[Test]
    public function bootsInProductionWithTheSandboxOff(): void
    {
        $this->bootInProduction('https://sandbox.test', ['enabled' => false]);

        $this->assertTrue($this->app->make(CallSandboxTool::class)->isLive());
    }

    /**
     * Boot the package again in production, as an application whose URL is the given one does.
     *
     * @param array<string, mixed> $sandbox
     */
    private function bootInProduction(string $appUrl, array $sandbox): void
    {
        $this->app->get('config')->set(['app.env' => 'production', 'app.url' => $appUrl]);
        $this->sandbox($sandbox);

        if ($appUrl === 'https://sandbox.test/') {
            $this->expectException(UnsafeMcpSandbox::class);
            $this->expectExceptionMessage('https://sandbox.test');
        }

        (new ScribeServiceProvider($this->app))->boot();
    }

    #[Test]
    public function isAdvertisedByTheServer(): void
    {
        $server = $this->app->make(ScribeServer::class, ['transport' => m::mock(Transport::class)]);

        $this->assertContains(CallSandboxTool::class, (fn (): array => $this->tools)->call($server));
    }
}
