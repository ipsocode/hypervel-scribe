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
    /**
     * Returns an instance of the documentation config.
     */
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

            // A driver that doesn't support transactions never had one to
            // begin, and calling rollback() on it would throw Error (undefined
            // method) rather than Exception — this is now reachable from
            // ResponseCalls's finally even when startDbTransaction() rejected
            // the connection before beginning anything.
            if (! self::driverSupportsTransactions($driver)) {
                continue;
            }

            try {
                $driver->rollback();
            } catch (Throwable $e) {
                // Any error handling should have been done on the startDbTransaction() side
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
