<?php

declare(strict_types=1);

namespace App\Update;

use App\Repository\DatabaseSchemaRepository;

/**
 * Puts the database back to the moment DatabaseBackup captured it — the
 * automatic answer to a migration that failed halfway (docs/updates/RECOVERY.md).
 *
 * MySQL runs no DDL inside a transaction, so a migration that fails after its
 * first ALTER leaves the schema half changed, and putting the old files back
 * on top of that would pair old code with a schema it has never seen. The
 * only consistent way back is the whole database as it was:
 *
 *   1. verify the dump file is the one the backup wrote (SHA-256 in its
 *      metadata) — a damaged dump is never replayed;
 *   2. drop every table and view the backup does not know — what the failed
 *      migration created;
 *   3. replay the dump statement by statement (each table is dropped and
 *      recreated by the dump itself), resumable across requests;
 *   4. verify: exactly the backed-up tables exist, each with exactly the
 *      backed-up number of rows. Only then is the restore reported done.
 *
 * If any of this fails, the update ends in recovery_required with the site
 * still in maintenance: this class never guesses its way to "probably fine".
 */
final class DatabaseRestore
{
    private DatabaseSchemaRepository $schema;

    /** @var array<string, mixed> */
    private array $metadata;

    public function __construct(
        private readonly string $directory,
        ?DatabaseSchemaRepository $schema = null
    ) {
        $metadataPath = $directory . '/' . DatabaseBackup::METADATA;
        $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;

        if (!is_array($metadata) || !is_array($metadata['tables'] ?? null)
            || !in_array($metadata['file'] ?? null, [DatabaseBackup::FILE, DatabaseBackup::PLAIN_FILE], true)
        ) {
            throw new UpdateException('update.error.backup_unreadable', [], 'No usable ' . DatabaseBackup::METADATA);
        }

        $this->metadata = $metadata;
        $this->schema = $schema ?? DatabaseBackup::connectDedicated();
    }

    /**
     * Checks the dump and clears what the failed update added.
     *
     * @return array<string, mixed> the cursor for step()
     */
    public function start(): array
    {
        $path = $this->dump();

        if (!is_file($path) || !hash_equals((string) ($this->metadata['sha256'] ?? ''), (string) hash_file('sha256', $path))) {
            throw new UpdateException('update.error.backup_damaged', [], basename($path) . ' does not match its recorded SHA-256');
        }

        $this->schema->execute('SET FOREIGN_KEY_CHECKS = 0');

        $known = array_keys((array) $this->metadata['tables']);
        foreach ($this->schema->views() as $view) {
            $this->schema->dropView($view);
        }
        foreach ($this->schema->tables() as $table) {
            if (!in_array($table, $known, true)) {
                $this->schema->dropTable($table);
            }
        }

        return ['offset' => 0, 'table_offset' => 0, 'statements' => 0, 'done' => false];
    }

    /**
     * The cursor to continue from when the previous step died halfway.
     *
     * The saved cursor points at the last statement boundary that was SAVED,
     * but the dead request may have executed a few INSERTs beyond it; running
     * those again would duplicate rows. Starting over at the current table's
     * DROP TABLE is always safe: the dump recreates the table from nothing.
     *
     * @param array<string, mixed> $cursor
     *
     * @return array<string, mixed>
     */
    public static function resumeAfterInterruption(array $cursor): array
    {
        $cursor['offset'] = (int) ($cursor['table_offset'] ?? 0);

        return $cursor;
    }

    /**
     * @param array<string, mixed> $cursor
     *
     * @return array<string, mixed>
     */
    public function step(array $cursor, float $budgetSeconds): array
    {
        $started = microtime(true);
        $reader = new SqlStatementReader($this->dump(), (int) $cursor['offset']);

        // Session settings are per connection, and every request has its own.
        $this->schema->execute('SET NAMES utf8mb4');
        $this->schema->execute("SET time_zone = '+00:00'");
        $this->schema->execute('SET FOREIGN_KEY_CHECKS = 0');
        $this->schema->execute("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        while (true) {
            $startsAt = $reader->offset();
            $statement = $reader->next();

            if ($statement === null) {
                break;
            }

            if (str_starts_with($statement, 'DROP TABLE IF EXISTS')) {
                $cursor['table_offset'] = $startsAt;
            }

            try {
                $this->schema->execute($statement);
            } catch (\PDOException $e) {
                throw new UpdateException(
                    'update.error.restore_failed',
                    [],
                    'Statement ' . ((int) $cursor['statements'] + 1) . ' failed: ' . $e->getMessage() . ' — ' . mb_substr($statement, 0, 300)
                );
            }

            $cursor['statements'] = (int) $cursor['statements'] + 1;
            $cursor['offset'] = $reader->offset();

            if ((microtime(true) - $started) >= $budgetSeconds) {
                return $cursor;
            }
        }

        $cursor['offset'] = $reader->offset();
        $cursor['done'] = true;

        return $cursor;
    }

    /** The dump file the metadata names: gzip, or plain SQL for a very large database. */
    private function dump(): string
    {
        return $this->directory . '/' . (string) $this->metadata['file'];
    }

    /**
     * @throws UpdateException when the database is not what was backed up
     */
    public function verify(): void
    {
        $expected = array_map('intval', (array) $this->metadata['tables']);
        ksort($expected);
        $actual = $this->schema->tables();
        sort($actual);

        if (array_keys($expected) !== $actual) {
            throw new UpdateException(
                'update.error.restore_mismatch',
                [],
                'Tables after restore differ: expected ' . implode(',', array_keys($expected)) . ' got ' . implode(',', $actual)
            );
        }

        foreach ($expected as $table => $rows) {
            $count = $this->schema->rowCount((string) $table);
            if ($count !== $rows) {
                throw new UpdateException('update.error.restore_mismatch', [], sprintf('%s has %d rows, the backup %d', $table, $count, $rows));
            }
        }
    }
}
