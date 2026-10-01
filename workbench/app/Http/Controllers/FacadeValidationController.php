<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Hypervel\Support\Facades\Request;

/**
 * Validation through the Request facade.
 *
 * In its own controller because the finder matches the literal class name in
 * the source (`Request::validate`), so the facade has to be imported under that
 * name — which would collide with the Request object the other controller
 * type-hints.
 *
 * @group Inline validation
 */
class FacadeValidationController extends Controller
{
    /**
     * Validate through the facade.
     */
    public function store(): array
    {
        $validated = Request::validate([
            'email' => 'required|email',
        ]);

        return ['data' => $validated];
    }

    /**
     * Validate through the facade, into a named error bag.
     *
     * The rules are the second argument rather than the first, so the finder
     * has to read a different position.
     */
    public function storeWithBag(): array
    {
        $validated = Request::validateWithBag('signup', [
            'nickname' => 'required|string',
        ]);

        return ['data' => $validated];
    }
}
