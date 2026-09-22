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
 * ONLY WHAT IS THE SAME IN EVERY LANGUAGE is written here: the anchor, the
 * image position, the CTA URL, the media references, the order and
 * visibility. The words of a section, of each point and of each gallery
 * image's alt text are stored per website language through
 * App\Service\Blocks\BlockLocalization, against the id of their own row
 * (db/migrations/20260917200000), in the same transaction as the write here
 * that belongs to them.
 *
 * This repository only stores the resulting image_path for the gallery and
 * the main image; choosing a Media Library item is
 * App\Service\Media\BlockImage::fromRequest()'s job, called from the API layer.
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
     * Only the id and the anchor: a section's label is words, read per
     * language through BlockLocalization by that id.
     *
     * @return array<int, array{id: int|string, anchor: string|null}>
     */
    public function findActiveForPageInBlockOrder(string $pageSlug): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.anchor
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
     * Inserts or updates the row for one instance: what is the same in every
     * language. Used by the admin editor's "Algemene inhoud" form; the main
     * image, points and gallery images are saved separately by their own
     * endpoints, so this method never touches them.
     *
     * @param array{anchor?: string|null, image_position?: string|null, cta_url?: string|null, is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO detail_sections
                (page_slug, section_key, anchor, image_position, cta_url, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :anchor, :image_position, :cta_url, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                anchor = VALUES(anchor),
                image_position = VALUES(image_position),
                cta_url = VALUES(cta_url),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'anchor' => self::nullIfEmpty($values['anchor'] ?? null),
            'image_position' => in_array($values['image_position'] ?? null, ['image_left', 'image_right'], true)
                ? $values['image_position']
                : 'image_right',
            'cta_url' => self::nullIfEmpty($values['cta_url'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * Permanently removes the section and (via ON DELETE CASCADE) its points
     * and gallery images — used by the page builder's "Delete section"
     * action, whose SectionRegistry::delete() removes the words of the
     * section and of every child row first.
     */
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
     * The alt text is words, saved per language through BlockLocalization.
     *
     * @param array{main_media_id?: int|null, main_image_path?: string} $values
     */
    public function updateMainImage(int $sectionId, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_sections SET
                main_media_id = :main_media_id,
                main_image_path = :main_image_path,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'main_media_id' => self::positiveOrNull($values['main_media_id'] ?? null),
            'main_image_path' => (string) ($values['main_image_path'] ?? ''),
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
            'UPDATE detail_sections SET main_media_id = NULL, main_image_path = NULL, updated_at = NOW()
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
     * Appends a new, visible point to the end of a section and returns its
     * id. Its title and body are words, stored per website language against
     * that id (App\Service\Blocks\BlockLocalization), in the same transaction
     * as this insert.
     */
    public function createPoint(int $sectionId): int
    {
        $nextSortOrder = $this->nextSortOrder('detail_section_points', 'section_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO detail_section_points (section_id, sort_order, is_active, created_at, updated_at)
             VALUES (:section_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a point has that is the same in every language: whether it is
     * shown. Its words are saved through BlockLocalization. The id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array{is_active: bool} $values
     */
    public function updatePoint(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_section_points SET
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
     * Permanently removes a point. The "Verwijderen" action removes the
     * point's words first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deletePoint(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detail_section_points WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the points of one Detailsectie as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $sectionId is
     * left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderPoints(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_section_points SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND section_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
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
     * Appends an image to the end of a section's gallery and returns its id.
     * Its alt text is words, stored per website language against that id
     * (App\Service\Blocks\BlockLocalization), in the same transaction as this
     * insert.
     *
     * @param array{media_id?: int|null, image_path?: string} $values
     */
    public function createImage(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('detail_section_images', 'section_id', $sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO detail_section_images (section_id, media_id, image_path, sort_order, created_at, updated_at)
             VALUES (:section_id, :media_id, :image_path, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'section_id' => $sectionId,
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Which Media Library item one gallery image shows. Its alt text is saved
     * through BlockLocalization; the id never changes, so the alt text of
     * every language stays attached to it.
     *
     * @param array{media_id?: int|null, image_path?: string} $values
     */
    public function updateImage(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_section_images SET media_id = :media_id, image_path = :image_path, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes a gallery image's row (the reference, never the
     * Media Library file). The "Verwijderen" action removes the image's alt
     * text first, in the same transaction (BlockLocalization::deleteOwner()).
     */
    public function deleteImage(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM detail_section_images WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of the images of one Detailsectie as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $sectionId is
     * left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderImages(int $sectionId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE detail_section_images SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND section_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $sectionId]);
        }
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

    /** A media id, or NULL for "no media item" — never 0. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
