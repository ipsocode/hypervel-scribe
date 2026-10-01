<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Tools;

use Closure;
use Exception;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\File;
use Ipsocode\Scribe\Exceptions\CouldntFindFactory;
use Ipsocode\Scribe\Exceptions\CouldntGetRouteDetails;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\Utils;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;
use Workbench\App\Models\User;

/**
 * The Utils helpers that need a real application: reading a controller and
 * method off a registered route, reflecting it, resolving a model factory, and
 * the filesystem helpers. The pure string/array helpers live in the Unit suite.
 */
class UtilsTest extends DatabaseTestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        // Outside the working directory on purpose: the helpers take absolute
        // paths as given rather than re-rooting them under getcwd().
        $this->scratch = sys_get_temp_dir() . '/scribe-utils-test-' . getmypid();
        File::deleteDirectory($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    #[Test]
    public function readsTheControllerAndMethodOffAnArrayActionRoute(): void
    {
        $this->assertSame(
            [PostController::class, 'index'],
            Utils::getRouteClassAndMethodNames($this->workbenchRoute('posts.index')),
        );
    }

    #[Test]
    public function readsTheClosureOffAClosureRoute(): void
    {
        [$uses, $method] = Utils::getRouteClassAndMethodNames($this->workbenchRoute('home'));

        $this->assertInstanceOf(Closure::class, $uses);
        $this->assertSame('__invoke', $method);
    }

    #[Test]
    public function readsAControllerWrittenInTheAtSyntax(): void
    {
        // Hypervel's Route constructor rejects a bare string action, but the
        // `uses` key inside an array action still carries the
        // `Controller@method` form — which is the branch under test.
        $route = new Route(['GET'], 'api/legacy', ['uses' => PostController::class . '@show']);

        $this->assertSame([PostController::class, 'show'], Utils::getRouteClassAndMethodNames($route));
    }

    #[Test]
    public function rejectsAnAtSyntaxActionWithNoMethod(): void
    {
        // Hypervel's own RouteAction::parse() rejects this at registration, so
        // the action is set after the fact: the branch is Scribe's defence
        // against an action array assembled by something other than the router.
        $route = (new Route(['GET'], 'api/legacy', fn () => null))
            ->setAction(['uses' => PostController::class]);

        $this->expectException(CouldntGetRouteDetails::class);

        Utils::getRouteClassAndMethodNames($route);
    }

    #[Test]
    public function readsAnArrayActionSetDirectlyOnTheRoute(): void
    {
        // Hypervel's router normalises `[Controller::class, 'method']` into the
        // `Controller@method` string, but an action assembled by hand — or by
        // another package — can leave the pair in place.
        $route = (new Route(['GET'], 'api/legacy', fn () => null))
            ->setAction(['uses' => [PostController::class, 'show']]);

        $this->assertSame([PostController::class, 'show'], Utils::getRouteClassAndMethodNames($route));
    }

    #[Test]
    public function readsAnActionHeldAsAPositionalPair(): void
    {
        $route = (new Route(['GET'], 'api/legacy', fn () => null))
            ->setAction(['uses' => null, PostController::class, 'show']);

        $this->assertSame([PostController::class, 'show'], Utils::getRouteClassAndMethodNames($route));
    }

    #[Test]
    public function prefersTheAsControllerMethodOfALaravelAction(): void
    {
        // The Laravel Actions package routes to `__invoke`, but the docblock a
        // user writes lives on `asController` — reading `__invoke` would
        // document nothing.
        $route = (new Route(['GET'], 'api/action', fn () => null))
            ->setAction(['uses' => LaravelStyleAction::class . '@__invoke']);

        $this->assertSame([LaravelStyleAction::class, 'asController'], Utils::getRouteClassAndMethodNames($route));
    }

    #[Test]
    public function rejectsAnActionItCannotReadAtAll(): void
    {
        $route = (new Route(['GET'], 'api/legacy', fn () => null))->setAction(['uses' => null]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('api/legacy');

        Utils::getRouteClassAndMethodNames($route);
    }

    #[Test]
    public function reflectsAControllerMethod(): void
    {
        $reflected = Utils::getReflectedRouteMethod([PostController::class, 'show']);

        $this->assertInstanceOf(ReflectionMethod::class, $reflected);
        $this->assertSame('show', $reflected->getName());
    }

    #[Test]
    public function reflectsAClosure(): void
    {
        $reflected = Utils::getReflectedRouteMethod([fn (int $id) => $id, '__invoke']);

        $this->assertInstanceOf(ReflectionFunction::class, $reflected);
    }

    #[Test]
    public function rejectsARouteActionItCannotReflect(): void
    {
        $this->expectException(CouldntGetRouteDetails::class);

        Utils::getReflectedRouteMethod([PostController::class]);
    }

    #[Test]
    public function resolvesAModelFactory(): void
    {
        $post = Utils::getModelFactory(Post::class)->make();

        $this->assertInstanceOf(Post::class, $post);
        $this->assertNotEmpty($post->title);
    }

    #[Test]
    public function toleratesALeadingBackslashInTheModelName(): void
    {
        // Users write `@apiResourceModel \App\Models\Post`, so the leading
        // slash has to be stripped before `method_exists` is consulted.
        $this->assertInstanceOf(Post::class, Utils::getModelFactory('\\' . Post::class)->make());
    }

    #[Test]
    public function appliesTheRequestedFactoryStates(): void
    {
        $this->assertTrue(Utils::getModelFactory(Post::class, ['published'])->make()->published);
    }

    #[Test]
    public function ignoresAStateTheFactoryDoesNotDefine(): void
    {
        // A typo'd `factoryStates:` should still produce a model rather than
        // failing the whole generation run.
        $this->assertInstanceOf(Post::class, Utils::getModelFactory(Post::class, ['no_such_state'])->make());
    }

    #[Test]
    public function buildsABelongsToRelationThroughTheFactory(): void
    {
        $post = Utils::getModelFactory(Post::class, [], ['author'])->create();

        $this->assertInstanceOf(User::class, $post->author);
    }

    #[Test]
    public function buildsAHasManyRelationThroughTheFactory(): void
    {
        $user = Utils::getModelFactory(User::class, [], ['posts'])->create();

        $this->assertCount(1, $user->posts);
    }

    #[Test]
    public function buildsABelongsToManyRelationWithItsPivotAttributes(): void
    {
        // `hasAttached()` is a different call from `has()`, and the factory's
        // `pivotTags()` method is what fills the pivot row.
        $post = Utils::getModelFactory(Post::class, [], ['tags'])->create();

        $this->assertCount(1, $post->tags);
        $this->assertSame('scribe', $post->tags->first()->pivot->added_by);
    }

    #[Test]
    public function aBelongsToManyWithNoPivotMethodStillAttaches(): void
    {
        // The `pivot<Relation>` method is optional; without one the pivot row
        // is created with its defaults rather than the relation being skipped.
        $tag = Utils::getModelFactory(Tag::class, [], ['posts'])->create();

        $this->assertCount(1, $tag->posts);
    }

    #[Test]
    public function buildsNestedRelationsThroughASingleFactoryCall(): void
    {
        // `with=posts.author` has to reach the author *of the posts*, not add a
        // second unrelated relation to the user.
        $user = Utils::getModelFactory(User::class, [], ['posts.author'])->create();

        $this->assertInstanceOf(User::class, $user->posts->first()->author);
    }

    #[Test]
    public function mergesRelationsThatShareAParent(): void
    {
        // Two paths under `posts` are one `has(posts)` call with both children,
        // not two calls where the second overwrites the first.
        $user = Utils::getModelFactory(User::class, [], ['posts.author', 'posts.tags'])->create();

        $post = $user->posts->first();

        $this->assertInstanceOf(User::class, $post->author);
        $this->assertCount(1, $post->tags);
    }

    #[Test]
    public function reportsAModelWithNoFactory(): void
    {
        $this->expectException(CouldntFindFactory::class);

        Utils::getModelFactory(FactorylessModel::class);
    }

    #[Test]
    public function createsADirectoryRecursivelyAndIsIdempotent(): void
    {
        Utils::makeDirectoryRecursive($this->scratch . '/a/b');
        $this->assertDirectoryExists($this->scratch . '/a/b');

        // Called on every write, so a second call must not fail.
        Utils::makeDirectoryRecursive($this->scratch . '/a/b');
        $this->assertDirectoryExists($this->scratch . '/a/b');
    }

    #[Test]
    public function copiesADirectoryTree(): void
    {
        File::makeDirectory($this->scratch . '/src/nested', 0o777, true);
        File::put($this->scratch . '/src/top.txt', 'top');
        File::put($this->scratch . '/src/nested/deep.txt', 'deep');

        Utils::copyDirectory($this->scratch . '/src', $this->scratch . '/dest');

        $this->assertSame('top', File::get($this->scratch . '/dest/top.txt'));
        $this->assertSame('deep', File::get($this->scratch . '/dest/nested/deep.txt'));
    }

    #[Test]
    public function reportsADestinationDirectoryItCannotCreate(): void
    {
        File::makeDirectory($this->scratch, 0o777, true);
        File::put($this->scratch . '/src', 'not a directory');
        File::makeDirectory($this->scratch . '/from', 0o777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create directory');

        // The destination's parent is a file, so it cannot be created.
        Utils::copyDirectory($this->scratch . '/from', $this->scratch . '/src/dest');
    }

    #[Test]
    public function reportsAFileItCannotCopy(): void
    {
        File::makeDirectory($this->scratch . '/from', 0o777, true);
        File::put($this->scratch . '/from/clash.txt', 'file');
        // A directory where the copied file has to go.
        File::makeDirectory($this->scratch . '/dest/clash.txt', 0o777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to copy');

        // copy() warns before it returns false, and PHPUnit would turn that
        // warning into a failure before the return value is ever checked.
        set_error_handler(static fn (): bool => true);

        try {
            Utils::copyDirectory($this->scratch . '/from', $this->scratch . '/dest');
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function copyingAMissingSourceDirectoryIsANoOp(): void
    {
        Utils::copyDirectory($this->scratch . '/missing', $this->scratch . '/dest');

        $this->assertDirectoryDoesNotExist($this->scratch . '/dest');
    }

    #[Test]
    public function deletesADirectoryAndEverythingInIt(): void
    {
        File::makeDirectory($this->scratch . '/nested', 0o777, true);
        File::put($this->scratch . '/one.txt', '1');
        File::put($this->scratch . '/nested/two.md', '2');

        Utils::deleteDirectoryAndContents($this->scratch);

        $this->assertDirectoryDoesNotExist($this->scratch);

        // A second delete, of a directory that is already gone, is not an error.
        Utils::deleteDirectoryAndContents($this->scratch);
        $this->assertDirectoryDoesNotExist($this->scratch);
    }

    #[Test]
    public function deletesOnlyTheFilesMatchingTheCondition(): void
    {
        File::makeDirectory($this->scratch . '/nested', 0o777, true);
        File::put($this->scratch . '/keep.md', 'keep');
        File::put($this->scratch . '/drop.txt', 'drop');
        File::put($this->scratch . '/.hidden.txt', 'drop');
        File::put($this->scratch . '/nested/deeper.txt', 'keep');

        Utils::deleteFilesMatching(
            $this->scratch,
            fn (SplFileInfo $file) => $file->getExtension() === 'txt',
        );

        $this->assertFileExists($this->scratch . '/keep.md');
        $this->assertFileDoesNotExist($this->scratch . '/drop.txt');
        $this->assertFileDoesNotExist($this->scratch . '/.hidden.txt');
        // Only the top level is considered.
        $this->assertFileExists($this->scratch . '/nested/deeper.txt');
    }

    #[Test]
    public function deletingFilesFromAMissingDirectoryIsANoOp(): void
    {
        Utils::deleteFilesMatching($this->scratch . '/missing', fn () => true);

        $this->assertDirectoryDoesNotExist($this->scratch . '/missing');
    }
}

/**
 * Stands in for a Laravel Actions action: routed to `__invoke`, documented on
 * `asController`.
 */
class LaravelStyleAction
{
    public function __invoke(): array
    {
        return [];
    }

    public function asController(): array
    {
        return [];
    }
}

/**
 * A model with no factory, for the CouldntFindFactory path.
 */
class FactorylessModel extends \Hypervel\Database\Eloquent\Model
{
}
