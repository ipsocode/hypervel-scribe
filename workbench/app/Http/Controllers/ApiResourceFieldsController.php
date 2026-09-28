<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Workbench\App\Http\Resources\DocumentedPostResource;
use Workbench\App\Http\Resources\EmptyResource;
use Workbench\App\Http\Resources\WrappedPostResource;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

/**
 * Endpoints whose response fields come from the API resource rather than from
 * the controller method.
 *
 * The same fields can be declared as `@responseField` tags or as
 * `#[ResponseField]` attributes, and a resource with a `$wrap` key nests them —
 * so each combination is a separate branch.
 *
 * @group API resource fields
 */
class ApiResourceFieldsController extends Controller
{
    /**
     * Fields read off the resource, unwrapped.
     *
     * @apiResource \Workbench\App\Http\Resources\DocumentedPostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function taggedFields(): array
    {
        return [];
    }

    /**
     * Fields read off a wrapped resource.
     *
     * @apiResource \Workbench\App\Http\Resources\WrappedPostResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function taggedWrappedFields(): array
    {
        return [];
    }

    /**
     * The attribute spelling of the same thing.
     */
    #[ResponseFromApiResource(DocumentedPostResource::class, Post::class)]
    public function attributedFields(): array
    {
        return [];
    }

    /**
     * And wrapped.
     */
    #[ResponseFromApiResource(WrappedPostResource::class, Post::class)]
    public function attributedWrappedFields(): array
    {
        return [];
    }

    /**
     * A resource attribute that names no model, and whose resource has no
     * `@mixin` either — so there is nothing to build an example from.
     */
    #[ResponseFromApiResource(EmptyResource::class)]
    public function noModelToTransform(): array
    {
        return [];
    }

    /**
     * A resource whose model is one of two bound to the URL.
     *
     * The example post's id has to land on the `{post}` parameter and not on
     * the `{user}` one — matching the response body to the path a reader would
     * actually call.
     */
    #[ResponseFromApiResource(DocumentedPostResource::class, Post::class)]
    public function boundToOneOfTwoModels(User $user, Post $post): array
    {
        return [];
    }
}
