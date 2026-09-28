<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Ipsocode\Scribe\Attributes\ResponseField;

/**
 * A resource that documents its own fields.
 *
 * The fields of a response belong with the resource that shapes it, not with
 * every controller method that returns one — so Scribe reads `@responseField`
 * tags and `#[ResponseField]` attributes off `toArray()` as well.
 *
 * @mixin \Workbench\App\Models\Post
 */
class DocumentedPostResource extends JsonResource
{
    /**
     * Resources wrap their payload in `data` by default; this one does not, so
     * that the wrapped and unwrapped shapes are both represented.
     */
    public static ?string $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @responseField id integer The post's id.
     * @responseField title string The post's title.
     *
     * @return array<string, mixed>
     */
    #[ResponseField('body', 'string', 'The post body.')]
    #[ResponseField('published', 'boolean', 'Whether it is public.')]
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
