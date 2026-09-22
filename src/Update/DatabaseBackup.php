<?php

declare(strict_types=1);

namespace App\Update;

use App\Repository\DatabaseSchemaRepository;

/**
 * A complete SQL dump of the site's database, written by PHP itself.
 *
 * WHY NOT mysqldump. A shared host rarely allows exec(), and a backup that
 * only works where a shell does is no backup for the hosts this project runs
 * on. So this is a plain SQL file, gzip-compressed, that:
 *
 *   - DatabaseRestore replays when a migration fails (automatic rollback);
 *   - a support engineer can import by hand through phpMyAdmin — standard
 *     statements, one INSERT per line, utf8mb4, UTC timestamps.
 *
 * WHAT IT HOLDS. Every base table (DROP + CREATE from SHOW CREATE TABLE, so
 * keys, foreign keys and AUTO_INCREMENT come back exactly), every row, and
 * every view. Triggers, routines and events are NOT dumped; a database that
 * has any is refused by Preflight, instead of producing a restore that would
 * quietly lose them. This project creates none.
 *
 * CONSISTENCY. It runs after maintenance mode is on (Updater), so no visitor
 * writes between the first table and the last; the row count of every table
 * is recorded as it is written and checked against COUNT(*) at the end, so a
 * dump that is not the database is a failed backup, never a trusted one.
 *
 * RESUMABLE. step() writes until its time budget is spent and returns a
 * cursor, appending plain SQL to database.sql.part; finish() compresses that
 * into ONE gzip stream. One stream on purpose: appending gzip members per
 * step would be valid gzip, but some importers (PHP's own gzdecode() among
 * them) stop after the first member, and a support engineer's phpMyAdmin
 * must see the whole dump. Large tables are paged by primary key, so the
 * thousandth page costs what the first did.
 *
 * All SQL goes through DatabaseSchemaRepository, on a dedicated connection
 * that fetches every value as the server's own text and works in UTC.
 */
final class DatabaseBackup
{
    public const FILE = 'database.sql.gz';
    public const METADATA = 'database.json';

    private const PART = 'database.sql.part';

    private const ROWS_PER_SELECT = 500;

    /** Keeps one INSERT well under the smallest max_allowed_packet (1 MB). */
    private const MAX_STATEMENT_BYTES = 262144;

    private DatabaseSchemaRepository $schema;

    public function __construct(
        private readonly string $directory,
        ?DatabaseSchemaRepository $schema = null
    ) {
        $this->schema = $schema ?? self::connectDedicated();
    }

    public static function connectDedicated(): DatabaseSchemaRepository
    {
        $connection = DatabaseSchemaRepository::dedicatedConnection();
        $connection->exec("SET time_zone = '+00:00'");

        return new DatabaseSchemaRepository($connection);
    }

    public function path(): string
    {
        return $this->directory . '/' . self::FILE;
    }

