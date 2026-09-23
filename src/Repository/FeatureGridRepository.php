<?php

namespace App\Repository;

/**
 * All feature_grids / feature_grid_items SQL lives here. A "grid" is one
 * page section (e.g. "Mijn stijl" on over-mij.php); each grid has one or
 * more "items" (its cards). Same shape/conventions as
 * ProductOptionRepository (parent + child table, sort_order swap for
 * reordering) — see App\Service\FeatureGridContent for the defaults/
 * fallback layer built on top of this.
 */
class FeatureGridRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM feature_grids WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM feature_grids WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. Used by the admin Feature Grid edit
     * form's section-level fields. The heading's words, and every card's, are
     * stored per website language through App\Service\Blocks\BlockLocalization
     * (db/migrations/20260917190000).
     *
     * @param array{is_active?: bool} $values
     */
    public function upsertGrid(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO feature_grids
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
    public function findItemsByGridId(int $gridId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM feature_grid_items WHERE feature_grid_id = :feature_grid_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['feature_grid_id' => $gridId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a new, visible card to the end of a grid and returns its id.
     * Its title and text are words, stored per website language against that
     * id (App\Service\Blocks\BlockLocalization), in the same transaction as
     * this insert.
     *
     * @param array{icon_key: string, icon_media_id?: int|null} $values
     */
    public function createItem(int $gridId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder($gridId);

        $stmt = $this->db->prepare(
            'INSERT INTO feature_grid_items
                (feature_grid_id, icon_key, icon_media_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:feature_grid_id, :icon_key, :icon_media_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'feature_grid_id' => $gridId,
            'icon_key' => $values['icon_key'],
            'icon_media_id' => $values['icon_media_id'] ?? null,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a card has that is the same in every language: its icon (a key,
     * and the media item of a custom one) and whether it is shown. Its words
     * are saved through BlockLocalization. The id never changes, so the words
     * of every language stay attached to it.
     *
     * @param array{icon_key: string, icon_media_id?: int|null, is_active: bool} $values
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE feature_grid_items SET
                icon_key = :icon_key,
                icon_media_id = :icon_media_id,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'icon_key' => $values['icon_key'],
            'icon_media_id' => $values['icon_media_id'] ?? null,
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes a card — distinct from hiding one via is_active
     * (see updateItem). Used by the admin "Verwijderen" action, which removes
     * the card's words first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM feature_grid_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the cards of one Kaartenraster as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $gridId is left
     * alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $gridId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE feature_grid_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND feature_grid_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $gridId]);
        }
    }

    /**
     * Permanently removes the grid and (via ON DELETE CASCADE) all of its
     * cards — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of the grid and of every
     * card first. This type has no uploaded media of its own, so no
     * filesystem cleanup is needed.
     */
    public function deleteGrid(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM feature_grids WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function nextSortOrder(int $gridId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM feature_grid_items WHERE feature_grid_id = :feature_grid_id'
        );
        $stmt->execute(['feature_grid_id' => $gridId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
