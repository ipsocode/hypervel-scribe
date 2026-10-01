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
 * Base for tests that need a running application: the service provider, the
 * route matcher, extraction from the Workbench controllers, the writers and the
 * console command.
 *
 * `WithWorkbench` loads `workbench/routes/api.php` and `web.php` into the
 * router; those routes are the fixture the suite documents.
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
     * Copy the Workbench storage fixtures into the skeleton's storage directory.
     *
     * `@responseFile` and `#[ResponseFromFile]` resolve relative paths through
     * `storage_path()`, which points at the skeleton. `package:sync-skeleton`
     * does this for the Testbench CLI, but PHPUnit never runs that command.
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
     * Extraction takes a `Route`, not a URI. Fails on an unknown name, rather
     * than letting a null reach the extractor and surface as an unrelated
     * TypeError.
     */
    protected function workbenchRoute(string $name): Route
    {
        $route = RouteFacade::getRoutes()->getByName($name);

        $this->assertNotNull($route, "The Workbench application has no route named [{$name}].");

        return $route;
    }
}
