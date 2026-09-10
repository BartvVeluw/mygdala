<?php

namespace App\Repository;

/**
 * All detail_sections / detail_section_points / detail_section_images SQL
 * lives here. A "detail section" is one instance of the reusable
 * "Detailsectie" block (App\Service\SectionRegistry's `detail_section`): a
 * full-width, optionally anchored content section with a title, a lead, rich
 * content, an optional main image (left or right), a list of "kenmerken"
 * (points), an optional image gallery, an optional closing note and an
 * optional CTA button.
 *
 * Same parent/child repeater shape and conventions as
 * TextImageSplitRepository (read that class's docblock first — this mirrors
 * it, with TWO child collections). Rows are addressed by
 * (page_slug, section_key) like every other repeatable block type; the
 * predecessor of this table (`services`) was keyed by a single closed-set
 * `service_key`, which is exactly the "maximum one per page, forever"
 * limitation phase 3 removed — see docs/content-blocks/DECISIONS.md.
 *
 * This repository only stores the resulting image_path for the gallery and
 * the main image; the actual upload/validation/delete is
 * App\Service\SectionImageUploader's job, called from the API layer.
 */
class DetailSectionRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM detail_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM detail_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every active detail section on one page, in the page's own block order
     * — the input the quicknav needs to build its links without knowing
     * anything about what those sections are about. Joins page_sections
     * because THAT is where a block's position lives; a content table never
     * stores its own position.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveForPageInBlockOrder(string $pageSlug): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*
               FROM detail_sections d
               JOIN page_sections ps
                 ON ps.section_type = :section_type
                AND ps.section_id = d.id
              WHERE d.page_slug = :page_slug
                AND d.is_active = 1
                AND ps.is_active = 1
              ORDER BY ps.sort_order ASC, ps.id ASC'
        );
        $stmt->execute(['section_type' => 'detail_section', 'page_slug' => $pageSlug]);

        return $stmt->fetchAll();
    }

    /**
     * Inserts or updates the row for one instance. Used by the admin
     * editor's "Algemene inhoud" form; the main image, points and gallery
     * images are saved separately by their own endpoints, so this method
     * never touches them.
     *
     * @param array<string, string|bool|null> $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO detail_sections
                (page_slug, section_key, anchor, nav_label_nl, nav_label_en,
                 title_nl, title_en, lead_nl, lead_en, content_html, content_html_en,
                 image_position, closing_note_nl, closing_note_en,
                 cta_label_nl, cta_label_en, cta_url, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :anchor, :nav_label_nl, :nav_label_en,
                 :title_nl, :title_en, :lead_nl, :lead_en, :content_html, :content_html_en,
                 :image_position, :closing_note_nl, :closing_note_en,
                 :cta_label_nl, :cta_label_en, :cta_url, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                anchor = VALUES(anchor),
                nav_label_nl = VALUES(nav_label_nl),
                nav_label_en = VALUES(nav_label_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                lead_nl = VALUES(lead_nl),
                lead_en = VALUES(lead_en),
                content_html = VALUES(content_html),
                content_html_en = VALUES(content_html_en),
                image_position = VALUES(image_position),
                closing_note_nl = VALUES(closing_note_nl),
                closing_note_en = VALUES(closing_note_en),
                cta_label_nl = VALUES(cta_label_nl),
                cta_label_en = VALUES(cta_label_en),
                cta_url = VALUES(cta_url),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'anchor' => self::nullIfEmpty($values['anchor'] ?? null),
            'nav_label_nl' => self::nullIfEmpty($values['nav_label_nl'] ?? null),
            'nav_label_en' => self::nullIfEmpty($values['nav_label_en'] ?? null),
            'title_nl' => $values['title_nl'] ?? '',
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'lead_nl' => self::nullIfEmpty($values['lead_nl'] ?? null),
            'lead_en' => self::nullIfEmpty($values['lead_en'] ?? null),
            'content_html' => self::nullIfEmpty($values['content_html'] ?? null),
            'content_html_en' => self::nullIfEmpty($values['content_html_en'] ?? null),
            'image_position' => in_array($values['image_position'] ?? null, ['image_left', 'image_right'], true)
                ? $values['image_position']
                : 'image_right',
            'closing_note_nl' => self::nullIfEmpty($values['closing_note_nl'] ?? null),
            'closing_note_en' => self::nullIfEmpty($values['closing_note_en'] ?? null),
            'cta_label_nl' => self::nullIfEmpty($values['cta_label_nl'] ?? null),
            'cta_label_en' => self::nullIfEmpty($values['cta_label_en'] ?? null),
            'cta_url' => self::nullIfEmpty($values['cta_url'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detail_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Main image --------------------------------------------------------

    /**
     * `main_media_id` names a Media Library item; `main_image_path` is
     * written with that item's own path so the legacy column stays true
     * rather than stale while it still exists. The two always move together.
     *
     * @param array<string, mixed> $values main_media_id, main_image_path, main_image_alt_nl, main_image_alt_en
     */
    public function updateMainImage(int $sectionId, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_sections SET
                main_media_id = :main_media_id,
                main_image_path = :main_image_path,
                main_image_alt_nl = :main_image_alt_nl,
                main_image_alt_en = :main_image_alt_en,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'main_media_id' => self::positiveOrNull($values['main_media_id'] ?? null),
            'main_image_path' => (string) ($values['main_image_path'] ?? ''),
            'main_image_alt_nl' => self::nullIfEmpty($values['main_image_alt_nl'] ?? null),
            'main_image_alt_en' => self::nullIfEmpty($values['main_image_alt_en'] ?? null),
            'id' => $sectionId,
        ]);
    }

    /**
     * Clears the main image back to NULL — the section then renders as a
     * plain text + kenmerken section, which is what every migrated material
     * section does today.
     */
    public function clearMainImage(int $sectionId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_sections SET main_media_id = NULL, main_image_path = NULL, main_image_alt_nl = NULL, main_image_alt_en = NULL, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $sectionId]);
    }

    // -- Points ("kenmerken") ---------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findPointsBySectionId(int $sectionId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM detail_section_points WHERE section_id = :section_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['section_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPointById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM detail_section_points WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string> $values title_nl, title_en, body_nl, body_en
     */
    public function createPoint(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('detail_section_points', 'section_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO detail_section_points (section_id, title_nl, title_en, body_nl, body_en, sort_order, is_active, created_at, updated_at)
             VALUES (:section_id, :title_nl, :title_en, :body_nl, :body_en, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'body_nl' => $values['body_nl'],
            'body_en' => self::nullIfEmpty($values['body_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string|bool> $values title_nl, title_en, body_nl, body_en, is_active
     */
    public function updatePoint(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_section_points SET
                title_nl = :title_nl,
                title_en = :title_en,
                body_nl = :body_nl,
                body_en = :body_en,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'body_nl' => $values['body_nl'],
            'body_en' => self::nullIfEmpty($values['body_en'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function deletePoint(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detail_section_points WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function movePoint(int $sectionId, int $pointId, string $direction): void
    {
        $this->moveWithinList(
            $this->findPointsBySectionId($sectionId),
            $pointId,
            $direction,
            'detail_section_points'
        );
    }

    // -- Gallery images ----------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findImagesBySectionId(int $sectionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM detail_section_images WHERE section_id = :section_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['section_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findImageById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM detail_section_images WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $values media_id, image_path, alt_nl, alt_en
     */
    public function createImage(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('detail_section_images', 'section_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO detail_section_images (section_id, media_id, image_path, alt_nl, alt_en, sort_order, created_at, updated_at)
             VALUES (:section_id, :media_id, :image_path, :alt_nl, :alt_en, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'section_id' => $sectionId,
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
            'UPDATE detail_section_images SET media_id = :media_id, image_path = :image_path, alt_nl = :alt_nl, alt_en = :alt_en, updated_at = NOW()
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

    public function deleteImage(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detail_section_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function moveImage(int $sectionId, int $imageId, string $direction): void
    {
        $this->moveWithinList(
            $this->findImagesBySectionId($sectionId),
            $imageId,
            $direction,
            'detail_section_images'
        );
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

    /** A media id, or NULL for "no media item" — never 0. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
