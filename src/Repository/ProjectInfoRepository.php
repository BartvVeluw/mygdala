<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `portfolio_project_infos` SQL: one row per Projectinformatie block
 * (App\Service\Blocks\ProjectInfoBlock), addressed by (page_slug, section_key)
 * like every block. The row holds how the project's head is shown — where
 * the picture sits and whether the extra photos follow — and never a word or
 * a picture of the project itself: those are read live from the project
 * (db/migrations/20260930100000).
 */
final class ProjectInfoRepository extends Repository
{
    /** @return array<string, mixed>|null */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM portfolio_project_infos WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portfolio_project_infos WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function createSection(string $pageSlug, string $sectionKey, string $imagePosition, bool $showGallery): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_project_infos (page_slug, section_key, is_active, image_position, show_gallery, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, :image_position, :show_gallery, NOW(), NOW())'
        );
        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'image_position' => $imagePosition,
            'show_gallery' => $showGallery ? 1 : 0,
        ]);
    }

    public function updateSettings(int $id, string $imagePosition, bool $showGallery): void
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_project_infos SET image_position = :image_position, show_gallery = :show_gallery, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['image_position' => $imagePosition, 'show_gallery' => $showGallery ? 1 : 0, 'id' => $id]);
    }

    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM portfolio_project_infos WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
