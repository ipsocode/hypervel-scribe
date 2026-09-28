<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

/**
 * @mixin \Workbench\App\Models\Post
 */
class PostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'published' => (bool) $this->published,
        ];
    }
}
