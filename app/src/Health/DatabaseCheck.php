<?php

declare(strict_types=1);

namespace App\Health;

use App\Domain\Interfaces\HealthCheckInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

/**
 * Proves the database answers queries, and on SQLite that it also accepts
 * writes.
 *
 * The distinction matters here: signing in writes, and a SQLite file owned by
 * the wrong user reads perfectly well right up to the moment someone tries.
 * That exact failure has already happened once in this project.
 */
final readonly class DatabaseCheck implements HealthCheckInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return 'health.check.database';
    }

    public function run(): HealthResult
    {
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (Throwable $exception) {
            // A connection error can carry the DSN, host and user, none of
            // which belong in a public response.
            $this->logger->error('Database health check failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return HealthResult::failed('health.detail.database_unreachable');
        }

        try {
            if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
                // Nothing equally cheap and side-effect free on other
                // platforms, so the read alone has to do.
                return HealthResult::ok('health.detail.database_read_only_platform');
            }

            // Writing user_version back to the value it already holds touches
            // the database header, so it needs a write lock without changing
            // anything. The value is cast, not bound: PRAGMA takes no parameters.
            $version = intval($this->connection->executeQuery('PRAGMA user_version')->fetchOne());
            $this->connection->executeStatement(sprintf('PRAGMA user_version = %d', $version));
        } catch (Throwable $exception) {
            $this->logger->error('Database is not writable: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return HealthResult::failed('health.detail.database_readonly');
        }

        return HealthResult::ok();
    }
}
