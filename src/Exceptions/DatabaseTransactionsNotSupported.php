<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Exceptions;

use RuntimeException;

class DatabaseTransactionsNotSupported extends RuntimeException implements ScribeException
{
    public static function create(string $connectionName, string $driverName)
    {
        return new self(
            "Database driver [{$driverName}] for connection [{$connectionName}] does not support transactions."
            . " To allow Scribe to proceed, remove \"{$connectionName}\" from the \"database_connections_to_transact\" config array."
            . ' Note that any changes to your database will be persisted.'
        );
    }
}
