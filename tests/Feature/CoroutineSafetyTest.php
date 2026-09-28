<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\RequestContext;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Extracting\Shared\ApiResourceResponseTools;
use Ipsocode\Scribe\Extracting\Strategies\Strategy;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Ipsocode\Scribe\Tools\Globals;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Swoole\Coroutine;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Terminal;
use Workbench\App\Http\Controllers\PostController;

use function Hypervel\Coroutine\parallel;

/**
 * Scribe is a one-shot CLI generator that never serves concurrent requests, so
 * the few caches it keeps are deliberately plain worker-lifetime statics rather
 * than coroutine-scoped state. This suite locks in that design: each cache must
 * cache within a run, every stateful class must reset via flushState(), and the
 * statics must remain visible (shared) across coroutines on the same worker.
 *
 * These live in the Feature suite rather than Unit because Testbench runs every
 * test method inside a real Swoole coroutine — which is exactly the environment
 * the claims below are about, and is not available to a `#[UnitTest]` method's
 * framework-free world.
 */
class CoroutineSafetyTest extends TestCase
{
    #[Test]
    public function theSuitePinsTerminalDimensionsSoConsoleOutputNeverShellsOut(): void
    {
        // Left unset, Symfony Console runs `stty` to size the generate
        // command's task and warning output. Inside this coroutine that
        // subprocess wait goes through Swoole's hooks, and under ParaTest it
        // can lose the child's exit and hang the worker. phpunit.xml pins both
        // values; this stops them being dropped without anyone noticing.
        $this->assertSame('80', getenv('COLUMNS'));
        $this->assertSame('24', getenv('LINES'));
        $this->assertSame(80, (new Terminal)->getWidth());
        $this->assertSame(24, (new Terminal)->getHeight());
    }

    #[Test]
    public function userHooksSetViaTheScribeApiAreClearedByFlushState(): void
    {
        Scribe::afterGenerating(fn (array $paths) => null);
        Scribe::normalizeEndpointUrlUsing(fn () => 'x');
        Globals::$shouldBeVerbose = true;

        $this->assertNotNull(Globals::$__afterGenerating);
        $this->assertNotNull(Globals::$__normalizeEndpointUrlUsing);

        Globals::flushState();

        $this->assertNull(Globals::$__afterGenerating);
        $this->assertNull(Globals::$__normalizeEndpointUrlUsing);
        $this->assertFalse(Globals::$shouldBeVerbose);
    }

    #[Test]
    public function consoleOutputBuffersWarningsThenFlushesThem(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);

        ConsoleOutputUtils::startWarningBuffer();
        ConsoleOutputUtils::warn('deferred warning');

        // While buffering, nothing is written to the output.
        $this->assertStringNotContainsString('deferred warning', $buffer->fetch());

