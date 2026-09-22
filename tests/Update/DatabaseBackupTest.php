<?php

declare(strict_types=1);

namespace Tests\Update;

use App\Repository\DatabaseSchemaRepository;
use App\Update\DatabaseBackup;
use App\Update\DatabaseRestore;
use App\Update\UpdateException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeedFixture;

/**
 * The PHP-only database backup and the restore that rolls a failed update
 * back (DatabaseBackup, DatabaseRestore, docs/updates/RECOVERY.md).
 *
 * Proven on a throwaway database holding the awkward cases on purpose:
 * binary data, NULLs, quotes, backslashes, newlines and emoji in text, a
 * generated column, a composite key, a table without any key, a foreign
 * key, a view, an id of zero, more rows than one page, and timestamps. The
 * round trip must give back EXACTLY what was there, and the restore must
 * also clear what a failed migration left behind.
 *
 * Needs the MySQL root account to create the database (like
 * Tests\Support\ScratchInstall); skips where there is none.
 */
final class DatabaseBackupTest extends TestCase
{
    private ?PDO $root = null;
    private string $database = '';
    private string $directory = '';

    protected function setUp(): void
    {
        $password = (string) ($_ENV['DB_ROOT_PASSWORD'] ?? '');
        if ($password === '') {
            $this->markTestSkipped('needs DB_ROOT_PASSWORD to create a throwaway database');
        }

        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $this->root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", 'root', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database = 'mygdala_upd_backup_' . bin2hex(random_bytes(3));
        $this->root->exec("CREATE DATABASE `{$this->database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->directory = sys_get_temp_dir() . '/mygdala-dbbackup-' . bin2hex(random_bytes(4));
        mkdir($this->directory, 0777, true);

        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->root?->exec("DROP DATABASE IF EXISTS `{$this->database}`");
        FeedFixture::removeDirectory($this->directory);
    }

    private function connection(): PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$this->database};charset=utf8mb4", 'root', (string) $_ENV['DB_ROOT_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }

    private function schema(): DatabaseSchemaRepository
    {
        return new DatabaseSchemaRepository($this->connection());
    }

