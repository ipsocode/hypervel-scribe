<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * A controller hidden in its entirety.
 *
 * @hideFromAPIDocumentation
 *
 * @group Undocumentable
 */
class HiddenController extends Controller
{
    public function show(): array
    {
        return [];
    }
}
