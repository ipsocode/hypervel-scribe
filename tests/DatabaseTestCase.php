<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;
use function Hypervel\Testbench\workbench_path;

/**
 * Base for the tests that need the Workbench application's tables.
 *
 * Scribe reaches a database in one place: instantiating the example models
 * behind API-resource and transformer responses. So extraction and the
 * generate command need one, and the route matcher, the writers and the
 * config/tooling tests do not, and keep the `:memory:` database phpunit.xml
 * and testbench.yaml configure.
 *
 * These tests run against a SQLite file instead. An in-memory database dies
 * with the connection that opened it, so Testbench resets `RefreshDatabase`'s
 * state after every test that uses one, and `migrate:fresh` ran again for
 * every test here. A file keeps the schema, so this process migrates it once
 * and each test after that only opens and rolls back a transaction.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Point the default connection at a SQLite file.
     *
     * The file lives in the Testbench skeleton, which is a copy of its own for
     * each process (each ParaTest worker included) that Testbench deletes when
     * the process exits. So no two processes share the file, and nothing is
     * left behind to clean up. The first test in a process creates it empty.
     */
    protected function defineEnvironment(Application $app): void
    {
        $database = $app->databasePath('testing.sqlite');

        if (! is_file($database)) {
            touch($database);
        }

        $config = $app->get('config');
        $config->set("database.connections.{$config->get('database.default')}.database", $database);
    }

    /**
     * Check the test starts with nothing but the schema.
     *
     * `RefreshDatabase` calls this inside the transaction it wraps the test in,
     * once the schema is in place. Rows that are already here were committed
     * by an earlier test in this process, outside its transaction (a seeder in
     * defineDatabaseSeeders(), which runs before the transaction opens, is the
     * usual way), and would otherwise leak into this test and every one after.
     * A subclass that seeds here calls this first.
     */
    protected function afterRefreshingDatabase(): void
    {
        $connection = $this->app->get('db')->connection();

        foreach ($connection->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            if ($table !== 'migrations' && ($rows = $connection->table($table)->count()) > 0) {
                $this->fail("The [{$table}] table has {$rows} row(s) an earlier test committed.");
            }
        }
    }

    /**
     * Register the migrations the Workbench application is built from.
     *
     * Testbench calls this while setting up the database requirements, which is
     * early enough for `RefreshDatabase` to see the paths — unlike
     * `WithWorkbench`'s own handling of `testbench.yaml`, which registers them
     * only after the one-shot `migrate:fresh` has already run.
     *
     * The paths are registered with the migrator directly rather than through
     * `loadMigrationsFrom()`. Once the schema is in place, that method stops
     * registering paths and runs them as a migration of the test's own
     * instead, which it rolls back afterwards and follows with a full
     * `migrate:fresh` for the next test: exactly the cost the file is here to
     * avoid.
     */
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            // Hypervel's default migrations supply the `users` table that
            // Workbench\App\Models\User sits on, with the same schema a
            // consuming application has.
            default_migration_path(),
            workbench_path('database', 'migrations'),
        ]);
    }
}
