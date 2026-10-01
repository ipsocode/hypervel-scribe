<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use InvalidArgumentException;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Camel\Extraction\ResponseCollection;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\Responses\ResponseCalls;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\ResponseCallController;
use Workbench\App\Models\Post;

use function Hypervel\Testbench\workbench_path;

/**
 * The strategy that builds a request from what earlier stages extracted, sends
 * it through the HTTP kernel, and records the response. The endpoints
 * ({@see ResponseCallController}) echo the request back, and are registered
 * here so the documented Workbench API keeps its shape.
 * See docs/design/response-calls.md.
 */
class ResponseCallsTest extends DatabaseTestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/response-calls/echo', [ResponseCallController::class, 'echo'])
            ->name('response-calls.echo');
        $router->post('api/response-calls/echo', [ResponseCallController::class, 'echo'])
            ->name('response-calls.echo-post');
        $router->get('api/response-calls/echo/{id}', [ResponseCallController::class, 'show'])
            ->name('response-calls.show');
        $router->post('api/response-calls/persist', [ResponseCallController::class, 'persist'])
            ->name('response-calls.persist');
        $router->get('api/response-calls/stream', [ResponseCallController::class, 'stream'])
            ->name('response-calls.stream');
        $router->get('api/response-calls/slow', [ResponseCallController::class, 'slow'])
            ->name('response-calls.slow');
        $router->get('api/response-calls/failing', [ResponseCallController::class, 'failing'])
            ->name('response-calls.failing');
        $router->domain('docs.example.test')
            ->get('api/response-calls/on-a-domain', [ResponseCallController::class, 'echo'])
            ->name('response-calls.domain');
    }

    /**
     * Extract one endpoint with `ResponseCalls` as the *only* response strategy.
     *
     * Anything else in the stage would document a response of its own, and the
     * strategy skips an endpoint that already has a successful one.
     */
    private function extract(string $routeName, array $settings = []): ExtractedEndpointData
    {
        config(['scribe.strategies.responses' => [ResponseCalls::withSettings(...$settings)]]);

        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    private function response(string $routeName, array $settings = []): array
    {
        $responses = $this->extract($routeName, $settings)->responses->toArray();

        $this->assertCount(1, $responses, "No response was captured for [{$routeName}].");

        return $responses[0];
    }

    /**
     * The echoed request, as the controller saw it.
     */
    private function echoed(string $routeName, array $settings = []): array
    {
        return json_decode($this->response($routeName, $settings)['content'], true);
    }

    private function enableAuth(string $in, string $name = 'key'): void
    {
        config([
            'scribe.auth.enabled' => true,
            'scribe.auth.default' => true,
            'scribe.auth.in' => $in,
            'scribe.auth.name' => $name,
            'scribe.auth.use_value' => 'the-real-token',
            'scribe.auth.placeholder' => '{YOUR_AUTH_KEY}',
        ]);
    }

    #[Test]
    public function aRealResponseIsCapturedFromTheRoute(): void
    {
        $response = $this->response('response-calls.echo');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('application/json', $response['headers']['content-type']);

        $echoed = json_decode($response['content'], true);
        $this->assertSame('GET', $echoed['method']);
        $this->assertSame('/api/response-calls/echo', $echoed['path']);
    }

    #[Test]
    public function theExtractedExampleValuesAreWhatGetSent(): void
    {
        // `@queryParam filter ... Example: published` on the controller — the
        // whole point of the strategy running last is that it can use what the
        // earlier stages worked out.
        $this->assertSame('published', $this->echoed('response-calls.echo')['query']['filter']);
    }

    #[Test]
    public function urlParametersAreBoundIntoThePath(): void
    {
        // `@urlParam id ... Example: 7`.
        $this->assertSame(7, $this->echoed('response-calls.show')['id']);
    }

    #[Test]
    public function extraQueryAndBodyParametersFromTheSettingsAreSentToo(): void
    {
        $echoed = $this->echoed('response-calls.echo-post', [
            'queryParams' => ['page' => '3'],
            'bodyParams' => ['title' => 'From the settings'],
        ]);

        $this->assertSame('3', $echoed['query']['page']);
        $this->assertSame('From the settings', $echoed['body']['title']);
    }

    #[Test]
    public function cookiesFromTheSettingsAreSent(): void
    {
        $echoed = $this->echoed('response-calls.echo', ['cookies' => ['tenant' => 'acme']]);

        $this->assertSame('acme', $echoed['cookies']['tenant']);
    }

    #[Test]
    public function fileParametersFromTheSettingsAreUploaded(): void
    {
        $echoed = $this->echoed('response-calls.echo-post', [
            'fileParams' => ['attachment' => workbench_path('storage', 'responses', 'comment.json')],
        ]);

        $this->assertSame(['attachment'], $echoed['files']);
    }

    #[Test]
    public function configOverridesApplyDuringTheCallOnly(): void
    {
        $before = config('app.name');

        $echoed = $this->echoed('response-calls.echo', [
            'config' => ['app.name' => 'Renamed for the call'],
        ]);

        $this->assertSame('Renamed for the call', $echoed['appName']);
        // The whole point of recording the previous value: generation carries on
        // afterwards, and every later endpoint would otherwise see the override.
        $this->assertSame($before, config('app.name'));
    }

    #[Test]
    public function theAuthValueIsSentAsAHeader(): void
    {
        $this->enableAuth('bearer');

        $echoed = $this->echoed('response-calls.echo');

        // The docs show the placeholder; the call sends the real thing.
        $this->assertSame('Bearer the-real-token', $echoed['authorization']);
    }

    #[Test]
    public function theAuthValueIsSentAsAQueryParameter(): void
    {
        $this->enableAuth('query', 'api_key');

        $this->assertSame('the-real-token', $this->echoed('response-calls.echo')['query']['api_key']);
    }

    #[Test]
    public function theAuthValueIsSentInTheBody(): void
    {
        $this->enableAuth('body', 'api_key');

        $this->assertSame('the-real-token', $this->echoed('response-calls.echo-post')['body']['api_key']);
    }

    #[Test]
    public function anAuthLocationNobodyKnowsIsRejected(): void
    {
        $endpointData = ExtractedEndpointData::fromRoute($this->workbenchRoute('response-calls.echo'));
        $endpointData->auth = ['carrierPigeon', 'key', 'value'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown auth location: carrierPigeon');

        $this->strategy()->makeResponseCall($endpointData, []);
    }

    #[Test]
    public function anEndpointThatAlreadyHasASuccessResponseIsLeftAlone(): void
    {
        $endpointData = ExtractedEndpointData::fromRoute($this->workbenchRoute('response-calls.echo'));
        $endpointData->responses = new ResponseCollection([
            ['status' => 200, 'content' => '{"documented":true}', 'description' => 'OK'],
        ]);

        $this->assertNull(($this->strategy())($endpointData, []));
    }

    #[Test]
    public function writesMadeByTheCallAreRolledBack(): void
    {
        config(['scribe.database_connections_to_transact' => [config('database.default')]]);

        $before = Post::query()->count();

        // The row is really there while the endpoint is running...
        $this->assertSame($before + 1, $this->echoed('response-calls.persist')['count']);
        // ...and gone once the strategy has rolled its transaction back.
        $this->assertSame($before, Post::query()->count());
    }

    #[Test]
    public function aStreamedBodyIsCapturedRatherThanLost(): void
    {
        // A streamed response has no content until something sends it, and it
        // echoes rather than returns — including a chunk flushed part-way.
        $this->assertSame('first-chunk;second-chunk', $this->response('response-calls.stream')['content']);
    }

    #[Test]
    public function aFailingCallIsWarnedAboutAndDocumentsNothing(): void
    {
        // Without this the kernel renders the exception into a 500 and the
        // strategy dutifully documents it; the interesting path is the one where
        // the call itself blows up.
        $this->withoutExceptionHandling();

        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);

        $this->assertCount(0, $this->extract('response-calls.failing')->responses);
        $this->assertStringContainsString('Exception thrown during response call', $buffer->fetch());
    }

    #[Test]
    public function aCallThatOutlivesItsTimeoutIsAbandoned(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);

        $this->assertCount(0, $this->extract('response-calls.slow', ['timeout' => 0.1])->responses);
        $this->assertStringContainsString('Exception thrown during response call', $buffer->fetch());
    }

    #[Test]
    public function theBeforeAndAfterHooksSeeTheRequestAndTheResponse(): void
    {
        $seen = [];

        Scribe::beforeResponseCall(function (Request $request, ExtractedEndpointData $endpointData) use (&$seen) {
            $seen['before'] = $endpointData->uri;
            $request->headers->set('Api-Key', 'set-by-the-hook');
        });
        Scribe::afterResponseCall(function (Request $request, ExtractedEndpointData $endpointData, $response) use (&$seen) {
            $seen['after'] = $response->getStatusCode();
        });

        $echoed = $this->echoed('response-calls.echo');

        $this->assertSame('api/response-calls/echo', $seen['before']);
        $this->assertSame(200, $seen['after']);
        // The hook runs before the request is sent, so its edits are part of it.
        $this->assertSame('set-by-the-hook', $echoed['apiKeyHeader']);
    }

    #[Test]
    public function aRouteBoundToADomainIsCalledOnThatDomain(): void
    {
        // The request is built against `app.url` like every other one, so a
        // route the router only answers on its own domain would 404 unless the
        // Host is put back afterwards.
        $echoed = $this->echoed('response-calls.domain');

        $this->assertSame('/api/response-calls/on-a-domain', $echoed['path']);
    }

    private function strategy(): ResponseCalls
    {
        return new ResponseCalls(new DocumentationConfig(config('scribe')));
    }
}
