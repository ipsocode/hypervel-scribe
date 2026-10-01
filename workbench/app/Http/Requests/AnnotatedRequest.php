<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Foundation\Http\FormRequest;

/**
 * A FormRequest that documents parameters in its own docblock.
 *
 * The request is where the parameters live, so its tags are read in preference
 * to the controller method's — a method that also carries them is describing
 * the same request twice, and the request wins.
 *
 * @queryParam page integer The page to fetch. Example: 2
 */
class AnnotatedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['title' => 'required|string'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return ['title' => ['description' => 'The title.', 'example' => 'A title']];
    }
}
