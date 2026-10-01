<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Hypervel\Database\ConnectionResolverInterface;
use Ipsocode\Scribe\Exceptions\CouldntStartDatabaseTransaction;
use Ipsocode\Scribe\Exceptions\DatabaseTransactionsNotSupported;
use Ipsocode\Scribe\Tools\DocumentationConfig;
use Throwable;

trait DatabaseTransactionHelpers
{
    abstract public function getConfig(): DocumentationConfig;

    private function connectionsToTransact()
    {
        return $this->getConfig()->get('database_connections_to_transact', []);
    }

    private function startDbTransaction()
    {
        foreach ($this->connectionsToTransact() as $connection) {
            $database ??= app(ConnectionResolverInterface::class);

            $driver = $database->connection($connection);

            if (self::driverSupportsTransactions($driver)) {
                try {
                    $driver->beginTransaction();
                } catch (Throwable $e) {
                    throw CouldntStartDatabaseTransaction::forConnection($connection, $e);
                }
            } else {
                $driverClassName = get_class($driver);

                throw DatabaseTransactionsNotSupported::create($connection, $driverClassName);
            }
        }
    }

    private function endDbTransaction()
    {
        foreach ($this->connectionsToTransact() as $connection) {
            $database ??= app(ConnectionResolverInterface::class);

            $driver = $database->connection($connection);

            // ResponseCalls ends transactions from a finally block, so this also
            // runs after startDbTransaction() rejected a connection. A driver
            // without transaction support has nothing to roll back, and calling
            // a rollback() it lacks would throw an Error.
            if (! self::driverSupportsTransactions($driver)) {
                continue;
            }

            try {
                $driver->rollback();
            } catch (Throwable $e) {
                // startDbTransaction() already threw any start failure; a failed
                // rollback is ignored so every connection is still reached.
            }
        }
    }

    private static function driverSupportsTransactions($driver): bool
    {
        $methods = ['beginTransaction', 'rollback'];

        foreach ($methods as $method) {
            if (! method_exists($driver, $method)) {
                return false;
            }
        }

        return true;
    }
}
