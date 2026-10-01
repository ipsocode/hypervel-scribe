<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * Endpoint metadata written on the method rather than inherited from the class.
 *
 * Group, subgroup, title, description, deprecation and authentication can each
 * be declared in either place, and the method's own docblock wins — so the two
 * halves need controllers of their own to be told apart. This is the method
 * half; {@see InheritedMetadataController} is the class half.
 *
 * @group Metadata
 */
class MetadataController extends Controller
{
    /**
     * @group Method group
     *
     * The group's description, on the lines after its name.
     */
    public function groupWithNoTitle(): array
    {
        return [];
    }

    /**
     * Fetch the things.
     *
     * @group Method group
     *
     * The group's description, on the lines after its name.
     */
    public function groupWithTitle(): array
    {
        return [];
    }

    /**
     * Explicitly public.
     *
     * @unauthenticated
     */
    public function unauthenticated(): array
    {
        return [];
    }

    /**
     * Deprecated with nothing more to say.
     *
     * @deprecated
     */
    public function deprecatedBare(): array
    {
        return [];
    }

    /**
     * Deprecated, with a replacement.
     *
     * @deprecated use `groupWithTitle` instead
     */
    public function deprecatedWithReason(): array
    {
        return [];
    }

    /**
     * Sorted into a subgroup by the method.
     *
     * @subgroup Method subgroup
     *
     * @subgroupDescription Written on the method.
     */
    public function subgrouped(): array
    {
        return [];
    }
}
