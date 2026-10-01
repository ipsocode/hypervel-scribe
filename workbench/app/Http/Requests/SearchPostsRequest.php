<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * Query parameters for searching posts.
 *
 * One FormRequest can describe body parameters or query parameters, and the two
 * strategies decide between themselves which it is: the phrase "Query
 * parameters" in this docblock, or a `queryParameters()` method, claims it for
 * the query side and takes it away from the body side.
 */
class SearchPostsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => 'required|string',
            'per_page' => 'integer',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function queryParameters(): array
    {
        return [
            'q' => [
                'description' => 'What to search for.',
                'example' => 'hypervel',
            ],
            'per_page' => [
                'description' => 'Results per page.',
                'example' => 25,
            ],
        ];
    }
}
