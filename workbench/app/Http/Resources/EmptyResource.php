<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

/**
 * A resource that stands for no model at all.
 *
 * No `@mixin`, and the endpoint documenting it names no `@apiResourceModel`, so
 * there is nothing for Scribe to instantiate — the resource renders from
 * whatever it is given, which is nothing.
 */
class EmptyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['status' => 'accepted'];
    }
}
