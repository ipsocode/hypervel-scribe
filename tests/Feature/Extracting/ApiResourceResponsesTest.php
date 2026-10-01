<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Shared\ApiResourceResponseTools;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\PostController;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Models\Post;

/**
 * API resource responses are the part of extraction that actually builds
 * objects — a model out of a factory, a paginator around it, and the resource
 * rendered through a synthetic request. Almost everything that can go wrong
 * during a real generation run goes wrong here.
 */
class ApiResourceResponsesTest extends DatabaseTestCase
{
    /**
     * Kept out of `workbench/routes/api.php` so the documented API the writer
     * and generate-command tests assert against stays as it is.
     */
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/posts-empty-resource', [PostController::class, 'emptyResource'])
            ->name('posts.emptyResource');
    }

    private function extract(string $routeName): ExtractedEndpointData
    {
        return (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute($routeName));
    }

    private function body(string $routeName, int $status = 200): array
    {
        $responses = collect($this->extract($routeName)->responses->toArray())->keyBy('status');

        return json_decode($responses[$status]['content'], true);
    }

    #[Test]
    public function aLengthAwarePaginatedCollectionCarriesPaginationMetadata(): void
    {
        $body = $this->body('posts.paginated');

        $this->assertCount(2, $body['data']);
        $this->assertArrayHasKey('links', $body);
        $this->assertSame(2, $body['meta']['per_page']);
        // `additional:` is merged in on top of the paginator's own metadata.
        $this->assertTrue($body['meta']['generated']);
    }

    #[Test]
    public function aSimplePaginatedCollectionOmitsTheTotal(): void
    {
        $body = $this->body('posts.simple');

        $this->assertArrayHasKey('data', $body);
        // A simple paginator deliberately does not count the whole result set.
        $this->assertArrayNotHasKey('total', $body['meta'] ?? []);
    }

    #[Test]
    public function aCursorPaginatedCollectionIsRendered(): void
    {
        $this->assertArrayHasKey('data', $this->body('posts.cursor'));
    }

    #[Test]
    public function aResourceCollectionClassIsInstantiatedDirectly(): void
    {
        // PostCollection is a ResourceCollection, which cannot be built through
        // `::collection()` — and names no model, so the model comes from
        // PostResource's `@mixin` docblock.
        $body = $this->body('posts.collection');

        $this->assertNotEmpty($body['data']);
        $this->assertArrayHasKey('title', $body['data'][0]);
    }

    #[Test]
    public function anUnpaginatedCollectionIsAPlainList(): void
    {
        $body = $this->body('users.index');

        $this->assertArrayNotHasKey('links', $body);
        $this->assertCount(2, $body['data']);
    }

    #[Test]
    public function theModelIsInferredFromTheResourcesMixinDocblock(): void
    {
        // Returned as written in the docblock, leading backslash and all;
        // Utils::getModelFactory() strips it before resolving the factory.
        $this->assertSame('\\' . Post::class, ApiResourceResponseTools::tryToInferApiResourceModel(PostResource::class));
    }

    #[Test]
    public function aResourceWithoutAMixinDocblockInfersNothing(): void
    {
        $this->assertNull(ApiResourceResponseTools::tryToInferApiResourceModel(MixinlessResource::class));
    }

    #[Test]
    public function aMixinNamingAClassThatDoesNotExistInfersNothing(): void
    {
        $this->assertNull(ApiResourceResponseTools::tryToInferApiResourceModel(BadMixinResource::class));
    }

    #[Test]
    public function fluentCallsAreAppliedToTheBuiltResource(): void
    {
        $resource = ApiResourceResponseTools::getApiResourceOrCollectionInstance(
            CallableResource::class,
            isCollection: false,
            modelInstantiator: fn () => Post::factory()->make(),
            calls: ['withDetails'],
        );

        $this->assertTrue($resource->detailed);
    }

    #[Test]
    public function anUnknownFluentCallIsASilentNoOp(): void
    {
        // A typo in `call:` must not take the whole generation run down.
        $resource = ApiResourceResponseTools::getApiResourceOrCollectionInstance(
            CallableResource::class,
            isCollection: false,
            modelInstantiator: fn () => Post::factory()->make(),
            calls: ['noSuchMethod'],
        );

        $this->assertFalse($resource->detailed);
    }

    #[Test]
    public function aCollectionWithNoModelInstantiatorStillBuilds(): void
    {
        // No model named and none inferable. An Error here — which is not an
        // Exception — would escape the extractor's catch and take the whole
        // generation run down.
        $resource = ApiResourceResponseTools::getApiResourceOrCollectionInstance(
            MixinlessResource::class,
            isCollection: true,
            modelInstantiator: null,
        );

        $this->assertInstanceOf(\Hypervel\Http\Resources\Json\JsonResource::class, $resource);
    }

    #[Test]
    public function anEndpointNamingNoModelAtAllIsStillDocumented(): void
    {
        // The whole way through, rather than at the tools: the endpoint names
        // no `@apiResourceModel` and the resource names no `@mixin`, so nothing
        // is instantiated and the resource renders whatever it does without one.
        $this->assertSame(['status' => 'accepted'], $this->body('posts.emptyResource')['data']);
    }

    #[Test]
    public function aResourceWithNoModelInstantiatorStillBuilds(): void
    {
        // The documented empty-resource case: the annotation names no model and
        // none can be inferred, so the resource wraps an empty array.
        $resource = ApiResourceResponseTools::getApiResourceOrCollectionInstance(
            PostResource::class,
            isCollection: false,
            modelInstantiator: null,
        );

        $this->assertSame([], $resource->resource);
    }
}

/**
 * A resource with a parameterless fluent method, for the `call:` hook.
 */
class CallableResource extends \Hypervel\Http\Resources\Json\JsonResource
{
    public bool $detailed = false;

    public function withDetails(): static
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(\Hypervel\Http\Request $request): array
    {
        return ['detailed' => $this->detailed];
    }
}

/**
 * A resource whose docblock names no model.
 */
class MixinlessResource extends \Hypervel\Http\Resources\Json\JsonResource
{
}

/**
 * @mixin \Workbench\App\Models\NotARealModel
 */
class BadMixinResource extends \Hypervel\Http\Resources\Json\JsonResource
{
}
