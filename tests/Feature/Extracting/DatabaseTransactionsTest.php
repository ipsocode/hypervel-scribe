<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Tests\Feature\Extracting;

use Hypervel\Routing\Router;
use Ipsocode\Camel\Extraction\ExtractedEndpointData;
use Ipsocode\Scribe\Exceptions\CouldntStartDatabaseTransaction;
use Ipsocode\Scribe\Exceptions\DatabaseTransactionsNotSupported;
use Ipsocode\Scribe\Extracting\Strategies\Responses\ResponseCalls;
use Ipsocode\Scribe\Tests\TestCase;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Workbench\App\Http\Controllers\ResponseCallController;

/**
 * `database_connections_to_transact` wraps everything a strategy does in a
 * transaction and rolls it back, so documenting an API never leaves rows
 * behind. {@see ResponseCallsTest::writesMadeByTheCallAreRolledBack()} covers
 * the case where that works; this covers the three where it does not.
 *
 * The connections here are stand-ins rather than real ones: a driver that
 * cannot transact, and one that fails on the way in and on the way out. None of
 * those can be asked of a working SQLite connection, and each has its own
 * exception precisely because the alternative — carrying on and persisting the
 * changes — is the thing to avoid.
 */
class DatabaseTransactionsTest extends TestCase
{
    protected function defineRoutes(Router $router): void
    {
        $router->get('api/transacting/echo', [ResponseCallController::class, 'echo'])
            ->name('transacting.echo');
    }

    /**
     * Make a response call with `$connection` standing in for every connection.
     */
    private function callWith(object $connection): ?array
    {
        $this->app->instance('db', new class($connection) {
            public function __construct(private object $connection)
            {
            }

            public function connection(?string $name = null): object
            {
                return $this->connection;
            }
        });

        config(['scribe.database_connections_to_transact' => ['ledger']]);

        return (new ResponseCalls(new DocumentationConfig(config('scribe'))))
            ->makeResponseCall(
                ExtractedEndpointData::fromRoute($this->workbenchRoute('transacting.echo')),
                []
            );
    }

    #[Test]
    public function aDriverThatCannotTransactIsRefusedRatherThanWrittenTo(): void
    {
        $driver = new class {
            // No beginTransaction(), no rollback(): nothing here could be undone.
        };

        $this->expectException(DatabaseTransactionsNotSupported::class);
        $this->expectExceptionMessage('for connection [ledger] does not support transactions');

        $this->callWith($driver);
    }

    #[Test]
    public function aTransactionThatWillNotStartIsReportedAgainstItsConnection(): void
    {
        $driver = new class {
            public function beginTransaction(): void
            {
                throw new RuntimeException('Connection refused.');
            }

            public function rollback(): void
            {
            }
        };

        $this->expectException(CouldntStartDatabaseTransaction::class);
        $this->expectExceptionMessage("Couldn't start a database transaction for the connection ledger");

        $this->callWith($driver);
    }

    #[Test]
    public function aRollbackThatFailsDoesNotTakeTheResponseWithIt(): void
    {
        $driver = new class {
            public function beginTransaction(): void
            {
            }

            public function rollback(): void
            {
                // Whatever went wrong here, the transaction was started and the
                // response was captured; there is nothing left to report.
                throw new RuntimeException('Gone.');
            }
        };

        $response = $this->callWith($driver);

        $this->assertSame(200, $response[0]['status']);
    }

    #[Test]
    public function anEarlierConnectionsTransactionIsRolledBackWhenALaterOneFailsToStart(): void
    {
        $first = new class {
            public bool $rolledBack = false;

            public function beginTransaction(): void
            {
            }

            public function rollback(): void
            {
                $this->rolledBack = true;
            }
        };

        $second = new class {
            public function beginTransaction(): void
            {
                throw new RuntimeException('Connection refused.');
            }

            public function rollback(): void
            {
            }
        };

        $this->app->instance('db', new class($first, $second) {
            public function __construct(private object $first, private object $second)
            {
            }

            public function connection(?string $name = null): object
            {
                return $name === 'first' ? $this->first : $this->second;
            }
        });

        config(['scribe.database_connections_to_transact' => ['first', 'second']]);

        try {
            (new ResponseCalls(new DocumentationConfig(config('scribe'))))
                ->makeResponseCall(
                    ExtractedEndpointData::fromRoute($this->workbenchRoute('transacting.echo')),
                    []
                );

            $this->fail('Expected CouldntStartDatabaseTransaction to be thrown.');
        } catch (CouldntStartDatabaseTransaction $e) {
            $this->assertStringContainsString('for the connection second', $e->getMessage());
        }

        // The `try`/`finally` around configureEnvironment() in ResponseCalls
        // calls finish() — and so endDbTransaction() — even though
        // configureEnvironment() itself threw, so `first`'s already-open
        // transaction does not leak past this call.
        $this->assertTrue($first->rolledBack);
    }
}
