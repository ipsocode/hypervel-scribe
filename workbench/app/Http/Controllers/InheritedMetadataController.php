<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * The class half of the metadata fixture: a subgroup every method inherits.
 *
 * @group Metadata
 *
 * @subgroup Class subgroup
 *
 * @subgroupDescription Written on the controller.
 */
class InheritedMetadataController extends Controller
{
    /**
     * Says nothing about its subgroup, so it takes the controller's.
     */
    public function inheritsTheSubgroup(): array
    {
        return [];
    }
}
