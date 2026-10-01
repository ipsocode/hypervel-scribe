<?php

declare(strict_types=1);

namespace Workbench\App\Http\Requests;

use Hypervel\Contracts\Validation\Factory as ValidationFactory;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Validation\Validator;

/**
 * A FormRequest that builds its own validator instead of declaring `rules()`.
 *
 * Scribe has to run the method to see the rules, which means the request needs
 * a route to sit on — hence the resolver the strategy installs before calling
 * it. `$this->route()` below is what makes that resolver run.
 */
class ValidatorPostRequest extends FormRequest
{
    public function validator(ValidationFactory $factory): Validator
    {
        // An application asking which route it is on is the normal reason for
        // this to be here; the point is that it answers rather than throwing.
        $isWrite = in_array($this->route()?->methods()[0], ['POST', 'PUT'], true);

        return $factory->make([], [
            'code' => $isWrite ? 'required|string' : 'sometimes|string',
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bodyParameters(): array
    {
        return [
            'code' => [
                'description' => 'The redemption code.',
                'example' => 'ABC-123',
            ],
        ];
    }
}