        ConsoleOutputUtils::flushWarningBuffer();
        $this->assertStringContainsString('deferred warning', $buffer->fetch());
    }

    #[Test]
    public function consoleOutputTaskReturnsTheCallbackResult(): void
    {
        ConsoleOutputUtils::bootstrapOutput(new BufferedOutput);

        // With no command bound, task() falls back to running the callback inline.
        $this->assertSame(42, ConsoleOutputUtils::task('do the thing', fn () => 42));
    }

    #[Test]
    public function methodAstParserCachesWithinARunAndResetsOnFlush(): void
    {
        MethodAstParser::flushState();
        $method = new ReflectionMethod(PostController::class, 'index');

        $first = MethodAstParser::getMethodAst($method);
        $second = MethodAstParser::getMethodAst($method);

        $this->assertInstanceOf(ClassMethod::class, $first);
        $this->assertSame('index', $first->name->toString());
        // Same cached node instance is returned on subsequent calls.
        $this->assertSame($first, $second);

        MethodAstParser::flushState();
        $afterFlush = MethodAstParser::getMethodAst($method);

        // After a flush the AST is parsed afresh (a different instance).
        $this->assertNotSame($first, $afterFlush);
        $this->assertSame('index', $afterFlush->name->toString());
    }

    #[Test]
    public function coroutineContextIsScopedToTheRunningCoroutine(): void
    {
        // Testbench invokes each test method inside a coroutine, so this is the
        // real coroutine-scoped branch of CoroutineContext, not its
        // non-coroutine static fallback.
        $this->assertGreaterThan(0, Coroutine::getCid());

        CoroutineContext::set('scribe.coroutine_safety_test', 'value');

        $this->assertTrue(CoroutineContext::has('scribe.coroutine_safety_test'));
        $this->assertSame('value', CoroutineContext::get('scribe.coroutine_safety_test'));

        // A child coroutine gets a fresh context: nothing Scribe keeps may rely
        // on context inheritance.
        [$childSees] = parallel([fn () => CoroutineContext::has('scribe.coroutine_safety_test')]);
        $this->assertFalse($childSees);

        CoroutineContext::forget('scribe.coroutine_safety_test');
        $this->assertFalse(CoroutineContext::has('scribe.coroutine_safety_test'));
    }

    #[Test]
    public function astCacheIsSharedAcrossCoroutinesOnTheSameWorker(): void
    {
        MethodAstParser::flushState();
        $method = new ReflectionMethod(PostController::class, 'index');

        // Prime the cache in the test's own coroutine first so the reads below
        // are deterministic cache hits rather than racing parses.
        $mainId = spl_object_id(MethodAstParser::getMethodAst($method));

        $results = parallel([
            fn () => spl_object_id(MethodAstParser::getMethodAst($method)),
            fn () => spl_object_id(MethodAstParser::getMethodAst($method)),
        ]);

        // Each coroutine sees the same worker-global cached node, proving the
        // cache is a plain static and not coroutine-scoped.
        $this->assertSame($mainId, $results[0]);
        $this->assertSame($mainId, $results[1]);
    }

    #[Test]
    public function userHooksAreVisibleInsideCoroutines(): void
    {
        Scribe::afterGenerating(fn (array $paths) => null);

        $results = parallel([
            fn () => Globals::$__afterGenerating !== null,
            fn () => Globals::$__afterGenerating !== null,
        ]);

        $this->assertTrue($results[0]);
        $this->assertTrue($results[1]);
    }

    #[Test]
    public function apiResourceResponseRestoresThePreviousRequestContextEvenWhenTheResourceThrows(): void
    {
        // ApiResourceResponseTools swaps in a synthetic request around
        // toResponse() so example responses reflect the documented route
        // rather than whatever real request is in flight. That swap must be
        // undone even when the resource throws, or the fake request leaks
        // into every later coroutine on the worker.
        $previousRequest = Request::create('/previous-request-in-context');
        RequestContext::set($previousRequest);

        $endpointData = ExtractedEndpointData::fromRoute($this->workbenchRoute('posts.show'));
        $resource = new class([]) extends JsonResource {
            public function toResponse(Request $request): JsonResponse
            {
                throw new RuntimeException('The resource blew up.');
            }
        };

        try {
            ApiResourceResponseTools::callApiResourceAndGetResponse($resource, $endpointData);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('The resource blew up.', $e->getMessage());
        }

        $this->assertSame($previousRequest, RequestContext::get());
    }

    #[Test]
    public function extractorClearsTheRouteBeingProcessedEvenWhenAStrategyThrows(): void
    {
        // Extractor::$routeBeingProcessed is worker-global; a throwing strategy
        // that skips the clear would leave it pointing at a route from a run
        // that already failed, misreporting it as still being processed.
        config(['scribe.strategies.metadata' => [ThrowingMetadataStrategy::class]]);

        $extractor = new Extractor(new DocumentationConfig(config('scribe')));
        $route = $this->workbenchRoute('posts.index');

        try {
            $extractor->processRoute($route);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('The strategy blew up.', $e->getMessage());
        }

        $this->assertNull(Extractor::getRouteBeingProcessed());
    }
}

class ThrowingMetadataStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        throw new RuntimeException('The strategy blew up.');
    }
}
