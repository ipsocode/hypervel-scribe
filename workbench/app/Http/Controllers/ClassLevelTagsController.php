<?php

declare(strict_types=1);

namespace Workbench\App\Http\Controllers;

use Hypervel\Routing\Controller;

/**
 * Tags written on the controller rather than on a method.
 *
 * A parameter every endpoint in a controller takes — an API version, a tenant
 * header — is written once on the class, and every method inherits it. The
 * method's own tag of the same name wins.
 *
 * @group Class-level tags
 *
 * @queryParam tenant string The tenant to act as. Example: acme
 * @queryParam api_version string The API version. Example: v2
 *
 * @header X-Tenant
 */
class ClassLevelTagsController extends Controller
{
    /**
     * Inherits the controller's parameters.
     */
    public function inherits(): array
    {
        return [];
    }

    /**
     * Overrides one of them.
     *
     * @queryParam api_version string The API version to use. Example: v3
     * @queryParam nothing string A parameter with no example at all. Example: null
     */
    public function overrides(): array
    {
        return [];
    }
}
