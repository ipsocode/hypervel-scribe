<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * A FormRequest with rules and nothing else.
 *
 * `bodyParameters()` is Scribe's own hook, not Hypervel's, so plenty of real
 * applications do not have one. The rules still describe the parameters — just
 * without the descriptions and examples — and saying so is more useful than
 * documenting them silently.
 */
class RulesOnlyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => 'required|string'];
    }
}
