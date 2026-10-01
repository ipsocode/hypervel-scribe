<?php

declare(strict_types=1);

namespace Workbench\App\Transformers;

use League\Fractal\TransformerAbstract;
use Workbench\App\Models\Post;

/**
 * A Fractal transformer, the other way an application can describe its
 * responses. Scribe reads it through the `@transformer` family of tags.
 */
class PostTransformer extends TransformerAbstract
{
    /**
     * @return array<string, mixed>
     */
    public function transform(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'published' => (bool) $post->published,
        ];
    }
}
