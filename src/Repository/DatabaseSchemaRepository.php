<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * SQL about the database ITSELF rather than about one domain: which server
 * this is, which tables exist, how each is defined, and its rows in order.
 *
 * The self-updater's repository (docs/updates/ARCHITECTURE.md): Preflight
 * asks it for the server version, DatabaseBackup reads every table through it
 * and DatabaseRestore writes the dump back through it. Every table and column
 * name that reaches a query here comes from the server's own catalogue
 * (tables(), columns()), never from a request or a file, and is quoted as an
 * identifier; values travel as bound parameters or through PDO::quote().
 *
 * The backup runs on its own connection (App\Database::open()), with every
 * value fetched as the server's own text, so a DECIMAL, a DOUBLE or a
 * DATETIME comes back exactly as MySQL would print it and goes back in
 * unchanged. That is why this repository accepts any PDO rather than only
 * the request's shared one.
 */
final class DatabaseSchemaRepository extends Repository
{
    public function __construct(?PDO $db = null)
    {
        parent::__construct($db);
    }

    /** A connection shaped for dumping and restoring: text values, own session. */
    public static function dedicatedConnection(): PDO
    {
        return \App\Database::open([
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
    }

    /** "8.0.36" or "10.6.12-MariaDB-…", as the server reports it. */
    public function serverVersion(): string
    {
        return (string) $this->db->query('SELECT VERSION()')->fetchColumn();
    }

    public function databaseName(): string
    {
        return (string) $this->db->query('SELECT DATABASE()')->fetchColumn();
    }

    /** @return list<string> base tables, sorted */
    public function tables(): array
    {
        return $this->namesOfType('BASE TABLE');
    }

    /** @return list<string> views, sorted */
    public function views(): array
    {
        return $this->namesOfType('VIEW');
    }

    /**
     * Server-side code a table-and-row dump does not carry. The backup
     * refuses a database that has any, rather than producing a restore that
     * silently loses it.
     *
     * @return array{triggers: int, routines: int, events: int}
     */
    public function programmableObjects(): array
    {
        $count = function (string $sql): int {
            $statement = $this->db->prepare($sql);
            $statement->execute([$this->databaseName()]);

            return (int) $statement->fetchColumn();
        };

        return [
            'triggers' => $count('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?'),
            'routines' => $count('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?'),
            'events' => $count('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?'),
        ];
    }

    /** Bytes of data and indexes, as the server estimates them. */
    public function estimatedSize(): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?'
        );
        $statement->execute([$this->databaseName()]);

        return (int) $statement->fetchColumn();
    }

    public function createStatement(string $table): string
    {
        $row = $this->db->query('SHOW CREATE TABLE ' . self::identifier($table))->fetch(PDO::FETCH_NUM);

        return (string) ($row[1] ?? '');
    }

    public function createViewStatement(string $view): string
    {
        $row = $this->db->query('SHOW CREATE VIEW ' . self::identifier($view))->fetch(PDO::FETCH_NUM);

        return (string) ($row[1] ?? '');
    }

    /**
     * The columns a row can be written back into, in table order. Generated
     * columns are left out: MySQL computes them and refuses a value.
     *
     * @return list<array{name: string, binary: bool}>
     */
    public function columns(string $table): array
    {
        $statement = $this->db->prepare(
            'SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$this->databaseName(), $table]);

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (stripos((string) $row['EXTRA'], 'GENERATED') !== false) {
                continue;
            }

            $type = strtolower((string) $row['DATA_TYPE']);
            $columns[] = [
                'name' => (string) $row['COLUMN_NAME'],
                'binary' => in_array($type, ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon'], true),
            ];
        }

        return $columns;
    }

    /**
     * The primary key's columns in key order; [] for a table without one.
     *
     * @return list<string>
     */
    public function primaryKey(string $table): array
    {
        $statement = $this->db->prepare(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY'
              ORDER BY ORDINAL_POSITION"
        );
        $statement->execute([$this->databaseName(), $table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Is $column an integer column (so keyset paging on it is safe)? */
    public function isIntegerColumn(string $table, string $column): bool
    {
        $statement = $this->db->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $statement->execute([$this->databaseName(), $table, $column]);

        return in_array(strtolower((string) $statement->fetchColumn()), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true);
    }

    public function rowCount(string $table): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM ' . self::identifier($table))->fetchColumn();
    }

    /**
     * Rows after $after on a single integer key, in key order: keyset paging,
     * so page 400 of a large table costs what page 1 did.
     *
     * @param list<string> $columns
     *
     * @return list<array<string, string|null>>
     */
    public function rowsAfterKey(string $table, array $columns, string $key, ?string $after, int $limit): array
    {
        $sql = 'SELECT ' . self::columnList($columns) . ' FROM ' . self::identifier($table)
            . ($after !== null ? ' WHERE ' . self::identifier($key) . ' > ?' : '')
            . ' ORDER BY ' . self::identifier($key) . ' LIMIT ' . max(1, $limit);

        $statement = $this->db->prepare($sql);
        $statement->execute($after !== null ? [$after] : []);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rows by position, for a table without a single integer key. Ordered by
     * the primary key when there is one, so the pages are stable; the site
     * is in maintenance while this runs, so nothing moves between pages.
     *
     * @param list<string> $columns
     * @param list<string> $orderBy
     *
     * @return list<array<string, string|null>>
     */
    public function rowsAtOffset(string $table, array $columns, array $orderBy, int $offset, int $limit): array
    {
        $sql = 'SELECT ' . self::columnList($columns) . ' FROM ' . self::identifier($table)
            . ($orderBy !== [] ? ' ORDER BY ' . self::columnList($orderBy) : '')
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function quote(string $value): string
    {
        return $this->db->quote($value);
    }

    /** Runs one statement from a dump this updater wrote itself (DatabaseRestore). */
    public function execute(string $statement): void
    {
        $this->db->exec($statement);
    }

    public function dropTable(string $table): void
    {
        $this->db->exec('DROP TABLE IF EXISTS ' . self::identifier($table));
    }

    public function dropView(string $view): void
    {
        $this->db->exec('DROP VIEW IF EXISTS ' . self::identifier($view));
    }

    public static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** @param list<string> $columns */
    public static function columnList(array $columns): string
    {
        return implode(', ', array_map([self::class, 'identifier'], $columns));
    }

    /** @return list<string> */
    private function namesOfType(string $type): array
    {
        $statement = $this->db->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME'
        );
        $statement->execute([$this->databaseName(), $type]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
