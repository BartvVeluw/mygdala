<?php

namespace App\Repository;

/**
 * All page_sections SQL — the page builder's junction table between a page,
 * an ordered position on it, and the block type's own content row.
 * See db/migrations/20260907160000_create_page_sections_table.php for the
 * original schema rationale (the separate is_active flag, the
 * (section_type, section_id) uniqueness),
 * .../20260908100200_add_page_id_to_page_sections.php for the `page_id`
 * foreign key added when the unified `pages` model landed, and
 * .../20260908250000_flatten_page_sections_into_one_list.php for why
 * `zone_key` is gone: one page is ONE ordered list of blocks, so a single
 * `sort_order` sequence per page covers all of it.
 *
 * Two page columns, on purpose:
 *   - `page_id`   — the real foreign key to pages.id, and what every query
 *                   here selects and scopes by. Renaming a page's public
 *                   slug cannot affect it.
 *   - `page_slug` — the owning page's IMMUTABLE pages.content_key, kept
 *                   because it is also what every section CONTENT table
 *                   (page_heroes, feature_grids, rich_text_sections, ...) is
 *                   keyed by, so App\Service\SectionRegistry::render() can
 *                   look content up straight from a page_sections row. It is
 *                   never the public URL slug and never changes.
 */
class PageSectionRepository extends Repository
{
    /**
     * Every block attached to a page, in the one order the page renders
     * them. Pass $onlyActive = true for frontend rendering (excludes blocks
     * hidden via the page builder — a block's own dedicated editor's
     * is_active is a separate check the caller still needs to make per
     * block, see App\Service\SectionRegistry::render()).
     *
     * @return list<array<string, mixed>>
     */
    public function findForPage(int $pageId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM page_sections WHERE page_id = :page_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM page_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The ids of every page carrying at least one block of these types, in
     * one query — what App\Service\PageContent::isProtected() needs to
     * decide "does this page carry application-critical functionality?"
     * without asking per page.
     *
     * @param list<string> $sectionTypes
     *
     * @return list<int>
     */
    public function pageIdsWithSectionTypes(array $sectionTypes): array
    {
        if ($sectionTypes === []) {
            return [];
        }

        // Placeholders are generated from the COUNT of an internal,
        // registry-derived list — never from its values, which are not
        // interpolated into the SQL at all.
        $placeholders = implode(', ', array_fill(0, count($sectionTypes), '?'));

        $stmt = $this->db->prepare(
            "SELECT DISTINCT page_id FROM page_sections WHERE section_type IN ({$placeholders})"
        );
        $stmt->execute(array_values($sectionTypes));

        return array_map(static fn (array $row): int => (int) $row['page_id'], $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>|null null when this content row isn't attached to any page
     */
    public function findBySectionTypeAndId(string $sectionType, int $sectionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM page_sections WHERE section_type = :section_type AND section_id = :section_id LIMIT 1'
        );
        $stmt->execute(['section_type' => $sectionType, 'section_id' => $sectionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Attaches a content row to a page at the BOTTOM of its block list —
     * where "+ Sectie toevoegen" sits in the admin, so a newly added block
     * always appears where the editor just clicked.
     * Fails loudly (unique-key violation) rather than silently if the
     * content row is already attached elsewhere — callers must not attach
     * the same (section_type, section_id) twice.
     *
     * $pageContentKey must be the owning page's immutable pages.content_key
     * (which is also the page_slug the block's own content row is stored
     * under).
     */
    public function create(
        int $pageId,
        string $pageContentKey,
        string $sectionType,
        ?string $sectionKey,
        int $sectionId
    ): int {
        $nextSortOrder = $this->nextSortOrder($pageId);

        $stmt = $this->db->prepare(
            'INSERT INTO page_sections
                (page_id, page_slug, section_type, section_key, section_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:page_id, :page_slug, :section_type, :section_key, :section_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'page_id' => $pageId,
            'page_slug' => $pageContentKey,
            'section_type' => $sectionType,
            'section_key' => $sectionKey,
            'section_id' => $sectionId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Detaches one block and closes the hole it leaves: the page is
     * renumbered to 0..n-1 straight afterwards. Without that, deleting a
     * block from the page builder would leave a permanent gap in the page's
     * sort_order — harmless for rendering (everything is ORDER BY
     * sort_order), but it breaks the "one page = one contiguous ordered
     * list" invariant the whole page-builder rests on, and the next
     * flatten-style migration or reorder would have to guess.
     */
    public function delete(int $id): bool
    {
        $row = $this->findById($id);

        $stmt = $this->db->prepare('DELETE FROM page_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        if ($row !== null) {
            $this->resequence((int) $row['page_id']);
        }

        return true;
    }

    /**
     * Renumbers one page's block list to 0..n-1 without changing the order
     * itself — the repair half of the invariant delete() maintains.
     *
     * Deliberately NOT wrapped in its own transaction: the page builder's
     * delete path (App\Service\SectionRegistry::delete()) already runs
     * inside one, and PDO cannot nest them. Rows that are already in the
     * right position are skipped, so on a healthy page this writes nothing.
     */
    public function resequence(int $pageId): void
    {
        $stmt = $this->db->prepare('UPDATE page_sections SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');

        foreach ($this->findForPage($pageId) as $position => $row) {
            if ((int) $row['sort_order'] === $position) {
                continue;
            }

            $stmt->execute(['sort_order' => $position, 'id' => (int) $row['id']]);
        }
    }

    public function setActive(int $id, bool $isActive): void
    {
        $stmt = $this->db->prepare('UPDATE page_sections SET is_active = :is_active, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    /**
     * Persists a new display order for one page's block list (drag-and-drop
     * in admin/page.php). Only ids that actually belong to this exact page
     * are honored — a submitted id for another page (forged or stale
     * request) is silently ignored rather than allowed to move a block it
     * has no business touching; any of the page's own rows missing from
     * $orderedIds keep their current relative position at the end, so a
     * partial/racy submission can't corrupt the rest of the order.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(int $pageId, array $orderedIds): void
    {
        $current = $this->findForPage($pageId);
        $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $current);
        $currentIdSet = array_flip($currentIds);

        $validOrderedIds = array_values(array_filter(
            $orderedIds,
            static fn (int $id): bool => isset($currentIdSet[$id])
        ));

        // Any of the page's rows the caller didn't mention keep their
        // relative order, appended after the ones it did.
        $remaining = array_values(array_diff($currentIds, $validOrderedIds));
        $finalOrder = array_merge($validOrderedIds, $remaining);

        $this->db->beginTransaction();
        try {
            foreach ($finalOrder as $position => $id) {
                $stmt = $this->db->prepare('UPDATE page_sections SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['sort_order' => $position, 'id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function nextSortOrder(int $pageId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM page_sections WHERE page_id = :page_id'
        );
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
