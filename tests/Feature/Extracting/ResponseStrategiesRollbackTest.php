<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\DB;
use Ipsocode\Scribe\Extracting\Extractor;
use Ipsocode\Scribe\Tests\DatabaseTestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use Workbench\App\Http\Controllers\ThrowingResponsesController;

/**
 * The response strategies that build their response inside a transaction
 * (API resource and transformer, as tags and as attributes) must roll it back
 * when building throws, or the connection is left a level deeper and the
 * caller's later writes are never committed.
 */
class ResponseStrategiesRollbackTest extends DatabaseTestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->get('config');
        $config->set('scribe.database_connections_to_transact', [$config->get('database.default')]);
    }

    protected function defineRoutes(Router $router): void
    {
        foreach (['resourceTag', 'resourceAttribute', 'transformerTag', 'transformerAttribute'] as $method) {
            $router->get("api/throwing/{$method}", [ThrowingResponsesController::class, $method])
                ->name("throwing.{$method}");
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function throwingEndpoints(): array
    {
        return [
            'API resource tag' => ['throwing.resourceTag'],
            'API resource attribute' => ['throwing.resourceAttribute'],
            'transformer tag' => ['throwing.transformerTag'],
            'transformer attribute' => ['throwing.transformerAttribute'],
        ];
    }

    #[Test]
    #[DataProvider('throwingEndpoints')]
    public function aResponseThatCannotBeBuiltLeavesNoTransactionOpen(string $routeName): void
    {
        $level = DB::transactionLevel();
        $threw = false;

        try {
            (new Extractor(new DocumentationConfig(config('scribe'))))
                ->processRoute($this->workbenchRoute($routeName));
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Building the response was expected to throw.');
        $this->assertSame($level, DB::transactionLevel());
    }
}
