<?php

namespace App\Repository;

/**
 * All cta_bands SQL lives here. Keyed by (page_slug, section_key) like every
 * other repeatable block type — see
 * db/migrations/20260908270000_make_cta_bands_repeatable_per_instance.php
 * and App\Service\CtaBandContent for the layer built on top of this.
 */
class CtaBandRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM cta_bands WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * This page's first CTA band, whichever instance that is — what a
     * non-CMS template that borrows a page's CTA band needs
     * (portfolio-detail.php), so it never has to name a section_key.
     *
     * @return array<string, mixed>|null
     */
    public function findFirstBySlug(string $pageSlug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cta_bands WHERE page_slug = :page_slug ORDER BY id ASC LIMIT 1');
        $stmt->execute(['page_slug' => $pageSlug]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM cta_bands WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * What a row stores when a caller leaves a setting out: no destination,
     * and the presentation every band had before CTA 2.0 (the column
     * defaults of db/migrations/20260926120000).
     */
    private const DEFAULTS = [
        'primary_link_type' => null,
        'primary_link_target_id' => null,
        'secondary_link_type' => null,
        'secondary_link_target_id' => null,
        'content_align' => 'center',
        'lead_width' => 'narrow',
        'full_width' => false,
        'background_media_id' => null,
        'background_focus' => 'center',
        'background_overlay' => 'medium',
        'text_panel' => false,
        'text_panel_opacity' => 'strong',
    ];

    /**
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. The band's words are stored per
     * website language through App\Service\Blocks\BlockLocalization
     * (db/migrations/20260917170000). The caller has already checked every
     * value (api/admin/update-cta-band.php); this only writes.
     *
     * @param array{primary_url: string, secondary_url: string, is_active: bool}&array<string, mixed> $values
     *        plus any key of DEFAULTS
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $values += self::DEFAULTS;

        $stmt = $this->db->prepare(
            'INSERT INTO cta_bands
                (page_slug, section_key, primary_url, primary_link_type, primary_link_target_id,
                 secondary_url, secondary_link_type, secondary_link_target_id,
                 content_align, lead_width, full_width, background_media_id, background_focus,
                 background_overlay, text_panel, text_panel_opacity, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :primary_url, :primary_link_type, :primary_link_target_id,
                 :secondary_url, :secondary_link_type, :secondary_link_target_id,
                 :content_align, :lead_width, :full_width, :background_media_id, :background_focus,
                 :background_overlay, :text_panel, :text_panel_opacity, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                primary_url = VALUES(primary_url),
                primary_link_type = VALUES(primary_link_type),
                primary_link_target_id = VALUES(primary_link_target_id),
                secondary_url = VALUES(secondary_url),
                secondary_link_type = VALUES(secondary_link_type),
                secondary_link_target_id = VALUES(secondary_link_target_id),
                content_align = VALUES(content_align),
                lead_width = VALUES(lead_width),
                full_width = VALUES(full_width),
                background_media_id = VALUES(background_media_id),
                background_focus = VALUES(background_focus),
                background_overlay = VALUES(background_overlay),
                text_panel = VALUES(text_panel),
                text_panel_opacity = VALUES(text_panel_opacity),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'primary_url' => $values['primary_url'],
            'primary_link_type' => $values['primary_link_type'],
            'primary_link_target_id' => $values['primary_link_target_id'],
            'secondary_url' => $values['secondary_url'] !== '' ? $values['secondary_url'] : null,
            'secondary_link_type' => $values['secondary_link_type'],
            'secondary_link_target_id' => $values['secondary_link_target_id'],
            'content_align' => $values['content_align'],
            'lead_width' => $values['lead_width'],
            'full_width' => $values['full_width'] ? 1 : 0,
            'background_media_id' => $values['background_media_id'],
            'background_focus' => $values['background_focus'],
            'background_overlay' => $values['background_overlay'],
            'text_panel' => $values['text_panel'] ? 1 : 0,
            'text_panel_opacity' => $values['text_panel_opacity'],
            'is_active' => $values['is_active'] ? 1 : 0,
        ]);
    }

    /**
     * Permanently removes ONE CTA band instance — used by the page builder's
     * "Delete section" action (distinct from is_active, which only hides
     * it). Deleting one instance must never touch another instance on the
     * same page, which is why this deletes by id rather than by page_slug.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM cta_bands WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
