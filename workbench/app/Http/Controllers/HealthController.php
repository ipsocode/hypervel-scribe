<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * A route that must NOT appear in the generated documentation.
 *
 * `scribe.routes.*.exclude` is only meaningfully under test if something in the
 * Workbench app would otherwise match the `api/*` prefix rule — so this
 * controller exists precisely to be left out.
 */
class HealthController extends Controller
{
    public function show(): array
    {
        return ['status' => 'ok'];
    }
}
