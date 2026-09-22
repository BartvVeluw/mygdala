<?php

namespace App\Repository;

/**
 * All text_image_splits / text_image_split_paragraphs /
 * text_image_split_images SQL lives here. A "section" is one Text + image
 * split block on a page (e.g. over-mij.php's "intro" block); each section
 * has one or more "paragraphs" and one or more "images". Same shape/
 * conventions as FaqRepository (parent + child tables, sort_order swap for
 * reordering) — see App\Service\TextImageSplitContent for the defaults/
 * fallback layer built on top of this. This repository only stores the
 * resulting image_path for images; the actual upload/validation/delete is
 * App\Service\SectionImageUploader's job, called from the API layer.
 *
 * Only what is the same in every language is stored here. The eyebrow,
 * title and button label of a section, the text of each paragraph and each
 * image's own alt text are stored per website language through
 * App\Service\Blocks\BlockLocalization, against the id of the row they belong
 * to (db/migrations/20260917200000).
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
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. Used by the admin Text + image
     * split edit form's section-level fields, which saves the eyebrow, title
     * and button label through BlockLocalization in the same transaction.
     *
     * @param array{layout?: string|null, button_url?: string|null, is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO text_image_splits
                (page_slug, section_key, layout, button_url, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :layout, :button_url, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                layout = VALUES(layout),
                button_url = VALUES(button_url),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'layout' => in_array($values['layout'] ?? null, ['image_left', 'image_right'], true) ? $values['layout'] : 'image_right',
            'button_url' => self::nullIfEmpty($values['button_url'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    // -- Paragraphs -----------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findParagraphsBySectionId(int $sectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM text_image_split_paragraphs
             WHERE text_image_split_id = :text_image_split_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['text_image_split_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a new paragraph to the end of a section and returns its id. Its
     * text is words, stored per website language against that id
     * (App\Service\Blocks\BlockLocalization), in the same transaction as this
     * insert.
     */
    public function createParagraph(int $sectionId): int
    {
        $nextSortOrder = $this->nextSortOrder('text_image_split_paragraphs', 'text_image_split_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO text_image_split_paragraphs
                (text_image_split_id, sort_order, created_at, updated_at)
             VALUES
                (:text_image_split_id, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'text_image_split_id' => $sectionId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A paragraph has nothing that is the same in every language except its
     * place, which moveParagraph() changes, so a save only marks it updated;
     * its text is saved through BlockLocalization in the same transaction.
     * The id never changes, so the words of every language stay attached to
     * it.
     */
    public function updateParagraph(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_paragraphs SET
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Permanently removes a paragraph. Used by the admin "Verwijderen"
     * action, which removes the paragraph's words first, in the same
     * transaction (BlockLocalization::deleteOwner()).
     */
    public function deleteParagraph(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_split_paragraphs WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the paragraphs of one Tekst met afbeelding as the
     * one-form editor posted it
     * (App\Service\Blocks\EditorChildList::save()): the first id gets
     * sort_order 0. An id that is not a row of $sectionId is left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderParagraphs(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_paragraphs SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND text_image_split_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
    }

    // -- Images -----------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findImagesBySectionId(int $sectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM text_image_split_images
             WHERE text_image_split_id = :text_image_split_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['text_image_split_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a new image to the end of a section and returns its id.
     *
     * `media_id` names an item in the Media Library. `image_path` is written
     * alongside it with that item's own path, so the legacy column stays
     * TRUE rather than stale for as long as it exists — and the two always
     * move together, so an image with no media item has neither. This
     * repository never touches the filesystem itself. The image's own alt
     * text is words, stored per website language against the returned id
     * (BlockLocalization), in the same transaction as this insert.
     *
     * @param array{media_id?: int|null, image_path?: string} $values
     */
    public function createImage(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('text_image_split_images', 'text_image_split_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO text_image_split_images
                (text_image_split_id, media_id, image_path, sort_order, created_at, updated_at)
             VALUES
                (:text_image_split_id, :media_id, :image_path, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'text_image_split_id' => $sectionId,
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Which media item an image shows: the same in every language. Its alt
     * text is saved through BlockLocalization in the same transaction. The id
     * never changes, so the alt text of every language stays attached to it.
     *
     * @param array{media_id?: int|null, image_path?: string} $values
     */
    public function updateImage(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_images SET
                media_id = :media_id,
                image_path = :image_path,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'id' => $id,
        ]);
    }

    /** A media id, or NULL for "no media item" — never 0. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Removes one image row. Used by the admin "Verwijderen" action, which
     * removes the image's alt text first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deleteImage(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_split_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the images of one Tekst met afbeelding as the
     * one-form editor posted it
     * (App\Service\Blocks\EditorChildList::save()): the first id gets
     * sort_order 0. An id that is not a row of $sectionId is left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderImages(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_images SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND text_image_split_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * paragraphs/images — used by the page builder's "Delete section"
     * action, whose SectionRegistry::delete() removes the words of the
     * section, of every paragraph and of every image first. Unlike the other
     * repeater types, this one DOES have uploaded media
     * (text_image_split_images.image_path); the CASCADE only removes the
     * database rows, so the caller must delete each image's file via
     * SectionImageUploader::delete() (using findImagesBySectionId() to get
     * the paths) BEFORE calling this.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_splits WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Shared helpers -----------------------------------------------------

    private function nextSortOrder(string $table, string $fkColumn, int $sectionId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM {$table} WHERE {$fkColumn} = :section_id"
        );
        $stmt->execute(['section_id' => $sectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}
