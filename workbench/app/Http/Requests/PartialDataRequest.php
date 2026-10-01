<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * A FormRequest whose `bodyParameters()` covers only some of its rules.
 *
 * The half that is described is easy to miss the other half of, so the
 * parameter with no entry gets called out by name.
 */
class PartialDataRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'described' => 'required|string',
            'undescribed' => 'required|string',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'described' => [
                'description' => 'The one with an entry.',
                'example' => 'here',
            ],
        ];
    }
}
