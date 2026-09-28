<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Ipsocode\Scribe\Attributes\ResponseField;

/**
 * The same, wrapped in a key.
 *
 * A wrapped resource nests its payload under `$wrap`, so the fields documented
 * on `toArray()` have to be renamed to match what the response actually
 * contains.
 *
 * @mixin \Workbench\App\Models\Post
 */
class WrappedPostResource extends JsonResource
{
    public static ?string $wrap = 'data';

    /**
     * Transform the resource into an array.
     *
     * @responseField id integer The post's id.
     *
     * @return array<string, mixed>
     */
    #[ResponseField('title', 'string', 'The post title.')]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}
