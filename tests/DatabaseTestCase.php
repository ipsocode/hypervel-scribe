<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;

use function Hypervel\Testbench\default_migration_path;
use function Hypervel\Testbench\load_migration_paths;
use function Hypervel\Testbench\workbench_path;

/**
 * Base for tests that need the Workbench application's tables.
 *
 * Scribe touches a database only to instantiate the example models behind
 * API-resource and transformer responses, so extraction and generate-command
 * tests extend this; the rest keep the `:memory:` database that phpunit.xml and
 * testbench.yaml configure.
 *
 * This uses a SQLite file instead. An in-memory database dies with its
 * connection, so Testbench resets `RefreshDatabase` after every test that uses
 * one and each test would run `migrate:fresh`. A file keeps the schema: each
 * process migrates once, and each test only opens and rolls back a transaction.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Point the default connection at a SQLite file.
     *
     * The file lives in the Testbench skeleton, which each process (ParaTest
     * workers included) gets its own copy of and deletes on exit, so no two
     * processes share the file and nothing is left to clean up. The first test
     * in a process creates it empty.
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
     * `RefreshDatabase` calls this inside the test's transaction. Rows already
     * present were committed outside a transaction by an earlier test (usually
     * a seeder in defineDatabaseSeeders(), which runs before the transaction
     * opens) and would leak into every later test. A subclass that seeds here
     * calls this first.
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
     * Testbench calls this early enough for `RefreshDatabase` to see the paths,
     * whereas `WithWorkbench` registers the ones in `testbench.yaml` only after
     * the one-shot `migrate:fresh`. The paths go to the migrator directly: once
     * the schema exists, `loadMigrationsFrom()` runs them as a migration of the
     * test's own, rolled back and followed by a full `migrate:fresh` for the next
     * test, which is the cost the file avoids.
     */
    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [
            // Hypervel's default migrations supply the `users` table behind
            // Workbench\App\Models\User, with the schema an application has.
            default_migration_path(),
            workbench_path('database', 'migrations'),
        ]);
    }
}
