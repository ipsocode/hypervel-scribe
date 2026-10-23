<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Workbench\App\Support\PasswordValidator;

/**
 * Helpers that share a name with a validation call but take a single argument.
 *
 * The finders match a call by its name, so each of these has to be passed over
 * rather than read as an inline validator.
 *
 * @group One-argument calls
 */
class OneArgumentCallsController extends Controller
{
    /**
     * Its own one-argument `validate()` helper, ahead of the real rules.
     */
    public function ownValidateHelper(Request $request): array
    {
        $this->validate($request->all());

        $validated = $request->validate([
            'title' => 'required|string',
        ]);

        return $validated;
    }

    /**
     * A one-argument `make()` on a class whose name ends in `Validator`.
     */
    public function ownValidatorMake(Request $request): array
    {
        $checked = PasswordValidator::make($request->input('password'));

        return [$checked];
    }

    /**
     * A first-class callable has no argument to read.
     */
    public function firstClassCallable(Request $request): array
    {
        $check = $this->validate(...);

        return [$check, $request->query()];
    }

    /**
     * Their one-argument spellings on the request.
     */
    public function requestWithoutRules(Request $request): array
    {
        $request->validate();
        $request->validateWithBag('post');

        return [];
    }

    private function validate(array $data): void
    {
    }
}
