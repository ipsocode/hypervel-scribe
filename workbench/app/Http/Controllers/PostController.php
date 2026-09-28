<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\Endpoint;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Workbench\App\Http\Requests\StorePostRequest;
use Workbench\App\Http\Resources\EmptyResource;
use Workbench\App\Http\Resources\PostCollection;
use Workbench\App\Http\Resources\PostResource;
use Workbench\App\Models\Post;

/**
 * The docblock-annotated half of the Workbench API.
 *
 * Scribe supports two annotation styles and they run through entirely separate
 * strategies, so the Workbench app documents one controller each way rather
 * than mixing them — see UserController for the PHP-attribute half.
 *
 * @group Posts
 *
 * Endpoints for reading and writing blog posts.
 */
class PostController extends Controller
{
    /**
     * List posts.
     *
     * Returns a page of posts, newest first.
     *
     * @queryParam page integer The page to fetch. Example: 2
     * @queryParam status string Filter by status. Enum: draft, published Example: published
     *
     * @apiResourceCollection \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function index(Request $request): mixed
    {
        return PostResource::collection(Post::query()->latest()->get());
    }

    /**
     * An API resource that stands for no model.
     *
     * No `@apiResourceModel`, and the resource names no `@mixin` either, so
     * there is nothing to instantiate — the resource renders from nothing.
     *
     * Registered by ApiResourceResponsesTest rather than in the Workbench route
     * file, so the documented API the writer tests assert against is unchanged.
     *
     * @apiResource \Workbench\App\Http\Resources\EmptyResource
     */
    public function emptyResource(): mixed
    {
        return new EmptyResource(null);
    }

    /**
     * Show a post.
     *
     * @urlParam id integer required The post id. Example: 3
     *
     * @apiResource \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function show(int $id): mixed
    {
        return new PostResource(Post::query()->findOrFail($id));
    }

    /**
     * Create a post.
     *
     * The body parameters below are read off StorePostRequest's validation
     * rules, not from tags — which is the whole point of routing this endpoint
     * through a form request.
     *
     * @response status=201 {"id": 1, "title": "My first post", "published": false}
     */
    public function store(StorePostRequest $request): mixed
    {
        return new PostResource(Post::query()->create($request->validated()));
    }

    #[Endpoint('List posts, paginated')]
    #[ResponseFromApiResource(PostResource::class, Post::class, collection: true, paginate: 2, with: ['author'], withCount: ['author'], additional: ['meta' => ['generated' => true]])]
    public function paginated(): mixed
    {
        return PostResource::collection(Post::query()->paginate(2));
    }

    #[Endpoint('List posts, simply paginated')]
    #[ResponseFromApiResource(PostResource::class, Post::class, collection: true, simplePaginate: 2)]
    public function simplePaginated(): mixed
    {
        return PostResource::collection(Post::query()->simplePaginate(2));
    }

    #[Endpoint('List posts, cursor paginated')]
    #[ResponseFromApiResource(PostResource::class, Post::class, collection: true, cursorPaginate: 2)]
    public function cursorPaginated(): mixed
    {
        return PostResource::collection(Post::query()->cursorPaginate(2));
    }

    #[Endpoint('List posts through a resource collection')]
    // No `model:` — the model is inferred from PostResource's `@mixin` docblock,
    // which is the documented fallback when the annotation does not name one.
    #[ResponseFromApiResource(PostCollection::class, collection: true)]
    public function collectionResource(): mixed
    {
        return new PostCollection(Post::query()->get());
    }

    /**
     * Show a post by route model binding.
     *
     * Type-hinting the model is what lets Scribe learn the parameter's route
     * key and rewrite `{blog}` into `{id}` — a plain `int $id` teaches it
     * nothing.
     *
     * @apiResource \Workbench\App\Http\Resources\PostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function showBound(Post $blog): mixed
    {
        return new PostResource($blog);
    }

    /**
     * Delete a post.
     *
     * @urlParam id integer required The post id. Example: 3
     *
     * @response status=204 {}
     *
     * @authenticated
     */
    public function destroy(int $id): mixed
    {
        Post::query()->where('id', $id)->delete();

        return response()->json([], 204);
    }
}
