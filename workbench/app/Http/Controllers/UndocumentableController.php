<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * Routes a generation run has to survive rather than document.
 *
 * Scribe walks every matched route, and one bad one must not take the rest of
 * the documentation down with it — so each way a route can be undocumentable
 * gets a method here.
 *
 * @group Undocumentable
 */
class UndocumentableController extends Controller
{
    /**
     * Hidden by an annotation on the method.
     *
     * @hideFromAPIDocumentation
     */
    public function hiddenMethod(): array
    {
        return [];
    }

    /**
     * An endpoint whose annotation names a file that is not there.
     *
     * @responseFile responses/does-not-exist.json
     */
    public function missingResponseFile(): array
    {
        return [];
    }

    /**
     * A perfectly ordinary endpoint, for the routes whose problem is in the
     * route rather than in the method.
     */
    public function fine(): array
    {
        return [];
    }
}
