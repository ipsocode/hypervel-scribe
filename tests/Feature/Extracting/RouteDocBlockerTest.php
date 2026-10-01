<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Exception;
use Ipsocode\Scribe\Extracting\RouteDocBlocker;
use Ipsocode\Scribe\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use ReflectionMethod;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Requests\UndocumentedRequest;

/**
 * Every docblock-reading strategy calls RouteDocBlocker several times per
 * endpoint, so results are cached per route and each docblock is parsed once.
 * The cache key survives both shapes a caller can name a method in, and a
 * missing method is reported in terms of the route.
 */
class RouteDocBlockerTest extends TestCase
{
    #[Test]
    public function readsTheMethodAndClassDocblocksOffARoute(): void
    {
        $docBlocks = RouteDocBlocker::getDocBlocksFromRoute($this->workbenchRoute('posts.index'));

        $this->assertStringContainsString('posts', $docBlocks['method']->getShortDescription());
        $this->assertNotEmpty($docBlocks['class']->getTags());
    }

    #[Test]
    public function acceptsAClassAndMethodPairInPlaceOfTwoArguments(): void
    {
        // `Utils::getRouteClassAndMethodNames()` hands back a pair, and callers
        // pass it straight through rather than unpacking it first.
        $route = $this->workbenchRoute('posts.index');

        $this->assertEquals(
            RouteDocBlocker::getDocBlocks($route, PostController::class, 'index'),
            RouteDocBlocker::getDocBlocks($route, [PostController::class, 'index']),
        );
    }

    #[Test]
    public function theSecondReadOfARouteComesFromTheCache(): void
    {
        $route = $this->workbenchRoute('posts.show');

        $this->assertSame(
            RouteDocBlocker::getDocBlocksFromRoute($route),
            RouteDocBlocker::getDocBlocksFromRoute($route),
        );
    }

    #[Test]
    public function endpointsOnOneControllerShareOneParseOfItsClassDocblock(): void
    {
        // The class docblock belongs to the controller, not to a route, so a
        // second endpoint on the same controller does not parse it again.
        $this->assertSame(
            RouteDocBlocker::getDocBlocksFromRoute($this->workbenchRoute('posts.index'))['class'],
            RouteDocBlocker::getDocBlocksFromRoute($this->workbenchRoute('posts.show'))['class'],
        );
    }

    #[Test]
    public function aClassOrMethodIsParsedOnceHoweverItIsNamed(): void
    {
        $this->assertSame(RouteDocBlocker::forClass(PostController::class), RouteDocBlocker::forClass(new PostController));
        $this->assertSame(
            RouteDocBlocker::forMethod(new ReflectionMethod(PostController::class, 'index')),
            RouteDocBlocker::forMethod(new ReflectionMethod(PostController::class, 'index')),
        );
    }

    #[Test]
    public function aClassWithNoDocCommentReadsAsAnEmptyDocblock(): void
    {
        $docBlock = RouteDocBlocker::forClass(UndocumentedRequest::class);

        $this->assertSame([], $docBlock->getTags());
        $this->assertSame('', $docBlock->getShortDescription());
    }

    #[Test]
    public function aClosuresDocblockIsParsedEveryTime(): void
    {
        // A closure has no name to key a cache on; two closures on one line
        // would otherwise share an entry.
        $closure = new ReflectionFunction(
            /** A closure. */
            fn () => null
        );

        $this->assertSame('A closure.', RouteDocBlocker::forMethod($closure)->getShortDescription());
        $this->assertNotSame(RouteDocBlocker::forMethod($closure), RouteDocBlocker::forMethod($closure));
    }

    #[Test]
    public function namesTheRouteWhenTheMethodDoesNotExist(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('api/posts');

        RouteDocBlocker::getDocBlocks($this->workbenchRoute('posts.index'), PostController::class, 'noSuchMethod');
    }
}
