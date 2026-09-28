<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests;

use Hypervel\Routing\Route;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Route as RouteFacade;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase as BaseTestCase;

use function Hypervel\Testbench\workbench_path;

/**
 * Base for the tests that need a running application: the service provider, the
 * route matcher, extraction from the Workbench controllers, the writers, and the
 * console command.
 *
 * `WithWorkbench` is what brings `workbench/routes/api.php` and `web.php` into
 * the router. For a documentation generator that is the whole fixture — without
 * routes there is nothing to document, and a matcher test against an empty
 * router asserts nothing.
 */
abstract class TestCase extends BaseTestCase
{
    use WithWorkbench;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncWorkbenchStorage();
    }

    /**
     * Copy the Workbench application's storage fixtures into the runtime
     * skeleton's storage directory.
     *
     * `@responseFile` and `#[ResponseFromFile]` resolve relative paths through
     * `storage_path()`, which points at the skeleton rather than at
     * `workbench/`. `package:sync-skeleton` does this for the Testbench CLI;
     * PHPUnit never runs that command, so the suite does it for itself.
     */
    protected function syncWorkbenchStorage(): void
    {
        $source = workbench_path('storage', 'responses');

        if (File::isDirectory($source)) {
            File::copyDirectory($source, storage_path('responses'));
        }
    }

    /**
     * Resolve one of the Workbench application's routes by name.
     *
     * Scribe's extraction API takes a `Route`, not a URI, so nearly every
     * feature test needs to reach into the router the Workbench route files
     * populated. Failing loudly on a typo'd name beats a null reaching the
     * extractor and surfacing as an unrelated TypeError.
     */
    protected function workbenchRoute(string $name): Route
    {
        $route = RouteFacade::getRoutes()->getByName($name);

        $this->assertNotNull($route, "The Workbench application has no route named [{$name}].");

        return $route;
    }
}
