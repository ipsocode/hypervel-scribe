<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;

/**
 * A ResourceCollection, as opposed to `PostResource::collection()`.
 *
 * The two are instantiated down different branches — `new $class($list)` versus
 * `$class::collection($list)` — so documenting one does not exercise the other.
 * The `@mixin` is how a resource names its model when the annotation does not.
 *
 * @mixin \Workbench\App\Models\Post
 */
class PostCollection extends ResourceCollection
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }
}
