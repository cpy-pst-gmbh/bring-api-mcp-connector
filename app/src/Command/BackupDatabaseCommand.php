<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

use function count;
use function is_dir;
use function sprintf;
use function unlink;

/**
 * Writes a consistent copy of the SQLite database next to the live one.
 *
 * `VACUUM INTO` goes through SQLite itself, so it can run while the app is
 * serving requests. Copying the file instead would catch it mid-write: with
 * WAL enabled the data lives in two files, and a copy of one without the other
 * restores to whatever state the last checkpoint left behind.
 *
 * The database is the only part of an installation that cannot be recreated.
 * The OAuth keypair can be regenerated — every connector reconnects once — but
 * a lost account table is lost Bring! passwords, and with them every connector
 * on every account.
 */
#[AsCommand(
    name: 'app:database:backup',
    description: 'Write a timestamped copy of the database and drop the oldest ones',
)]
final class BackupDatabaseCommand
{
    private const string SUFFIX = '.db';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%app.backup_dir%')] private readonly string $defaultDirectory,
        #[Autowire('%app.backup_keep%')] private readonly int $defaultKeep,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Where to write, defaults to BACKUP_DIR')] ?string $dir = null,
        #[Option(description: 'How many copies to keep, defaults to BACKUP_KEEP')] ?int $keep = null,
    ): int {
        $platform = $this->connection->getDatabasePlatform();

        if (!$platform instanceof SQLitePlatform) {
            // Anything else has its own tooling and its own idea of a
            // consistent copy. Saying so daily beats a cron that looks like it
            // is backing something up.
            $io->error('Only SQLite is backed up here. Point pg_dump or mysqldump at the database instead.');

            return Command::INVALID;
        }

        $source = $this->connection->getParams()['path'] ?? null;

        if (false === is_string($source) || '' === $source) {
            $io->error('The connection has no database file to copy — an in-memory database cannot be backed up.');

            return Command::INVALID;
        }

        $directory = $dir ?? $this->defaultDirectory;
        $keep = $keep ?? $this->defaultKeep;

        if (false === is_dir($directory) && false === @mkdir($directory, 0o700, true) && false === is_dir($directory)) {
            $io->error(sprintf('%s does not exist and cannot be created.', $directory));

            return Command::FAILURE;
        }

        $prefix = basename($source, self::SUFFIX);
        $target = $this->target($directory, $prefix);

        try {
            // VACUUM takes no parameters, so the path goes in as a literal.
            $this->connection->executeStatement('VACUUM INTO ' . $this->connection->quote($target));
        } catch (Throwable $exception) {
            $io->error(sprintf('Backup failed: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        // The copy holds every stored credential, same as the original.
        @chmod($target, 0o600);

        $io->text(sprintf('Wrote %s (%s).', $target, $this->size($target)));

        $dropped = $this->prune($io, $directory, $prefix, $keep);

        $io->success(sprintf('Backup written, %d older copy/copies removed.', $dropped));

        return Command::SUCCESS;
    }

    /**
     * A name nothing occupies yet.
     *
     * The timestamp resolves to the second, which is plenty for a daily run
     * but not for two runs by hand. VACUUM INTO refuses an existing file, so a
     * counter goes on the end. It sorts next to its predecessor rather than
     * strictly after it, which changes nothing: both copies are the same
     * second, and retention has no reason to prefer one over the other.
     */
    private function target(string $directory, string $prefix): string
    {
        $stamp = new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Ymd-His');
        $base = sprintf('%s/%s-%s', rtrim($directory, '/'), $prefix, $stamp);
        $target = $base . self::SUFFIX;

        for ($n = 2; file_exists($target); ++$n) {
            $target = sprintf('%s-%d%s', $base, $n, self::SUFFIX);
        }

        return $target;
    }

    /**
     * Keeps the newest copies and removes the rest.
     *
     * The names carry a sortable timestamp, so lexical order is chronological
     * order — no mtimes involved, which a restore or an rsync could have
     * rewritten.
     */
    private function prune(SymfonyStyle $io, string $directory, string $prefix, int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }

        $existing = glob(sprintf('%s/%s-*%s', rtrim($directory, '/'), $prefix, self::SUFFIX));

        if (false === $existing || count($existing) <= $keep) {
            return 0;
        }

        sort($existing);
        $obsolete = array_slice($existing, 0, count($existing) - $keep);

        foreach ($obsolete as $file) {
            if (!@unlink($file)) {
                $io->warning(sprintf('%s could not be removed.', $file));
            }
        }

        return count($obsolete);
    }

    private function size(string $file): string
    {
        $bytes = filesize($file);

        if (false === $bytes) {
            throw new RuntimeException(sprintf('%s disappeared right after it was written.', $file));
        }

        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }
}
