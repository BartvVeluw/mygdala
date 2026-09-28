<?php

namespace App\Repository;

/**
 * All page_heroes SQL lives here. See App\Service\PageHeroContent for the
 * defaults/fallback layer built on top of this.
 */
class PageHeroRepository extends Repository
{
    /** The choices upsert() writes only when its caller names them. */
    private const LATER_CHOICES = ['image_mode', 'hero_height', 'slide_transition', 'slide_duration'];

    /**
     * @return array<string, mixed>|null null when no row exists for this slug
     */
    public function findBySlug(string $pageSlug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM page_heroes WHERE page_slug = :page_slug LIMIT 1');
        $stmt->execute(['page_slug' => $pageSlug]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page slug: what is the same
     * in every language. The header's words (eyebrow, title, lead) are stored
     * per website language through App\Service\Blocks\BlockLocalization
     * (Multilingual 2.0 phase 3B), so this writes none. Used by the admin Page
     * Hero edit form and by PageHeroBlock::create() with
     * PageHeroContent::startingValues().
     *
     * Every key is required, the image and the first three presentation
     * choices included: a caller that left one out would silently reset what
     * an editor chose. The ones that came later — image_mode and hero_height
     * (db/migrations/20260924120000), slide_transition and slide_duration
     * (db/migrations/20260928180000) — are the exception the other way round:
     * a caller that does not name one leaves it as it is stored, and a new row
     * gets the column's default (no picture, medium, fade, five seconds). So
     * nothing written before they existed can reset them. The values arrive
     * checked — `media_id` resolved against the Media Library
     * (BlockImage::fromRequest()) or null, each choice one of PageHeroContent's
     * or MediaSequence's closed lists — so this only writes them. How the
     * picture sits in its place (Responsive Media 2.0) is
     * App\Repository\ResponsiveImageRepository's.
     *
     * @param array{media_id: int|null, content_position: string, title_size: string, text_size: string, image_mode?: string, hero_height?: string, slide_transition?: string, slide_duration?: int, is_active: bool} $values
     */
    public function upsert(string $pageSlug, array $values): void
    {
        $parameters = [
            'page_slug' => $pageSlug,
            'media_id' => $values['media_id'] !== null ? (int) $values['media_id'] : null,
            'content_position' => $values['content_position'],
            'title_size' => $values['title_size'],
            'text_size' => $values['text_size'],
            'is_active' => $values['is_active'] ? 1 : 0,
        ];

        // A closed list of column names, never a key taken from $values.
        foreach (self::LATER_CHOICES as $column) {
            if (array_key_exists($column, $values)) {
                $parameters[$column] = (string) $values[$column];
            }
        }

        $columns = array_keys($parameters);
        $updates = array_map(
            static fn (string $column): string => $column . ' = VALUES(' . $column . ')',
            array_values(array_diff($columns, ['page_slug']))
        );

        $stmt = $this->db->prepare(
            'INSERT INTO page_heroes (' . implode(', ', $columns) . ', created_at, updated_at)
             VALUES (:' . implode(', :', $columns) . ', NOW(), NOW())
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', updated_at = NOW()'
        );

        $stmt->execute($parameters);
    }

    /**
     * Permanently removes the row for this page slug — used by the page
     * builder's "Delete section" action (distinct from is_active, which only
     * hides it). A later "Add section" for the same page starts fresh via
     * upsert() rather than resurrecting this row. Its further pictures go
     * with it (page_hero_images, ON DELETE CASCADE).
     */
    public function deleteBySlug(string $pageSlug): bool
    {
        $stmt = $this->db->prepare('DELETE FROM page_heroes WHERE page_slug = :page_slug');
        $stmt->execute(['page_slug' => $pageSlug]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The header's FURTHER pictures, after its own (`media_id`), in their
     * order: a media sequence (App\Service\Media\MediaSequence). Library ids
     * only; whether they still name a picture is the reader's check.
     *
     * @return list<int>
     */
    public function findImageIds(int $heroId): array
    {
        $stmt = $this->db->prepare(
            'SELECT media_id FROM page_hero_images WHERE page_hero_id = :hero_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['hero_id' => $heroId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Stores the header's further pictures as exactly this list, in this
     * order. A row here is nothing but a library id and a place — no words,
     * no settings — so the list is simply written again. The ids arrive
     * checked (pictures of the library, the header's own picture not among
     * them); runs inside the caller's transaction.
     *
     * @param list<int> $mediaIds
     */
    public function replaceImages(int $heroId, array $mediaIds): void
    {
        $this->db->prepare('DELETE FROM page_hero_images WHERE page_hero_id = :hero_id')->execute(['hero_id' => $heroId]);

        $insert = $this->db->prepare(
            'INSERT INTO page_hero_images (page_hero_id, media_id, sort_order, created_at) VALUES (:hero_id, :media_id, :sort_order, NOW())'
        );
        foreach (array_values($mediaIds) as $position => $mediaId) {
            $insert->execute(['hero_id' => $heroId, 'media_id' => (int) $mediaId, 'sort_order' => $position]);
        }
    }
}
