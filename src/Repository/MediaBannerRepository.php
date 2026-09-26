<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the Mediabanner block (`media_banners`,
 * App\Service\Blocks\MediaBannerBlock): one row per instance, addressed by
 * (page_slug, section_key) like every block, holding the chosen library item,
 * the layout and the video options. It has no words and no child rows.
 */
final class MediaBannerRepository extends Repository
{
    /** The columns update() writes, and nothing else: never a name from a request. */
    private const SETTINGS = [
        'media_id',
        'width',
        'height',
        'image_focus',
        'video_autoplay',
        'video_loop',
        'video_controls',
        'poster_media_id',
    ];

    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM media_banners WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM media_banners WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates the instance's row: no media yet, and every other column at its
     * default (the table's own), shown. It renders nothing until a picture or
     * video is chosen.
     */
    public function createSection(string $pageSlug, string $sectionKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO media_banners (page_slug, section_key, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * Writes the settings of one instance. Every value has been checked by
     * the caller (App\Service\MediaBannerContent's closed lists, a library
     * item that exists and is of the right kind); keys that are not settings
     * are ignored.
     *
     * @param array<string, int|string|bool|null> $settings
     */
    public function update(int $id, array $settings): void
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

        $stmt = $this->db->prepare('UPDATE media_banners SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Permanently removes one instance — used by the page builder's "Delete
     * section" action via App\Service\SectionRegistry::delete(). The library
     * items it used stay in the library.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM media_banners WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