    private function seed(): void
    {
        $db = $this->connection();
        $db->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
        $db->exec('CREATE TABLE parents (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $db->exec('CREATE TABLE children (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NOT NULL,
            body TEXT NULL,
            payload BLOB NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            ratio DOUBLE NULL,
            flags BIT(3) NULL,
            created_at TIMESTAMP NULL,
            stamped TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            happened DATETIME NULL,
            name_upper VARCHAR(255) GENERATED ALWAYS AS (UPPER(body)) VIRTUAL,
            CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES parents (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB');
        $db->exec('CREATE TABLE pairs (a VARCHAR(10) NOT NULL, b INT NOT NULL, note VARCHAR(50) NULL, PRIMARY KEY (a, b)) ENGINE=InnoDB');
        $db->exec('CREATE TABLE loose (note VARCHAR(50) NULL) ENGINE=InnoDB');
        $db->exec('CREATE VIEW parent_names AS SELECT name FROM parents');

        $db->exec("INSERT INTO parents (id, name) VALUES (0, 'zero'), (1, 'one'), (2, 'O''Brien \\\\ backslash')");

        $insert = $db->prepare('INSERT INTO children (parent_id, body, payload, price, ratio, flags, created_at, stamped, happened) VALUES (?, ?, ?, ?, ?, b\'101\', ?, ?, ?)');
        for ($i = 0; $i < 1234; $i++) {
            $insert->execute([
                $i % 3,
                $i === 0 ? "line one\nline two; with a semicolon -- and /* not a comment */ 'quotes' \"double\" `tick` 🙂" : 'row ' . $i,
                $i % 7 === 0 ? null : random_bytes(16) . "\0;\n'",
                '12.34',
                $i === 1 ? '0.1000000000000000055511151231257827' : (string) ($i / 3),
                '2026-03-29 01:30:00',
                // A column with an expression default (MySQL 8 reports it as
                // DEFAULT_GENERATED): its own value must survive the round trip.
                sprintf('2020-01-%02d 10:00:00', 1 + $i % 28),
                '2026-10-27 02:15:00',
            ]);
        }

        $db->exec("INSERT INTO pairs VALUES ('x', 1, 'first'), ('x', 2, NULL), ('y', 1, 'third')");
        $db->exec("INSERT INTO loose VALUES ('same'), ('same'), (NULL)");
    }

    /**
     * Table => checksum of its definition and all its rows. The definition
     * is read from information_schema, not from SHOW CREATE TABLE: MySQL 8
     * prints a replayed column as "CHARACTER SET x COLLATE y" where the
     * original said "COLLATE y", which is the same column in other words.
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $db = $this->connection();
        $snapshot = [];
        $describe = function (string $sql, string $table) use ($db): string {
            $statement = $db->prepare($sql);
            $statement->execute([$this->database, $table]);

            return serialize($statement->fetchAll(PDO::FETCH_ASSOC));
        };

        foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
            $create = $describe('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, COLLATION_NAME, EXTRA, GENERATION_EXPRESSION
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', $name)
                . $describe('SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', $name)
                . $describe('SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', $name)
                . $describe('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', $name)
                . $type;
            $rows = $db->query("SELECT * FROM `{$name}`")->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(static fn (array $row): string => bin2hex(serialize($row)), $rows);
            sort($encoded);
            $snapshot[$name] = hash('sha256', $create . implode('|', $encoded));
        }

        ksort($snapshot);

        return $snapshot;
    }

    /** Backs up with a zero budget: every call does one page, so the cursor is really exercised. */
    private function backUp(): array
    {
        $backup = new DatabaseBackup($this->directory, $this->schema());
        $cursor = $backup->start();
        $calls = 0;

        while (!$cursor['done']) {
            $cursor = $backup->step($cursor, 0.0);
            $calls++;
        }

        $this->assertGreaterThan(3, $calls, 'the dump is spread over several steps');

        return $backup->finish($cursor);
    }

    private function restore(): void
    {
        $restore = new DatabaseRestore($this->directory, $this->schema());
        $cursor = $restore->start();

        while (!$cursor['done']) {
            $cursor = $restore->step($cursor, 0.0);
        }

        $restore->verify();
    }

    public function testTheBackupRecordsEveryTableWithItsRowCount(): void
    {
        $metadata = $this->backUp();

        $this->assertSame(['children' => 1234, 'loose' => 3, 'pairs' => 3, 'parents' => 3], array_map('intval', $metadata['tables']));
        $this->assertSame(['parent_names'], $metadata['views']);
        $this->assertSame(hash_file('sha256', $this->directory . '/database.sql.gz'), $metadata['sha256']);
    }

    public function testARestoreGivesBackExactlyWhatWasThereAndClearsWhatAFailedMigrationAdded(): void
    {
        $before = $this->snapshot();
        $this->backUp();

        // What a migration that failed halfway might leave behind.
        $db = $this->connection();
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->exec('CREATE TABLE half_created (id INT PRIMARY KEY)');
        $db->exec('ALTER TABLE children ADD COLUMN extra INT NULL');
        $db->exec('DELETE FROM children WHERE id > 1000');
        $db->exec("UPDATE parents SET name = 'changed'");
        $db->exec('DROP TABLE pairs');
        $db->exec('DROP VIEW parent_names');

        $this->restore();

        $this->assertSame($before, $this->snapshot());
    }

    public function testAColumnWithAnExpressionDefaultKeepsItsOwnValues(): void
    {
        $this->backUp();
        $this->connection()->exec("UPDATE children SET stamped = '2031-01-01 00:00:00'");

        $this->restore();

        $this->assertSame('2020-01-02 10:00:00', $this->connection()->query('SELECT stamped FROM children WHERE id = 2')->fetchColumn());
    }

    public function testADumpInterruptedHalfwayContinuesWhereItsCursorSaysOrStartsOver(): void
    {
        $backup = new DatabaseBackup($this->directory, $this->schema());
        $cursor = $backup->step($backup->start(), 0.0);
        $part = $this->directory . '/database.sql.part';

        // The dead attempt wrote more than the saved cursor knows about: cut back.
        file_put_contents($part, "INSERT INTO `parents` VALUES ('9','half written", FILE_APPEND);
        $resumed = $backup->resume($cursor);
        $this->assertSame($cursor, $resumed);
        clearstatcache();
        $this->assertSame($cursor['bytes'], filesize($part));

        // A part that is shorter than the cursor, or gone, cannot be continued.
        file_put_contents($part, 'short');
        $this->assertNull($backup->resume($cursor));
        unlink($part);
        $this->assertNull($backup->resume($cursor));
    }

    public function testFinishingRefusesADumpThatIsNotTheLengthItsCursorRecorded(): void
    {
        $backup = new DatabaseBackup($this->directory, $this->schema());
        $cursor = $backup->start();
        while (!$cursor['done']) {
            $cursor = $backup->step($cursor, 60);
        }
        file_put_contents($this->directory . '/database.sql.part', 'stray', FILE_APPEND);

        $this->assertRefused('update.error.backup_inconsistent', fn () => $backup->finish($cursor));
    }

    public function testADamagedDumpIsNeverReplayed(): void
    {
        $this->backUp();
        file_put_contents($this->directory . '/database.sql.gz', 'x', FILE_APPEND);

        $this->assertRefused('update.error.backup_damaged', fn () => (new DatabaseRestore($this->directory, $this->schema()))->start());
    }

    public function testADatabaseWithTriggersIsRefusedRatherThanHalfBackedUp(): void
    {
        $this->connection()->exec('CREATE TRIGGER parents_touch BEFORE UPDATE ON parents FOR EACH ROW SET NEW.name = NEW.name');

        $this->assertRefused('update.error.backup_unsupported', fn () => (new DatabaseBackup($this->directory, $this->schema()))->start());
    }

    public function testTheDumpIsPlainSqlOneInsertPerLine(): void
    {
        $this->backUp();
        $sql = (string) gzdecode((string) file_get_contents($this->directory . '/database.sql.gz'));

        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        $this->assertStringContainsString('CREATE TABLE `children`', $sql);
        foreach (explode("\n", $sql) as $line) {
            if (str_starts_with($line, 'INSERT INTO')) {
                $this->assertStringEndsWith(');', $line);
            }
        }
    }

    private function assertRefused(string $messageKey, callable $action): void
    {
        try {
            $action();
            $this->fail('expected a refusal with ' . $messageKey);
        } catch (UpdateException $e) {
            $this->assertSame($messageKey, $e->messageKey, $e->getMessage());
        }
    }
}
