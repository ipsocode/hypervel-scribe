<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Extracting\Strategies\Responses\ResponseCalls;
use Ipsocode\Scribe\Scribe;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\ResponseCallController;
use Workbench\App\Models\Post;

use function Hypervel\Testbench\load_migration_paths;
use function Hypervel\Testbench\workbench_path;

/**
 * Whether `database_connections_to_transact` undoes what a response call
 * writes, with connections pooled the way an application's are.
 *
 * By default the testing resolver hands every coroutine one shared connection,
 * so a write from the call's coroutine lands in whichever transaction is open
 * and is rolled back however the strategy is built. `pool.testing_enabled`
 * gives each coroutine a connection of its own, as `scribe:generate` gets, and
 * the call's writes are rolled back only if the call itself is transacted.
 *
 * `RefreshDatabase` cannot wrap these tests: its transaction would hold the
 * test coroutine's connection while the call writes through another. Each test
 * migrates its own SQLite file instead, apart from the one DatabaseTestCase uses.
 * See docs/ai/response-calls.md.
 */
class ResponseCallsRollbackTest extends TestCase
{
    use DatabaseMigrations;

    protected function defineEnvironment(Application $app): void
    {
        $database = $app->databasePath('response-calls-rollback.sqlite');

        if (! is_file($database)) {
            touch($database);
        }

        $config = $app->get('config');
        $connection = "database.connections.{$config->get('database.default')}";
        $config->set("{$connection}.database", $database);
        $config->set("{$connection}.pool.testing_enabled", true);
        $config->set('scribe.database_connections_to_transact', [$config->get('database.default')]);
    }

    protected function defineDatabaseMigrations(): void
    {
        load_migration_paths($this->app, [workbench_path('database', 'migrations')]);
    }

    protected function defineRoutes(Router $router): void
    {
        $router->post('api/response-calls/persist', [ResponseCallController::class, 'persist'])
            ->name('response-calls.persist');
    }

    /**
     * Call the persisting endpoint, and return the row count it saw.
     */
    private function persist(): int
    {
        config(['scribe.strategies.responses' => [ResponseCalls::withSettings()]]);

        $responses = (new Extractor(new DocumentationConfig(config('scribe'))))
            ->processRoute($this->workbenchRoute('response-calls.persist'))
            ->responses
            ->toArray();

        $this->assertCount(1, $responses, 'No response was captured for the call.');

        return json_decode($responses[0]['content'], true)['count'];
    }

    #[Test]
    public function writesMadeByTheCallAreRolledBack(): void
    {
        $before = Post::query()->count();

        // The row is really there while the endpoint is running...
        $this->assertSame($before + 1, $this->persist());
        // ...and gone once the strategy has rolled its transaction back.
        $this->assertSame($before, Post::query()->count());
    }

    #[Test]
    public function writesMadeByTheBeforeHookAreSeenByTheCallAndRolledBackWithIt(): void
    {
        // The usual reason for the hook: a user or token for the call to use.
        Scribe::beforeResponseCall(function (Request $request, ExtractedEndpointData $endpointData) {
            Post::query()->create(['title' => 'Written by the hook', 'body' => 'Body.']);
        });

        $before = Post::query()->count();

        $this->assertSame($before + 2, $this->persist());
        $this->assertSame($before, Post::query()->count());
    }
}
