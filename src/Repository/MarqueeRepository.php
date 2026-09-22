<?php

namespace App\Repository;

/**
 * All marquee_sections / marquee_items SQL lives here. A "section" is one
 * marquee block on a page (currently only index.php's materialenband); each
 * section has one or more "items" (its scrolling labels). Same shape/
 * conventions as StatStripRepository (parent + child table, sort_order swap
 * for reordering) — see App\Service\MarqueeContent for the defaults/
 * fallback layer built on top of this.
 */
class MarqueeRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM marquee_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM marquee_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     * Used by the admin Marquee edit form's visibility checkbox.
     *
     * @param array<string, bool> $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO marquee_sections
                (page_slug, section_key, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsBySectionId(int $sectionId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM marquee_items WHERE marquee_section_id = :marquee_section_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['marquee_section_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM marquee_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new, visible item to the end of a section and returns its id.
     * Its label is words, stored per website language against that id
     * (App\Service\Blocks\BlockLocalization, db/migrations/20260917190000),
     * in the same transaction as this insert.
     */
    public function createItem(int $sectionId): int
    {
        $nextSortOrder = $this->nextSortOrder($sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO marquee_items
                (marquee_section_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:marquee_section_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'marquee_section_id' => $sectionId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What an item has that is the same in every language: whether it is
     * shown. Its label is saved through BlockLocalization. The id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array{is_active: bool} $values
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE marquee_items SET
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes an item — distinct from hiding one via is_active
     * (see updateItem). Used by the admin "Verwijderen" action, which removes
     * the item's words first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM marquee_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the words of one Woordenband as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $sectionId is
     * left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE marquee_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND marquee_section_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * items — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of every item first. This
     * type has no uploaded media of its own, so no filesystem cleanup is
     * needed.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM marquee_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function nextSortOrder(int $sectionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM marquee_items WHERE marquee_section_id = :marquee_section_id'
        );
        $stmt->execute(['marquee_section_id' => $sectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
