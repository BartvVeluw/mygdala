<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the Hover kaarten grid block (App\Service\Blocks\HoverCardGridBlock):
 * `hover_card_grids`, one row per instance addressed by (page_slug,
 * section_key) like every block, and its cards in `hover_card_grid_items`.
 * The same parent-plus-children shape as TextImageSplitRepository, with the
 * order as the one-form editor posts it.
 *
 * Only what is the same in every language is stored here: the grid's choices,
 * and per card its two pictures and its link. Every word — the grid's
 * heading, a card's badge, title, text and link label — is stored per website
 * language through App\Service\Blocks\BlockLocalization against the row's id,
 * in the same transaction as these writes.
 */
final class HoverCardGridRepository extends Repository
{
    /** The columns updateSettings() writes, and nothing else: never a name from a request. */
    private const SETTINGS = ['layout', 'shape', 'columns', 'overlay', 'effect', 'header_align', 'is_active'];

    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM hover_card_grids WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM hover_card_grids WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates the instance's row: no cards, every choice at the table's own
     * default, shown. It renders nothing until a card has a picture.
     */
    public function createSection(string $pageSlug, string $sectionKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO hover_card_grids (page_slug, section_key, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * Writes the choices of one instance. Every value has been checked by the
     * caller (App\Service\HoverCardGridContent's closed lists); keys that are
     * not settings are ignored.
     *
     * @param array<string, string|bool> $settings
     */
    public function updateSettings(int $id, array $settings): void
    {
        $sets = [];
        $params = ['id' => $id];

        foreach (self::SETTINGS as $column) {
            if (!array_key_exists($column, $settings)) {
                continue;
            }

            $value = $settings[$column];
            $sets[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? (int) $value : $value;
        }

        if ($sets === []) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE hover_card_grids SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Permanently removes one instance and, through ON DELETE CASCADE, its
     * cards — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of the grid and of every
     * card first. The pictures are Media Library items and stay there.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM hover_card_grids WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Cards -----------------------------------------------------------

    /**
     * @return list<array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsByGridId(int $gridId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM hover_card_grid_items
             WHERE hover_card_grid_id = :grid_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['grid_id' => $gridId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a card to the end of a grid and returns its id.
     *
     * @param array<string, mixed> $values media_id, hover_media_id, link_type, link_target_id, link_url
     */
    public function createItem(int $gridId, array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO hover_card_grid_items
                (hover_card_grid_id, media_id, hover_media_id, link_type, link_target_id, link_url, sort_order, created_at, updated_at)
             VALUES
                (:grid_id, :media_id, :hover_media_id, :link_type, :link_target_id, :link_url, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'grid_id' => $gridId,
            'sort_order' => $this->nextSortOrder($gridId),
        ] + self::itemValues($values));

        return (int) $this->db->lastInsertId();
    }

    /**
     * What is the same in every language of one card. Its id never changes,
     * so the words of every language stay attached to it.
     *
     * @param array<string, mixed> $values as createItem()
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE hover_card_grid_items SET
                media_id = :media_id,
                hover_media_id = :hover_media_id,
                link_type = :link_type,
                link_target_id = :link_target_id,
                link_url = :link_url,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id] + self::itemValues($values));
    }

    /**
     * Permanently removes one card. Its words go first, in the same
     * transaction (BlockLocalization::deleteOwner()). Never the library's file.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM hover_card_grid_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of a grid's cards as the one-form editor posted it
     * (App\Service\Blocks\EditorChildList::save()): the first id gets
     * sort_order 0. An id that is not a card of $gridId is left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $gridId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE hover_card_grid_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND hover_card_grid_id = :grid_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'grid_id' => $gridId]);
        }
    }

    private function nextSortOrder(int $gridId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM hover_card_grid_items WHERE hover_card_grid_id = :grid_id'
        );
        $stmt->execute(['grid_id' => $gridId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function itemValues(array $values): array
    {
        $hoverMediaId = (int) ($values['hover_media_id'] ?? 0);
        $linkType = isset($values['link_type']) ? (string) $values['link_type'] : '';
        $linkUrl = trim((string) ($values['link_url'] ?? ''));

        $mediaId = (int) ($values['media_id'] ?? 0);

        return [
            'media_id' => $mediaId > 0 ? $mediaId : null,
            'hover_media_id' => $hoverMediaId > 0 ? $hoverMediaId : null,
            'link_type' => $linkType !== '' ? $linkType : null,
            'link_target_id' => (int) ($values['link_target_id'] ?? 0) > 0 ? (int) $values['link_target_id'] : null,
            'link_url' => $linkUrl !== '' ? $linkUrl : null,
        ];
    }
}
