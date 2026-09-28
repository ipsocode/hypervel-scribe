<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * The form request Scribe reads body parameters out of.
 *
 * `bodyParameters()` is Scribe's own hook for the descriptions and examples a
 * validation rule cannot express; keeping both here is what makes the
 * GetFromFormRequest strategy testable end to end rather than only its
 * rule-parsing half.
 */
class StorePostRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:120',
            'body' => 'required|string',
            'published' => 'boolean',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'title' => [
                'description' => 'The title of the post.',
                'example' => 'My first post',
            ],
            'body' => [
                'description' => 'The body of the post.',
                'example' => 'Hello, world.',
            ],
            'published' => [
                'description' => 'Whether the post is publicly visible.',
                'example' => false,
            ],
        ];
    }
}
