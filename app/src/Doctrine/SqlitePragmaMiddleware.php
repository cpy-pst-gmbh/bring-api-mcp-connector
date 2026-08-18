<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies the SQLite pragmas this project relies on to every new connection.
 *
 * WAL keeps readers from blocking the writer, busy_timeout makes concurrent
 * writes wait instead of failing with SQLITE_BUSY, and foreign_keys enforces
 * the constraints Doctrine emits (SQLite ignores them by default).
 *
 * journal_mode is persisted in the database file, the other two are per
 * connection, so all three are set on connect.
 */
final class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);

                if ('pdo_sqlite' === ($params['driver'] ?? null)) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 5000');
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
