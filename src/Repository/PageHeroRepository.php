<?php

namespace App\Repository;

/**
 * All page_heroes SQL lives here. See App\Service\PageHeroContent for the
 * defaults/fallback layer built on top of this.
 */
class PageHeroRepository extends Repository
{
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
     * Inserts or updates the single row for this page slug. Used by the
     * admin Page Hero edit form, which always submits every field together,
     * and by PageHeroBlock::create() with PageHeroContent::startingValues().
     *
     * Every key is required, the image and the three presentation choices
     * included: a caller that left one out would silently reset what an
     * editor chose. The values arrive checked — `media_id` resolved against
     * the Media Library (BlockImage::fromRequest()) or null, and each choice
     * one of PageHeroContent's closed lists — so this only writes them.
     *
     * @param array<string, string|bool|int|null> $values
     */
    public function upsert(string $pageSlug, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO page_heroes
                (page_slug, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                 breadcrumb_label_nl, breadcrumb_label_en, media_id, content_position, title_size, text_size,
                 is_active, created_at, updated_at)
             VALUES
                (:page_slug, :eyebrow_nl, :eyebrow_en, :title_nl, :title_en, :lead_nl, :lead_en,
                 :breadcrumb_label_nl, :breadcrumb_label_en, :media_id, :content_position, :title_size, :text_size,
                 :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                eyebrow_nl = VALUES(eyebrow_nl),
                eyebrow_en = VALUES(eyebrow_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                lead_nl = VALUES(lead_nl),
                lead_en = VALUES(lead_en),
                breadcrumb_label_nl = VALUES(breadcrumb_label_nl),
                breadcrumb_label_en = VALUES(breadcrumb_label_en),
                media_id = VALUES(media_id),
                content_position = VALUES(content_position),
                title_size = VALUES(title_size),
                text_size = VALUES(text_size),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'eyebrow_nl' => $values['eyebrow_nl'],
            'eyebrow_en' => $values['eyebrow_en'] !== '' ? $values['eyebrow_en'] : null,
            'title_nl' => $values['title_nl'],
            'title_en' => $values['title_en'] !== '' ? $values['title_en'] : null,
            'lead_nl' => $values['lead_nl'] !== '' ? $values['lead_nl'] : null,
            'lead_en' => $values['lead_en'] !== '' ? $values['lead_en'] : null,
            'breadcrumb_label_nl' => $values['breadcrumb_label_nl'],
            'breadcrumb_label_en' => $values['breadcrumb_label_en'] !== '' ? $values['breadcrumb_label_en'] : null,
            'media_id' => $values['media_id'] !== null ? (int) $values['media_id'] : null,
            'content_position' => $values['content_position'],
            'title_size' => $values['title_size'],
            'text_size' => $values['text_size'],
            'is_active' => $values['is_active'] ? 1 : 0,
        ]);
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
