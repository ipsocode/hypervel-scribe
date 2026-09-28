<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Hypervel\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->loadMigrationsFrom(realpath(__DIR__ . '/../../database/migrations'));
    }
}
