<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the Witruimte block (`spacers`, App\Service\Blocks\SpacerBlock):
 * one row per instance, addressed by (page_slug, section_key) like every
 * block, holding only its height and whether it shows. It has no words and
 * no child rows.
 */
final class SpacerRepository extends Repository
{
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM spacers WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM spacers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates the instance's row with $size (App\Service\SpacerContent::SIZES,
     * checked by the caller), shown.
     */
    public function createSection(string $pageSlug, string $sectionKey, string $size): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO spacers (page_slug, section_key, size, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, :size, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey, 'size' => $size]);
    }

    /** The height of one instance (a key of SpacerContent::SIZES, checked by the caller). */
    public function updateSize(int $id, string $size): void
    {
        $stmt = $this->db->prepare('UPDATE spacers SET size = :size, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['size' => $size, 'id' => $id]);
    }

    /**
     * Permanently removes one instance — used by the page builder's "Delete
     * section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM spacers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
