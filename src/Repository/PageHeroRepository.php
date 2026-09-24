<?php

namespace App\Repository;

/**
 * All page_heroes SQL lives here. See App\Service\PageHeroContent for the
 * defaults/fallback layer built on top of this.
 */
class PageHeroRepository extends Repository
{
    /** The choices upsert() writes only when its caller names them. */
    private const LATER_CHOICES = ['image_mode', 'hero_height', 'image_focus'];

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
     * an editor chose. The three that came later — image_mode, hero_height
     * and image_focus (db/migrations/20260924120000) — are the exception the
     * other way round: a caller that does not name one leaves it as it is
     * stored, and a new row gets the column's default (no picture, medium,
     * centre). So nothing written before they existed can reset them. The
     * values arrive checked — `media_id` resolved against the Media Library
     * (BlockImage::fromRequest()) or null, each choice one of
     * PageHeroContent's closed lists and the focus an ImageFocus key — so
     * this only writes them.
     *
     * @param array{media_id: int|null, content_position: string, title_size: string, text_size: string, image_mode?: string, hero_height?: string, image_focus?: string, is_active: bool} $values
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
     * upsert() rather than resurrecting this row.
     */
    public function deleteBySlug(string $pageSlug): bool
    {
        $stmt = $this->db->prepare('DELETE FROM page_heroes WHERE page_slug = :page_slug');
        $stmt->execute(['page_slug' => $pageSlug]);

        return $stmt->rowCount() > 0;
    }
}
