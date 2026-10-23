<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;
use Ipsocode\Scribe\Attributes\Authenticated;
use Ipsocode\Scribe\Attributes\Group;
use Ipsocode\Scribe\Attributes\Subgroup;

/**
 * Metadata written as attributes on the class, which a tag on the method outranks.
 */
#[Authenticated]
#[Group('Class group', 'Written on the class.')]
#[Subgroup('Class subgroup', 'Class subgroup description.')]
class ClassAttributesController extends Controller
{
    /**
     * Left to the class attributes.
     */
    public function inherits(): array
    {
        return [];
    }

    /**
     * Tagged on the method.
     *
     * @unauthenticated
     *
     * @group Method group
     *
     * @subgroup Method subgroup
     *
     * @deprecated
     */
    public function tagged(): array
    {
        return [];
    }
}
