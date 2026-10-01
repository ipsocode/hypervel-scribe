<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

// Deliberately has no class docblock: the tag strategies read a FormRequest's
// docblock for @bodyParam and @queryParam tags, and for a class without one
// reflection returns `false`, which is a TypeError under strict types anywhere a
// string is expected.
class UndocumentedRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'title' => [
                'description' => 'The title.',
                'example' => 'Hello',
            ],
        ];
    }
}
