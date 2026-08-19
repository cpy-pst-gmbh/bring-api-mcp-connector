<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BackupDatabaseCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A backup nobody restores is a file, not a backup, so the tests read the copy
 * back rather than checking that something appeared.
 */
#[CoversClass(BackupDatabaseCommand::class)]
final class BackupDatabaseCommandTest extends TestCase
{
    private string $dir;
    private string $database;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/backup-command-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);

        $this->database = $this->dir . '/bring.db';
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->database]);
        $this->connection->executeStatement('CREATE TABLE app_user (email TEXT)');
        $this->connection->executeStatement("INSERT INTO app_user (email) VALUES ('someone@example.com')");
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/**/*') ?: []);
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir . '/backups');
        @rmdir($this->dir);
    }

    public function testTheCopyContainsTheData(): void
    {
        $this->backup();

        $copy = $this->copies()[0];
        $restored = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $copy]);

        self::assertSame('someone@example.com', $restored->fetchOne('SELECT email FROM app_user'));
    }

    /**
     * The copy holds the same encrypted passwords as the original, so it must
     * not be more readable than the original.
     */
    public function testTheCopyIsNotReadableByAnyoneElse(): void
    {
        $this->backup();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->copies()[0])), -4));
    }

    public function testTheTargetDirectoryIsCreated(): void
    {
        self::assertDirectoryDoesNotExist($this->dir . '/backups');

        $this->backup();

        self::assertDirectoryExists($this->dir . '/backups');
    }

    /**
     * VACUUM INTO refuses to overwrite, and two runs inside one second would
     * otherwise fail the whole maintenance run.
     */
    public function testASecondRunInTheSameSecondDoesNotCollide(): void
    {
        $this->backup();
        $this->backup();

        self::assertCount(2, $this->copies());
    }

    public function testOnlyTheNewestCopiesSurvive(): void
    {
        mkdir($this->dir . '/backups', 0o700, true);

        foreach (['20250101-000000', '20250102-000000', '20250103-000000'] as $stamp) {
            touch($this->dir . '/backups/bring-' . $stamp . '.db');
        }

        $this->backup(keep: 2);

        $names = array_map(basename(...), $this->copies());

        self::assertCount(2, $names);
        self::assertContains('bring-20250103-000000.db', $names);
        self::assertNotContains('bring-20250101-000000.db', $names);
    }

    /**
     * Files that are not ours share the directory when it is a bind mount the
     * operator also uses for something else.
     */
    public function testItLeavesUnrelatedFilesAlone(): void
    {
        mkdir($this->dir . '/backups', 0o700, true);
        touch($this->dir . '/backups/notes.txt');
        touch($this->dir . '/backups/bring-20250101-000000.db');

        $this->backup(keep: 1);

        self::assertFileExists($this->dir . '/backups/notes.txt');
        self::assertFileDoesNotExist($this->dir . '/backups/bring-20250101-000000.db');
    }

    /**
     * VACUUM INTO is SQLite's. Anything else has its own tooling, and a cron
     * that looks like it is backing something up would be worse than one that
     * says it is not.
     */
    public function testAnotherDatabaseIsRefusedRatherThanSilentlySkipped(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createStub(AbstractPlatform::class));

        $output = new BufferedOutput();
        $command = new BackupDatabaseCommand($connection, $this->dir . '/backups', 7);
        $status = $command(new SymfonyStyle(new ArrayInput([]), $output), null, null);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Only SQLite', $output->fetch());
    }

    private function backup(?int $keep = null): void
    {
        $command = new BackupDatabaseCommand($this->connection, $this->dir . '/backups', 7);
        $status = $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), null, $keep);

        self::assertSame(Command::SUCCESS, $status);
    }

    /**
     * @return list<string>
     */
    private function copies(): array
    {
        $copies = glob($this->dir . '/backups/bring-*.db') ?: [];
        sort($copies);

        return $copies;
    }
}
