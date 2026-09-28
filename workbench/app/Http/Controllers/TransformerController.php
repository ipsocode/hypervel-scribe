<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\ResponseFromTransformer;
use Workbench\App\Models\Post;
use Workbench\App\Transformers\HypervelPaginatorAdapter;
use Workbench\App\Transformers\PostTransformer;

/**
 * Endpoints documented with Fractal transformers.
 *
 * @group Transformed
 */
class TransformerController extends Controller
{
    /**
     * Show a transformed post.
     *
     * @transformer \Workbench\App\Transformers\PostTransformer
     *
     * @transformerModel \Workbench\App\Models\Post
     */
    public function show(): array
    {
        return [];
    }

    /**
     * List transformed posts.
     *
     * @transformerCollection \Workbench\App\Transformers\PostTransformer
     *
     * @transformerModel \Workbench\App\Models\Post states=published resourceKey=posts
     */
    public function index(): array
    {
        return [];
    }

    /**
     * List transformed posts, paginated.
     *
     * @transformerCollection 201 \Workbench\App\Transformers\PostTransformer
     *
     * @transformerModel \Workbench\App\Models\Post
     *
     * @transformerPaginator \Workbench\App\Transformers\HypervelPaginatorAdapter 1
     */
    public function paginated(): array
    {
        return [];
    }

    /**
     * A transformer with no `@transformerModel`.
     *
     * The model is inferred from the transform method's own parameter type.
     *
     * @transformer \Workbench\App\Transformers\PostTransformer
     */
    public function inferredModel(): array
    {
        return [];
    }

    /**
     * A transformer with neither `@transformerModel` nor a typed parameter.
     *
     * Nothing left to infer from, so Scribe says so rather than documenting an
     * empty object.
     *
     * @transformer \Workbench\App\Transformers\UntypedTransformer
     */
    public function undetectableModel(): array
    {
        return [];
    }

    /**
     * List transformed posts, documented with an attribute.
     *
     * The attribute is the second spelling of the same strategy: everything the
     * `@transformer*` tags express as free text is a named argument here, and
     * both go through TransformerResponseTools.
     */
    #[ResponseFromTransformer(
        PostTransformer::class,
        Post::class,
        status: 201,
        description: 'The transformed posts',
        collection: true,
        factoryStates: ['published'],
        resourceKey: 'posts',
        paginate: [HypervelPaginatorAdapter::class, 1],
    )]
    public function attributed(): array
    {
        return [];
    }

    /**
     * A transformed post, documented with an attribute and no paginator.
     *
     * Pagination is a separate branch of the same call, so an attribute
     * without it is its own fixture.
     */
    #[ResponseFromTransformer(PostTransformer::class, Post::class)]
    public function attributedSingle(): array
    {
        return [];
    }
}
