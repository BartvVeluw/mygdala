<?php

namespace App\Repository;

/**
 * All step_list_sections / step_list_items SQL lives here. A "section" is
 * one step list block on a page (e.g. index.php's "Werkwijze"); each section
 * has one or more "items" (its steps). Same shape/conventions as
 * FaqRepository (parent + child table, sort_order swap for reordering) —
 * see App\Service\StepListContent for the defaults/fallback layer built on
 * top of this.
 */
class StepListRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM step_list_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM step_list_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. Used by the admin step list edit
     * form's section-level fields. The heading's words, and every step's, are
     * stored per website language through App\Service\Blocks\BlockLocalization
     * (db/migrations/20260917190000).
     *
     * @param array{is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO step_list_sections
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
        $sql = 'SELECT * FROM step_list_items WHERE step_list_section_id = :step_list_section_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['step_list_section_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM step_list_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new, visible step to the end of a section and returns its id.
     * Its title and description are words, stored per website language
     * against that id (App\Service\Blocks\BlockLocalization), in the same
     * transaction as this insert.
     */
    public function createItem(int $sectionId): int
    {
        $nextSortOrder = $this->nextSortOrder($sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO step_list_items
                (step_list_section_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:step_list_section_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'step_list_section_id' => $sectionId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a step has that is the same in every language: whether it is
     * shown. Its words are saved through BlockLocalization. The id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array{is_active: bool} $values
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE step_list_items SET
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
     * Permanently removes a step — distinct from hiding one via is_active
     * (see updateItem). Used by the admin "Verwijderen" action, which removes
     * the step's words first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM step_list_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the steps of one Stappenplan as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $sectionId is
     * left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE step_list_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND step_list_section_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * steps — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of the section and of every
     * step first. This type has no uploaded media of its own, so no
     * filesystem cleanup is needed.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM step_list_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function nextSortOrder(int $sectionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM step_list_items WHERE step_list_section_id = :step_list_section_id'
        );
        $stmt->execute(['step_list_section_id' => $sectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
