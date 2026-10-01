<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Testing;

use FilesystemIterator;
use Hypervel\Testing\PHPUnit\AfterEachTestCleanup;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\MethodAstParser;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Testing\TestState;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils;
use Ipsocode\Scribe\Tools\Globals;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Workbench\App\Http\Controllers\PostController;

/**
 * The package's worker-lifetime state is reset between tests through
 * `extra.hypervel.test-state`, which consuming applications pick up too. If it
 * stops working, one test's `afterGenerating` hook or cached AST silently
 * carries into every later test in the same worker.
 */
class TestStateTest extends TestCase
{
    #[Test]
    public function flushStateClearsTheUserHooksSetThroughTheScribeApi(): void
    {
        Scribe::afterGenerating(fn (array $paths) => null);
        Scribe::normalizeEndpointUrlUsing(fn () => 'x');
        Globals::$shouldBeVerbose = true;

        TestState::flushState();

        $this->assertNull(Globals::$__afterGenerating);
        $this->assertNull(Globals::$__normalizeEndpointUrlUsing);
        $this->assertFalse(Globals::$shouldBeVerbose);
    }

    #[Test]
    public function flushStateClearsTheMethodAstCache(): void
    {
        $method = new ReflectionMethod(PostController::class, 'index');
        $first = MethodAstParser::getMethodAst($method);

        TestState::flushState();

        $this->assertNotSame($first, MethodAstParser::getMethodAst($method));
    }

    #[Test]
    public function flushStateClearsTheRouteDocblockCache(): void
    {
        $route = $this->workbenchRoute('posts.index');

        $first = RouteDocBlocker::getDocBlocksFromRoute($route);

        TestState::flushState();

        $this->assertNotSame($first, RouteDocBlocker::getDocBlocksFromRoute($route));
    }

    #[Test]
    public function flushStateDiscardsBufferedConsoleWarnings(): void
    {
        $buffer = new BufferedOutput;
        ConsoleOutputUtils::bootstrapOutput($buffer);
        ConsoleOutputUtils::startWarningBuffer();
        ConsoleOutputUtils::warn('deferred warning');

        TestState::flushState();

        // Both the buffered warning and the "currently buffering" flag are
        // worker-lifetime statics. A leak here means a warning raised while
        // generating one test's docs is emitted during another's.
        ConsoleOutputUtils::bootstrapOutput($buffer);
        ConsoleOutputUtils::flushWarningBuffer();

        $this->assertSame('', $buffer->fetch());
    }

    /**
     * Static properties written only at their declaration (fixed lookup tables
     * such as attribute-class registries or a CSS-colour map), so they carry no
     * worker-lifetime state and need no flushState() entry. Check that a
     * property is never reassigned before adding it here.
     *
     * @var string[] "Class::$property" pairs
     */
    private const array IMMUTABLE_STATIC_ALLOWLIST = [
        'Ipsocode\Scribe\Extracting\Strategies\PhpAttributeStrategy::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\QueryParameters\GetFromQueryParamAttribute::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\BodyParameters\GetFromBodyParamAttribute::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\Metadata\GetFromMetadataAttributes::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\Responses\UseResponseAttributes::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\ResponseFields\GetFromResponseFieldAttribute::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\Headers\GetFromHeaderAttribute::attributeNames',
        'Ipsocode\Scribe\Extracting\Strategies\UrlParameters\GetFromUrlParamAttribute::attributeNames',
        'Ipsocode\Scribe\Tools\WritingUtils::httpMethodToCssColour',
    ];

    #[Test]
    public function flushStateResetsEveryClassThePackageOwns(): void
    {
        // A regression here is a class gaining static state without being added
        // to TestState — the failure mode the registrar exists to prevent.
        foreach ([
            Globals::class,
            ConsoleOutputUtils::class,
            Extractor::class,
            MethodAstParser::class,
            RouteDocBlocker::class,
        ] as $class) {
            $this->assertTrue(
                method_exists($class, 'flushState'),
                "[{$class}] is listed in TestState but has no flushState()."
            );
        }
    }

    #[Test]
    public function everyMutableStaticPropertyDeclaredInSrcHasAFlushState(): void
    {
        // src/Reflection is re-namespaced third-party (phpDocumentor) code,
        // outside this package's coroutine-safety conventions.
        $excludedNamespacePrefix = 'Ipsocode\Scribe\Reflection\\';

        $srcDir = dirname(__DIR__, 3) . '/src';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            require_once $file->getPathname();
        }

        foreach (array_merge(get_declared_classes(), get_declared_traits()) as $fqcn) {
            if (! str_starts_with($fqcn, 'Ipsocode\Scribe\\') || str_starts_with($fqcn, $excludedNamespacePrefix)) {
                continue;
            }

            $reflection = new ReflectionClass($fqcn);
            if ($reflection->getFileName() === false || ! str_starts_with($reflection->getFileName(), $srcDir)) {
                continue;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $fqcn) {
                    continue; // Only this class/trait's own declaration, not an inherited one.
                }

                $key = "{$fqcn}::{$property->getName()}";
                if (in_array($key, self::IMMUTABLE_STATIC_ALLOWLIST, true)) {
                    continue;
                }

                if (! method_exists($fqcn, 'flushState')) {
                    $offenders[] = $key;
                }
            }
        }

        $this->assertSame([], $offenders, 'Static property holder(s) without a flushState() (add one and register it in TestState, or add to IMMUTABLE_STATIC_ALLOWLIST if truly never reassigned): ' . implode(', ', $offenders));
    }

    #[Test]
    public function thePackageStillAdvertisesItsTestStateRegistrar(): void
    {
        // Losing this key means consuming applications stop getting the cleanup,
        // with nothing in this package's own suite to notice.
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        $this->assertContains(TestState::class, $composer['extra']['hypervel']['test-state']);
    }

    #[Test]
    public function registeringHooksTheFlushIntoTheFrameworksAfterEachCleanup(): void
    {
        // Registration normally happens during PHPUnit's bootstrap; redoing it
        // here asserts the wiring rather than assuming it.
        AfterEachTestCleanup::forget('ipsocode/hypervel-scribe');

        TestState::register();

        Globals::$shouldBeVerbose = true;
        AfterEachTestCleanup::runCallbacks();

        $this->assertFalse(Globals::$shouldBeVerbose);
    }
}
