<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Language\TranslationTable;
use PDO;

/**
 * The SQL of the typed `<entity>_translations` tables of Multilingual 2.0
 * phase 4: nav_item_translations, footer_column_translations,
 * footer_link_translations, form_translations, form_field_translations and
 * form_field_option_translations.
 *
 * One class for all of them because they have one shape
 * (App\Service\Language\TranslationTable). The table, owner column and field
 * names come only from that declaration, which the owning domain writes in
 * code and which refuses anything but a plain identifier.
 *
 * Deliberately dumb, like the SQL class of page_translations: which
 * language a reader gets, what an empty field falls back to and whether a
 * language may be written at all is App\Service\Language\EntityTranslations's
 * business, and nothing else calls this class. The schema holds the rules no
 * caller can walk past: one row per owner per language (UNIQUE), only a
 * registered language (a foreign key), and no words without their owner
 * (a foreign key with ON DELETE CASCADE).
 */
final class EntityTranslationRepository extends Repository
{
    public function __construct(private readonly TranslationTable $table, ?PDO $db = null)
    {
        parent::__construct($db);
    }

    /**
     * Every language's row of many owners in one query.
     *
     * @param list<int> $ownerIds
     * @return array<int, array<string, array<string, string|null>>> owner id => language code => field => stored value
     */
    public function findForOwners(array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_filter(
            array_map('intval', $ownerIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ownerIds === []) {
            return [];
        }

        $owner = $this->table->ownerColumn;
        $columns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $this->table->fieldNames()));
        $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));

        $stmt = $this->db->prepare(
            "SELECT `{$owner}` AS owner_id, language_code, {$columns}
               FROM `{$this->table->name}`
              WHERE `{$owner}` IN ({$placeholders})
              ORDER BY `{$owner}` ASC, id ASC"
        );
        $stmt->execute($ownerIds);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values = [];
            foreach ($this->table->fieldNames() as $field) {
                $values[$field] = $row[$field] === null ? null : (string) $row[$field];
            }
            $rows[(int) $row['owner_id']][(string) $row['language_code']] = $values;
        }

        return $rows;
    }

    /**
     * The owners whose $field contains $needle IN ANY LANGUAGE — what a CMS
     * search box needs, and the reason it lives here: a domain repository must
     * not name a translation table in its own SQL
     * (Tests\Service\MultilingualBoundaryTest).
     *
     * Searching every language rather than a chosen one is deliberate: an
     * editor looking for a post looks for a title they remember, and which
     * language they remember it in is not the search's business.
     *
     * $needle is matched with LIKE and escaped for it, so a search for "50%"
     * or "a_b" finds those characters rather than any two characters.
     *
     * @return list<int> ascending, each owner once
     */
    public function ownersMatching(string $field, string $needle): array
    {
        if (!$this->table->has($field) || trim($needle) === '') {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT `{$this->table->ownerColumn}` AS owner_id
               FROM `{$this->table->name}`
              WHERE `{$field}` LIKE :needle
              ORDER BY owner_id ASC"
        );
        $stmt->execute([
            'needle' => '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%',
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Writes one language's row of one owner: a new row, or every field of
     * the existing row replaced.
     *
     * One statement, so a save never reads first and cannot race another save
     * into a duplicate. Takes part in a transaction already open on the
     * connection.
     *
     * @param array<string, string|null> $values every declared field
     */
    public function save(int $ownerId, string $languageCode, array $values): void
    {
        $fields = $this->table->fieldNames();
        $owner = $this->table->ownerColumn;

        $insertColumns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $fields));
        $insertValues = implode(', ', array_map(static fn (string $field): string => ':' . $field, $fields));
        // Every parameter named once: native prepares (App\Database) do not
        // allow a name to be used twice in one statement.
        $updates = implode(', ', array_map(static fn (string $field): string => '`' . $field . '` = :' . $field . '_update', $fields));

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table->name}`
                (`{$owner}`, language_code, {$insertColumns}, created_at, updated_at)
             VALUES
                (:owner_id, :language_code, {$insertValues}, NOW(), NOW())
             ON DUPLICATE KEY UPDATE {$updates}, updated_at = NOW()"
        );

        $parameters = ['owner_id' => $ownerId, 'language_code' => $languageCode];
        foreach ($fields as $field) {
            $parameters[$field] = $values[$field] ?? null;
            $parameters[$field . '_update'] = $values[$field] ?? null;
        }

        $stmt->execute($parameters);
    }

    public function delete(int $ownerId, string $languageCode): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->table->name}` WHERE `{$this->table->ownerColumn}` = :owner_id AND language_code = :language_code"
        );
        $stmt->execute(['owner_id' => $ownerId, 'language_code' => $languageCode]);
    }
}
