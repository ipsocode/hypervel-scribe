<?php

declare(strict_types=1);

namespace Workbench\App\Http\Resources;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;

/**
 * @mixin \Workbench\App\Models\User
 */
class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
