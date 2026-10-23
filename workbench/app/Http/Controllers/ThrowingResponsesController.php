<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\ResponseFromApiResource;
use Ipsocode\Scribe\Attributes\ResponseFromTransformer;
use Workbench\App\Models\Post;

/**
 * Endpoints whose response cannot be built: each names a resource or
 * transformer class that does not exist, so building the response throws.
 *
 * Kept off `workbench/routes/api.php`, since a route that throws during
 * extraction would fail every generate-command test along with it.
 *
 * @group Throwing
 */
class ThrowingResponsesController extends Controller
{
    /**
     * @apiResource \Workbench\App\Http\Resources\NoSuchResource
     *
     * @apiResourceModel \Workbench\App\Models\Post
     */
    public function resourceTag(): array
    {
        return [];
    }

    #[ResponseFromApiResource('Workbench\App\Http\Resources\NoSuchResource', Post::class)]
    public function resourceAttribute(): array
    {
        return [];
    }

    /**
     * @transformer \Workbench\App\Transformers\NoSuchTransformer
     *
     * @transformerModel \Workbench\App\Models\Post
     */
    public function transformerTag(): array
    {
        return [];
    }

    #[ResponseFromTransformer('Workbench\App\Transformers\NoSuchTransformer', Post::class)]
    public function transformerAttribute(): array
    {
        return [];
    }
}
