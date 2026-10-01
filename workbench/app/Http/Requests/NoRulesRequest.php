<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * A FormRequest with neither `rules()` nor `validator()`.
 *
 * Nothing to read, and nothing to fall over on: the endpoint is documented with
 * no body parameters rather than failing extraction.
 */
class NoRulesRequest extends FormRequest
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [];
    }
}
