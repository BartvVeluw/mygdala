<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The site search's derived block-text index (Search 2.0,
 * db/migrations/20261013100000): `search_block_texts`, one row per placed
 * block and website language, and `search_index_state`, the fingerprint it
 * was built with. App\Service\Search\BlockSearchIndex is the only caller.
 *
 * Identifiers that are not literals here — a block's child table and its
 * parent column, an owner's link table and column, block types — are code
 * constants of block definitions and content owners, never request data, and
 * are checked against a plain identifier pattern anyway. Every value is a
 * bound parameter; a search term goes through the same escaped LIKE as
 * EntityTranslationRepository::ownersMatching().
 */
final class SearchIndexRepository extends Repository
{
    /** Longest text stored per block and language (characters). */
    public const MAX_BODY = 100000;
    public const MAX_HEADINGS = 10000;

    // ------------------------------------------------------------ the state

    public function fingerprint(string $index): ?string
    {
        $stmt = $this->db->prepare('SELECT fingerprint FROM search_index_state WHERE index_name = :name');
        $stmt->execute(['name' => $index]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function saveFingerprint(string $index, string $fingerprint): void
    {
        $this->db->prepare(
            'INSERT INTO search_index_state (index_name, fingerprint, built_at) VALUES (:name, :fingerprint, NOW())
             ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint), built_at = NOW()'
        )->execute(['name' => $index, 'fingerprint' => $fingerprint]);
    }

    public function forgetFingerprint(string $index): void
    {
        $this->db->prepare('DELETE FROM search_index_state WHERE index_name = :name')->execute(['name' => $index]);
    }

    /**
     * A MySQL named lock, so two requests never rebuild at once. Scoped to
     * this database: installations sharing one MySQL server do not wait on
     * each other. No wait: a request that does not get it goes on without.
     */
    public function tryLock(string $index): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(CONCAT(DATABASE(), :suffix), 0)');
        $stmt->execute(['suffix' => '.search_index.' . $index]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function unlock(string $index): void
    {
        $this->db->prepare('SELECT RELEASE_LOCK(CONCAT(DATABASE(), :suffix))')->execute(['suffix' => '.search_index.' . $index]);
    }

    // ------------------------------------------------------------ reading blocks

    /**
     * The rows of one block child table that hang under these parent rows,
     * in their own order (sort_order where the table has one, then id).
     *
     * @param list<int> $parentIds
     * @return list<array<string, mixed>>
     */
    public function childRows(string $table, string $parentColumn, array $parentIds): array
    {
        $parentIds = self::ids($parentIds);
        self::identifiers([$table, $parentColumn]);

        if ($parentIds === []) {
            return [];
        }

        $rows = $this->db->query("SELECT * FROM `{$table}` WHERE `{$parentColumn}` IN (" . implode(', ', $parentIds) . ')')->fetchAll();

        usort($rows, static fn (array $a, array $b): int => [(int) ($a['sort_order'] ?? 0), (int) $a['id']] <=> [(int) ($b['sort_order'] ?? 0), (int) $b['id']]);

        return $rows;
    }

    /** @return list<int> every page id, ordinary pages and content pages */
    public function allPageIds(): array
    {
        return array_map('intval', $this->db->query('SELECT id FROM pages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ------------------------------------------------------------ writing

    /**
     * Replaces the rows of these blocks with $rows (an empty $rows removes
     * them). $rows: page_section_id, page_id, section_type, language_code,
     * heading_text, body_text.
     *
     * @param list<int> $pageSectionIds
     * @param list<array{page_section_id: int, page_id: int, section_type: string, language_code: string, heading_text: string, body_text: string}> $rows
     */
    public function replaceSections(array $pageSectionIds, array $rows): void
    {
        $pageSectionIds = self::ids($pageSectionIds);

        if ($pageSectionIds !== []) {
            $this->db->exec('DELETE FROM search_block_texts WHERE page_section_id IN (' . implode(', ', $pageSectionIds) . ')');
        }

        $this->insert($rows);
    }

    /**
     * Replaces every row of these pages (blocks that are gone or hidden lose
     * theirs).
     *
     * @param list<int> $pageIds
     * @param list<array{page_section_id: int, page_id: int, section_type: string, language_code: string, heading_text: string, body_text: string}> $rows
     */
    public function replacePages(array $pageIds, array $rows): void
    {
        $pageIds = self::ids($pageIds);

        if ($pageIds !== []) {
            $this->db->exec('DELETE FROM search_block_texts WHERE page_id IN (' . implode(', ', $pageIds) . ')');
        }

        $this->insert($rows);
    }

    /** Rows of a language that is no longer on the site (after a rebuild). */
    public function deleteOtherLanguages(array $languages): void
    {
        $languages = array_values(array_filter($languages, 'is_string'));

        if ($languages === []) {
            $this->db->exec('DELETE FROM search_block_texts');

            return;
        }

        $marks = implode(', ', array_fill(0, count($languages), '?'));
        $this->db->prepare("DELETE FROM search_block_texts WHERE language_code NOT IN ({$marks})")->execute($languages);
    }

    // ------------------------------------------------------------ searching

    /**
     * Pages with a block of these types whose words in $language contain at
     * least one of $needles.
     *
     * @param list<string> $needles
     * @param list<string> $types
     * @return list<int>
     */
    public function pagesMatchingAny(array $needles, string $language, array $types): array
    {
        [$where, $params] = $this->matchClause($needles, $language, $types);

        if ($where === null) {
            return [];
        }

        $stmt = $this->db->prepare('SELECT DISTINCT s.page_id FROM search_block_texts s WHERE ' . $where);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Owners (through their link table to their content page) with a block
     * whose words in $language contain $needle.
     *
     * @param list<string> $types
     * @return list<int>
     */
    public function ownersMatching(string $linkTable, string $linkColumn, string $needle, string $language, array $types): array
    {
        self::identifiers([$linkTable, $linkColumn]);
        [$where, $params] = $this->matchClause([$needle], $language, $types);

        if ($where === null) {
            return [];
        }

        $stmt = $this->db->prepare("SELECT DISTINCT l.`{$linkColumn}` FROM search_block_texts s JOIN `{$linkTable}` l ON l.page_id = s.page_id WHERE " . $where);
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The words of the shown blocks of these pages in $language, in the
     * order of the page: page id => list of [heading_text, body_text].
     *
     * @param list<int> $pageIds
     * @param list<string> $types
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function textsForPages(array $pageIds, string $language, array $types): array
    {
        $pageIds = self::ids($pageIds);
        $types = self::types($types);

        if ($pageIds === [] || $types === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($types), '?'));
        $stmt = $this->db->prepare(
            'SELECT s.page_id, s.heading_text, s.body_text
               FROM search_block_texts s
               JOIN page_sections ps ON ps.id = s.page_section_id AND ps.is_active = 1
              WHERE s.page_id IN (' . implode(', ', $pageIds) . ") AND s.language_code = ? AND s.section_type IN ({$marks})
              ORDER BY s.page_id, ps.sort_order, ps.id"
        );
        $stmt->execute([$language, ...$types]);

        $texts = [];
        foreach ($stmt->fetchAll() as $row) {
            $texts[(int) $row['page_id']][] = [(string) $row['heading_text'], (string) $row['body_text']];
        }

        return $texts;
    }

    /**
     * Content page ids of these owners: owner id => page id.
     *
     * @param list<int> $ownerIds
     * @return array<int, int>
     */
    public function contentPagesOf(string $linkTable, string $linkColumn, array $ownerIds): array
    {
        self::identifiers([$linkTable, $linkColumn]);
        $ownerIds = self::ids($ownerIds);

        if ($ownerIds === []) {
            return [];
        }

        $rows = $this->db->query("SELECT `{$linkColumn}` AS owner_id, page_id FROM `{$linkTable}` WHERE `{$linkColumn}` IN (" . implode(', ', $ownerIds) . ')')->fetchAll();
        $pages = [];
        foreach ($rows as $row) {
            $pages[(int) $row['owner_id']] = (int) $row['page_id'];
        }

        return $pages;
    }

    // ------------------------------------------------------------ helpers

    /** @param list<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO search_block_texts (page_section_id, page_id, section_type, language_code, heading_text, body_text, updated_at)
             VALUES (:page_section_id, :page_id, :section_type, :language_code, :heading_text, :body_text, NOW())'
        );

        foreach ($rows as $row) {
            $stmt->execute([
                'page_section_id' => (int) $row['page_section_id'],
                'page_id' => (int) $row['page_id'],
                'section_type' => (string) $row['section_type'],
                'language_code' => (string) $row['language_code'],
                'heading_text' => mb_substr((string) $row['heading_text'], 0, self::MAX_HEADINGS),
                'body_text' => mb_substr((string) $row['body_text'], 0, self::MAX_BODY),
            ]);
        }
    }

    /**
     * @param list<string> $needles
     * @param list<string> $types
     * @return array{0: string|null, 1: list<string>}
     */
    private function matchClause(array $needles, string $language, array $types): array
    {
        $types = self::types($types);
        $needles = array_values(array_filter($needles, static fn (string $needle): bool => $needle !== ''));

        if ($types === [] || $needles === []) {
            return [null, []];
        }

        $params = [$language, ...$types];
        $any = [];
        foreach ($needles as $needle) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%';
            $any[] = '(s.heading_text LIKE ? OR s.body_text LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return ['s.language_code = ? AND s.section_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ') AND (' . implode(' OR ', $any) . ')', $params];
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param list<string> $types
     * @return list<string>
     */
    private static function types(array $types): array
    {
        return array_values(array_filter($types, static fn ($type): bool => is_string($type) && preg_match('/^[a-z][a-z0-9_]{0,49}$/', $type) === 1));
    }

    /** @param list<string> $identifiers */
    private static function identifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $identifier) !== 1) {
                throw new \InvalidArgumentException('Not a table or column name: ' . $identifier);
            }
        }
    }
}
