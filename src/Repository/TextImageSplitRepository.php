<?php

namespace App\Repository;

/**
 * All text_image_splits / text_image_split_items SQL lives here. A "section"
 * is one Tekst met afbeelding block on a page; it holds an ordered list of
 * "items", each a text beside at most one picture with its own layout
 * (Tekst met afbeelding 2.0, db/migrations/20260924100000). Same shape and
 * conventions as FaqRepository (parent + child table, the order as the
 * one-form editor posts it) — see App\Service\TextImageSplitContent for the
 * read model built on top of this.
 *
 * Only what is the same in every language is stored here. An item's eyebrow,
 * title, rich body, button label and alt text are stored per website
 * language through App\Service\Blocks\BlockLocalization, against the item's
 * id. The legacy child tables text_image_split_paragraphs and
 * text_image_split_images are emptied by that migration and read by nothing.
 */
class TextImageSplitRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM text_image_splits WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM text_image_splits WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     * Since Tekst met afbeelding 2.0 the block row holds nothing but whether
     * the block shows: every word, picture, layout and button belongs to an
     * item. The legacy `layout` and `button_url` columns are left as they
     * are, and read by nothing.
     *
     * @param array{is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO text_image_splits
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

    // -- Items -----------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsBySectionId(int $sectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM text_image_split_items
             WHERE text_image_split_id = :text_image_split_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['text_image_split_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a new item to the end of a section and returns its id. Its
     * words are stored per website language against that id
     * (App\Service\Blocks\BlockLocalization), in the same transaction as this
     * insert.
     *
     * `media_id` names an item in the Media Library; `image_path` is written
     * alongside it with that item's own path, as on every block picture
     * (App\Service\Media\BlockImage::fromRequest()). The layout values are
     * keys the caller has already checked (App\Service\TextImageSplitContent::layout()).
     *
     * The button's destination is App\Service\Routing\LinkChoice's shape:
     * button_link_type (NULL, 'url' or a LinkTargets type, checked by the
     * caller), button_link_target_id for an internal type, and button_url, the
     * typed address, kept whatever the type.
     *
     * @param array<string, mixed> $values media_id, image_path, image_side, image_column, image_height, image_focus,
     *                                     button_link_type, button_link_target_id, button_url
     */
    public function createItem(int $sectionId, array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO text_image_split_items
                (text_image_split_id, media_id, image_path, image_side, image_column, image_height, image_focus,
                 button_link_type, button_link_target_id, button_url, sort_order, created_at, updated_at)
             VALUES
                (:text_image_split_id, :media_id, :image_path, :image_side, :image_column, :image_height, :image_focus,
                 :button_link_type, :button_link_target_id, :button_url, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'text_image_split_id' => $sectionId,
            'sort_order' => $this->nextSortOrder($sectionId),
        ] + self::itemValues($values));

        return (int) $this->db->lastInsertId();
    }

    /**
     * What is the same in every language of one item: its picture, its
     * layout and its button's destination. Its words are saved through
     * BlockLocalization in the same transaction; the id never changes, so the
     * words of every language stay attached to it.
     *
     * @param array<string, mixed> $values as createItem()
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_items SET
                media_id = :media_id,
                image_path = :image_path,
                image_side = :image_side,
                image_column = :image_column,
                image_height = :image_height,
                image_focus = :image_focus,
                button_link_type = :button_link_type,
                button_link_target_id = :button_link_target_id,
                button_url = :button_url,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id] + self::itemValues($values));
    }

    /**
     * Permanently removes one item. Used by the editor's removal mark, which
     * removes the item's words first, in the same transaction
     * (BlockLocalization::deleteOwner()). Never the library's file.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_split_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the items of one Tekst met afbeelding as the
     * one-form editor posted it
     * (App\Service\Blocks\EditorChildList::save()): the first id gets
     * sort_order 0. An id that is not a row of $sectionId is left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND text_image_split_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * items — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of every item first. The
     * pictures are Media Library items and stay in the library
     * (TextImageSplitBlock::deleteFiles()).
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_splits WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Shared helpers -----------------------------------------------------

    private function nextSortOrder(int $sectionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM text_image_split_items WHERE text_image_split_id = :section_id'
        );
        $stmt->execute(['section_id' => $sectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function itemValues(array $values): array
    {
        $mediaId = (int) ($values['media_id'] ?? 0);

        return [
            'media_id' => $mediaId > 0 ? $mediaId : null,
            'image_path' => self::nullIfEmpty((string) ($values['image_path'] ?? '')),
            'image_side' => (string) $values['image_side'],
            'image_column' => (string) $values['image_column'],
            'image_height' => (string) $values['image_height'],
            'image_focus' => (string) $values['image_focus'],
            'button_link_type' => self::nullIfEmpty(isset($values['button_link_type']) ? (string) $values['button_link_type'] : null),
            'button_link_target_id' => (int) ($values['button_link_target_id'] ?? 0) > 0 ? (int) $values['button_link_target_id'] : null,
            'button_url' => self::nullIfEmpty(trim((string) ($values['button_url'] ?? ''))),
        ];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}
