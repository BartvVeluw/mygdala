<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `block_translations` SQL (db/migrations/20260917160000).
 *
 * Deliberately dumb, like the repository of page_translations: which
 * owner tables and fields exist, which language a reader gets and what an
 * empty field falls back to is App\Service\Blocks\BlockLocalization's
 * business, and nothing else calls this class.
 *
 * `owner_table` reaches SQL as a bound VALUE everywhere, except where the
 * owner table itself has to be read (ownerExists() and the orphan queries).
 * Those take the name from BlockLocalization's closed registry and refuse
 * anything that is not a plain table identifier before it is interpolated.
 */
final class BlockTranslationRepository extends Repository
{
    /**
     * Every stored field of many owners, in one query: what a page of blocks
     * needs before it renders.
     *
     * @param array<string, list<int>> $ownerIds owner table => row ids
     * @return array<string, array<int, array<string, array<string, string>>>> table => id => language => field => value
     */
    public function findForOwners(array $ownerIds): array
    {
        $clauses = [];
        $parameters = [];

        foreach ($ownerIds as $table => $ids) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
            if ($ids === []) {
                continue;
            }

            $clauses[] = '(owner_table = ? AND owner_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $parameters[] = (string) $table;
            array_push($parameters, ...$ids);
        }

        if ($clauses === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT owner_table, owner_id, language_code, field, value
               FROM block_translations
              WHERE ' . implode(' OR ', $clauses) . '
              ORDER BY owner_table, owner_id, id'
        );
        $stmt->execute($parameters);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['owner_table']][(int) $row['owner_id']][(string) $row['language_code']][(string) $row['field']] = (string) $row['value'];
        }

        return $rows;
    }

    /**
     * Makes one owner's stored fields in one language exactly $values: every
     * field in $values is inserted or replaced, every other field of that
     * owner in that language is deleted.
     *
     * A field whose words did not change keeps its updated_at, so the moment
     * a field was last really changed stays readable per field. Takes part in
     * a transaction already open on the connection; the caller opens one
     * otherwise, because this is two statements.
     *
     * @param array<string, string> $values field => words, none of them empty
     */
    public function replaceLanguage(string $ownerTable, int $ownerId, string $languageCode, array $values): void
    {
        $delete = 'DELETE FROM block_translations WHERE owner_table = ? AND owner_id = ? AND language_code = ?';
        $parameters = [$ownerTable, $ownerId, $languageCode];

        if ($values !== []) {
            $delete .= ' AND field NOT IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($parameters, ...array_map('strval', array_keys($values)));
        }

        $this->db->prepare($delete)->execute($parameters);

        if ($values === []) {
            return;
        }

        $rows = [];
        $parameters = [];
        foreach ($values as $field => $value) {
            $rows[] = '(?, ?, ?, ?, ?, NOW(), NOW())';
            array_push($parameters, $ownerTable, $ownerId, $languageCode, (string) $field, $value);
        }

        // updated_at is assigned BEFORE value: MySQL evaluates these left to
        // right, so the comparison still sees the stored words. BINARY, so a
        // change of capitals counts as a change under the table's _ci collation.
        $this->db->prepare(
            'INSERT INTO block_translations
                (owner_table, owner_id, language_code, field, value, created_at, updated_at)
             VALUES ' . implode(', ', $rows) . '
             ON DUPLICATE KEY UPDATE
                updated_at = IF(BINARY value <=> BINARY VALUES(value), updated_at, NOW()),
                value = VALUES(value)'
        )->execute($parameters);
    }

    /**
     * Does the owner row exist? Asked before words are written, so a save can
     * never create an orphan. Sees rows inserted earlier in the same open
     * transaction, as a block's create() needs.
     */
    public function ownerExists(string $ownerTable, int $ownerId): bool
    {
        $table = self::identifier($ownerTable);

        $stmt = $this->db->prepare("SELECT 1 FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$ownerId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Removes every language of one owner. Takes part in an open transaction. */
    public function deleteOwner(string $ownerTable, int $ownerId): int
    {
        $stmt = $this->db->prepare('DELETE FROM block_translations WHERE owner_table = ? AND owner_id = ?');
        $stmt->execute([$ownerTable, $ownerId]);

        return $stmt->rowCount();
    }

    /**
     * Rows of one owner table whose owner row no longer exists.
     *
     * @return list<array{owner_id: int, rows: int}>
     */
    public function findMissingOwners(string $ownerTable): array
    {
        $table = self::identifier($ownerTable);

        $stmt = $this->db->prepare(
            "SELECT t.owner_id, COUNT(*) AS row_count
               FROM block_translations t
               LEFT JOIN `{$table}` o ON o.id = t.owner_id
              WHERE t.owner_table = ? AND o.id IS NULL
              GROUP BY t.owner_id
              ORDER BY t.owner_id"
        );
        $stmt->execute([$ownerTable]);

        return array_map(
            static fn (array $row): array => ['owner_id' => (int) $row['owner_id'], 'rows' => (int) $row['row_count']],
            $stmt->fetchAll()
        );
    }

    /** Deletes the rows findMissingOwners() reports for one owner table. */
    public function deleteMissingOwners(string $ownerTable): int
    {
        $table = self::identifier($ownerTable);

        $stmt = $this->db->prepare(
            "DELETE t FROM block_translations t
               LEFT JOIN `{$table}` o ON o.id = t.owner_id
              WHERE t.owner_table = ? AND o.id IS NULL"
        );
        $stmt->execute([$ownerTable]);

        return $stmt->rowCount();
    }

    /**
     * Rows of one owner table in a field it does not declare (any more).
     *
     * @param list<string> $declaredFields
     * @return list<array{field: string, rows: int}>
     */
    public function findUndeclaredFields(string $ownerTable, array $declaredFields): array
    {
        $sql = 'SELECT field, COUNT(*) AS row_count FROM block_translations WHERE owner_table = ?';
        $parameters = [$ownerTable];

        if ($declaredFields !== []) {
            $sql .= ' AND field NOT IN (' . implode(',', array_fill(0, count($declaredFields), '?')) . ')';
            array_push($parameters, ...$declaredFields);
        }

        $stmt = $this->db->prepare($sql . ' GROUP BY field ORDER BY field');
        $stmt->execute($parameters);

        return array_map(
            static fn (array $row): array => ['field' => (string) $row['field'], 'rows' => (int) $row['row_count']],
            $stmt->fetchAll()
        );
    }

    /**
     * Rows whose owner table no registered block declares: a block type that
     * is gone, or belongs to a module that is switched off.
     *
     * @param list<string> $registeredTables
     * @return list<array{owner_table: string, rows: int}>
     */
    public function findUnregisteredOwnerTables(array $registeredTables): array
    {
        $sql = 'SELECT owner_table, COUNT(*) AS row_count FROM block_translations';
        $parameters = [];

        if ($registeredTables !== []) {
            $sql .= ' WHERE owner_table NOT IN (' . implode(',', array_fill(0, count($registeredTables), '?')) . ')';
            $parameters = $registeredTables;
        }

        $stmt = $this->db->prepare($sql . ' GROUP BY owner_table ORDER BY owner_table');
        $stmt->execute($parameters);

        return array_map(
            static fn (array $row): array => ['owner_table' => (string) $row['owner_table'], 'rows' => (int) $row['row_count']],
            $stmt->fetchAll()
        );
    }

    private static function identifier(string $table): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $table) !== 1) {
            throw new \InvalidArgumentException('"' . $table . '" is not a table name.');
        }

        return $table;
    }
}
