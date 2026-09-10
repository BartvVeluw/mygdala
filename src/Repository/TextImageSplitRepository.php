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
     * Used by the admin Text + image split edit form's section-level fields.
     *
     * @param array<string, string|bool|null> $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO text_image_splits
                (page_slug, section_key, layout, eyebrow_nl, eyebrow_en, title_nl, title_en,
                 button_label_nl, button_label_en, button_url, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :layout, :eyebrow_nl, :eyebrow_en, :title_nl, :title_en,
                 :button_label_nl, :button_label_en, :button_url, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                layout = VALUES(layout),
                eyebrow_nl = VALUES(eyebrow_nl),
                eyebrow_en = VALUES(eyebrow_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                button_label_nl = VALUES(button_label_nl),
                button_label_en = VALUES(button_label_en),
                button_url = VALUES(button_url),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'layout' => in_array($values['layout'] ?? null, ['image_left', 'image_right'], true) ? $values['layout'] : 'image_right',
            'eyebrow_nl' => self::nullIfEmpty($values['eyebrow_nl'] ?? null),
            'eyebrow_en' => self::nullIfEmpty($values['eyebrow_en'] ?? null),
            'title_nl' => self::nullIfEmpty($values['title_nl'] ?? null),
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'button_label_nl' => self::nullIfEmpty($values['button_label_nl'] ?? null),
            'button_label_en' => self::nullIfEmpty($values['button_label_en'] ?? null),
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
     * @return array<string, mixed>|null
     */
    public function findParagraphById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM text_image_split_paragraphs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new paragraph to the end of a section.
     *
     * @param array<string, string> $values content_nl, content_en
     */
    public function createParagraph(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('text_image_split_paragraphs', 'text_image_split_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO text_image_split_paragraphs
                (text_image_split_id, content_nl, content_en, sort_order, created_at, updated_at)
             VALUES
                (:text_image_split_id, :content_nl, :content_en, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'text_image_split_id' => $sectionId,
            'content_nl' => $values['content_nl'],
            'content_en' => self::nullIfEmpty($values['content_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string> $values content_nl, content_en
     */
    public function updateParagraph(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_paragraphs SET
                content_nl = :content_nl,
                content_en = :content_en,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'content_nl' => $values['content_nl'],
            'content_en' => self::nullIfEmpty($values['content_en'] ?? null),
            'id' => $id,
        ]);
    }

    public function deleteParagraph(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_split_paragraphs WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next paragraph (in current display
     * order) within the same section — same approach as
     * FaqRepository::moveItem().
     */
    public function moveParagraph(int $sectionId, int $paragraphId, string $direction): void
    {
        $this->moveWithinList(
            $this->findParagraphsBySectionId($sectionId),
            $paragraphId,
            $direction,
            'text_image_split_paragraphs'
        );
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
     * @return array<string, mixed>|null
     */
    public function findImageById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM text_image_split_images WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new image to the end of a section.
     *
     * `media_id` names an item in the Media Library. `image_path` is written
     * alongside it with that item's own path, so the legacy column stays
     * TRUE rather than stale for as long as it exists — and the two always
     * move together, so an image with no media item has neither. This
     * repository never touches the filesystem itself.
     *
     * @param array<string, mixed> $values media_id, image_path, alt_nl, alt_en
     */
    public function createImage(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('text_image_split_images', 'text_image_split_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO text_image_split_images
                (text_image_split_id, media_id, image_path, alt_nl, alt_en, sort_order, created_at, updated_at)
             VALUES
                (:text_image_split_id, :media_id, :image_path, :alt_nl, :alt_en, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'text_image_split_id' => $sectionId,
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'alt_nl' => self::nullIfEmpty($values['alt_nl'] ?? null),
            'alt_en' => self::nullIfEmpty($values['alt_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values media_id, image_path, alt_nl, alt_en
     */
    public function updateImage(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE text_image_split_images SET
                media_id = :media_id,
                image_path = :image_path,
                alt_nl = :alt_nl,
                alt_en = :alt_en,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'alt_nl' => self::nullIfEmpty($values['alt_nl'] ?? null),
            'alt_en' => self::nullIfEmpty($values['alt_en'] ?? null),
            'id' => $id,
        ]);
    }

    /** A media id, or NULL for "no media item" — never 0. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    public function deleteImage(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_split_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next image (in current display
     * order) within the same section.
     */
    public function moveImage(int $sectionId, int $imageId, string $direction): void
    {
        $this->moveWithinList(
            $this->findImagesBySectionId($sectionId),
            $imageId,
            $direction,
            'text_image_split_images'
        );
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * paragraphs/images — used by the page builder's "Delete section"
     * action. Unlike the other repeater types, this one DOES have uploaded
     * media (text_image_split_images.image_path); the CASCADE only removes
     * the database rows, so the caller must delete each image's file via
     * SectionImageUploader::delete() (using findImagesBySectionId() to get
     * the paths) BEFORE calling this — same convention as
     * api/admin/delete-text-image-split-image.php.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM text_image_splits WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Shared helpers -----------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $items already ordered by sort_order ASC, id ASC
     */
    private function moveWithinList(array $items, int $itemId, string $direction, string $table): void
    {
        $index = null;
        foreach ($items as $i => $item) {
            if ((int) $item['id'] === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($items)) {
            return;
        }

        $a = $items[$index];
        $b = $items[$swapWith];

        $this->updateSortOrder($table, (int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder($table, (int) $b['id'], (int) $a['sort_order']);
    }

    private function updateSortOrder(string $table, int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

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