    /**
     * Begins a dump: lists what will be dumped and writes the header.
     *
     * @return array<string, mixed> the cursor for step()
     *
     * @throws UpdateException when the database holds something this backup cannot carry
     */
    public function start(): array
    {
        $objects = $this->schema->programmableObjects();
        if (array_sum($objects) > 0) {
            throw new UpdateException(
                'update.error.backup_unsupported',
                $objects,
                sprintf('Database has %d triggers, %d routines, %d events', $objects['triggers'], $objects['routines'], $objects['events'])
            );
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $this->directory], 'Cannot create ' . $this->directory);
        }

        @unlink($this->path());
        @unlink($this->directory . '/' . self::PART);

        $this->append(implode("\n", [
            '-- Mygdala database backup',
            '-- Database: ' . $this->schema->databaseName(),
            '-- Server: ' . $this->schema->serverVersion(),
            '-- Created: ' . UpdateState::now(),
            'SET NAMES utf8mb4;',
            "SET time_zone = '+00:00';",
            'SET FOREIGN_KEY_CHECKS = 0;',
            "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
            '',
        ]) . "\n");

        return [
            'tables' => $this->schema->tables(),
            'views' => $this->schema->views(),
            'table' => 0,
            'after' => null,
            'offset' => 0,
            'started_table' => false,
            'rows' => [],
            'done' => false,
        ];
    }

    /**
     * Writes rows until the budget is spent or the dump is complete.
     *
     * @param array<string, mixed> $cursor
     *
     * @return array<string, mixed> the next cursor; `done` is true at the end
     */
    public function step(array $cursor, float $budgetSeconds): array
    {
        $started = microtime(true);
        $tables = (array) $cursor['tables'];

        while ((int) $cursor['table'] < count($tables)) {
            $table = (string) $tables[(int) $cursor['table']];

            if (!$cursor['started_table']) {
                $create = $this->schema->createStatement($table);
                $this->append("\nDROP TABLE IF EXISTS " . DatabaseSchemaRepository::identifier($table) . ";\n" . $create . ";\n");
                $cursor['started_table'] = true;
                $cursor['rows'][$table] = 0;
            }

            $finished = $this->dumpRows($table, $cursor, $started, $budgetSeconds);

            if (!$finished) {
                return $cursor;
            }

            $cursor['table'] = (int) $cursor['table'] + 1;
            $cursor['after'] = null;
            $cursor['offset'] = 0;
            $cursor['started_table'] = false;

            if ((microtime(true) - $started) >= $budgetSeconds) {
                return $cursor;
            }
        }

        foreach ((array) $cursor['views'] as $view) {
            $create = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $this->schema->createViewStatement((string) $view)) ?? '';
            $this->append("\nDROP VIEW IF EXISTS " . DatabaseSchemaRepository::identifier((string) $view) . ";\n" . $create . ";\n");
        }

        $this->append("\nSET FOREIGN_KEY_CHECKS = 1;\n");
        $cursor['done'] = true;

        return $cursor;
    }

    /**
     * Checks the finished dump against the live database and writes the
     * metadata a restore verifies against.
     *
     * @param array<string, mixed> $cursor
     *
     * @return array<string, mixed> the metadata
     *
     * @throws UpdateException when a table's rows in the dump are not its rows now
     */
    public function finish(array $cursor): array
    {
        $rows = (array) $cursor['rows'];

        foreach ($rows as $table => $written) {
            $now = $this->schema->rowCount((string) $table);
            if ($now !== (int) $written) {
                throw new UpdateException(
                    'update.error.backup_inconsistent',
                    ['table' => (string) $table],
                    sprintf('%s: %d rows dumped, %d in the table', $table, $written, $now)
                );
            }
        }

        $metadata = [
            'format' => 1,
            'file' => self::FILE,
            'sha256' => $this->compress(),
            'bytes' => (int) filesize($this->path()),
            'database' => $this->schema->databaseName(),
            'server' => $this->schema->serverVersion(),
            'created_at' => UpdateState::now(),
            'tables' => $rows,
            'views' => array_values((array) $cursor['views']),
        ];

        UpdateStateStore::writeAtomically(
            $this->directory . '/' . self::METADATA,
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        return $metadata;
    }

    /**
     * @param array<string, mixed> $cursor
     *
     * @return bool whether the table is complete
     */
    private function dumpRows(string $table, array &$cursor, float $started, float $budgetSeconds): bool
    {
        $columns = $this->schema->columns($table);
        $names = array_column($columns, 'name');
        $kinds = array_column($columns, 'kind', 'name');

        if ($names === []) {
            return true;
        }

        $key = $this->pagingKey($table);
        $prefix = 'INSERT INTO ' . DatabaseSchemaRepository::identifier($table) . ' (' . DatabaseSchemaRepository::columnList($names) . ') VALUES ';

        while (true) {
            $rows = $key !== null
                ? $this->schema->rowsAfterKey($table, $names, $key, $cursor['after'] === null ? null : (string) $cursor['after'], self::ROWS_PER_SELECT)
                : $this->schema->rowsAtOffset($table, $names, $this->schema->primaryKey($table), (int) $cursor['offset'], self::ROWS_PER_SELECT);

            if ($rows === []) {
                return true;
            }

            $buffer = '';
            $values = [];
            $size = 0;

            foreach ($rows as $row) {
                $tuple = '(' . implode(',', array_map(
                    fn (string $column): string => $this->literal($row[$column] ?? null, (string) ($kinds[$column] ?? 'text')),
                    $names
                )) . ')';

                if ($values !== [] && $size + strlen($tuple) > self::MAX_STATEMENT_BYTES) {
                    $buffer .= $prefix . implode(',', $values) . ";\n";
                    $values = [];
                    $size = 0;
                }

                $values[] = $tuple;
                $size += strlen($tuple) + 1;
            }

            if ($values !== []) {
                $buffer .= $prefix . implode(',', $values) . ";\n";
            }

            $this->append($buffer);

            $cursor['rows'][$table] = (int) ($cursor['rows'][$table] ?? 0) + count($rows);
            if ($key !== null) {
                $cursor['after'] = (string) end($rows)[$key];
            } else {
                $cursor['offset'] = (int) $cursor['offset'] + count($rows);
            }

            if (count($rows) < self::ROWS_PER_SELECT) {
                return true;
            }

            if ((microtime(true) - $started) >= $budgetSeconds) {
                return false;
            }
        }
    }

    /** A single integer primary key allows keyset paging; anything else pages by offset. */
    private function pagingKey(string $table): ?string
    {
        $primary = $this->schema->primaryKey($table);

        if (count($primary) === 1 && $this->schema->isIntegerColumn($table, $primary[0])) {
            return $primary[0];
        }

        return null;
    }

    private function literal(?string $value, string $kind): string
    {
        if ($value === null) {
            return 'NULL';
        }

        // The server hands a BIT value out as its number; written back
        // unquoted it is that number again. As a quoted or hex string it
        // would be read as the bytes of the digits.
        if ($kind === 'bit' && ctype_digit($value)) {
            return $value;
        }

        $binary = $kind !== 'text';

        // Binary data, and the rare text value that is not valid UTF-8, go
        // in as hex: byte for byte, whatever the connection's character set.
        if ($binary || !mb_check_encoding($value, 'UTF-8')) {
            return "X'" . bin2hex($value) . "'";
        }

        return $this->schema->quote($value);
    }

    private function append(string $sql): void
    {
        if (@file_put_contents($this->directory . '/' . self::PART, $sql, FILE_APPEND) !== strlen($sql)) {
            throw new UpdateException('update.error.disk_full', [], 'Short write to ' . self::PART);
        }
    }

    /**
     * database.sql.part → database.sql.gz, one gzip stream; returns the
     * SHA-256 of the compressed file a restore will check.
     */
    private function compress(): string
    {
        $part = $this->directory . '/' . self::PART;
        $input = @fopen($part, 'rb');
        $output = @gzopen($this->path(), 'wb6');

        if ($input === false || $output === false) {
            throw new UpdateException('update.error.storage_not_writable', ['path' => $this->directory], 'Cannot compress the dump');
        }

        while (!feof($input)) {
            $chunk = (string) fread($input, 1048576);
            if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                fclose($input);
                gzclose($output);
                throw new UpdateException('update.error.disk_full', [], 'Short write to ' . self::FILE);
            }
        }

        fclose($input);
        gzclose($output);
        @unlink($part);

        return (string) hash_file('sha256', $this->path());
    }
}
