<?php

namespace App\Repository;

/**
 * All item_galleries SQL — the "Portfolio-/collectiegalerij" block
 * (db/migrations/20260908290000_turn_the_portfolio_blocks_into_one_reusable_block.php,
 * App\Service\ItemGalleryContent).
 *
 * Same shape and method names as the other repeatable block types, so
 * App\Service\SectionRegistry dispatches to it exactly like the rest. This
 * table holds only the block's own settings and copy: the ITEMS it shows
 * live in their own catalogues (portfolio items, collection products) and
 * are read through their own repositories, never joined in here — a block
 * points at a source, it does not own one.
 *
 * Two block types keep their rows here: the gallery itself, and the
 * Portfolio's Projecten (App\Service\Blocks\ProjectCardsBlock), which is the
 * same row with its source fixed. page_sections.section_type says which block
 * placed a row, and only that block's editor changes it.
 *
 * No child rows and no uploaded media of its own, so deleting an instance is
 * a single DELETE.
 */
class ItemGalleryRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM item_galleries WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM item_galleries WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     * The admin form always submits every field together.
     *
     * `source_type` and `portfolio_scope` are stored as given; the caller
     * (api/admin/update-item-gallery.php, App\Service\SectionRegistry) is
     * the one that validates them against
     * App\Service\ItemGalleryContent::SOURCES first, and
     * App\Service\ItemGalleryContent validates again on read — an unknown
     * value can therefore never reach a query or a class name.
     *
     * @param array<string, string|bool|int|null> $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO item_galleries
                (page_slug, section_key, source_type, portfolio_scope, collection_id, max_items,
                 show_filter_bar, enable_lightbox, fallback_link_url,
                 eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                 footer_note_nl, footer_note_en,
                 button_label_nl, button_label_en, button_url,
                 background, tight_top, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :source_type, :portfolio_scope, :collection_id, :max_items,
                 :show_filter_bar, :enable_lightbox, :fallback_link_url,
                 :eyebrow_nl, :eyebrow_en, :title_nl, :title_en, :lead_nl, :lead_en,
                 :footer_note_nl, :footer_note_en,
                 :button_label_nl, :button_label_en, :button_url,
                 :background, :tight_top, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                source_type = VALUES(source_type),
                portfolio_scope = VALUES(portfolio_scope),
                collection_id = VALUES(collection_id),
                max_items = VALUES(max_items),
                show_filter_bar = VALUES(show_filter_bar),
                enable_lightbox = VALUES(enable_lightbox),
                fallback_link_url = VALUES(fallback_link_url),
                eyebrow_nl = VALUES(eyebrow_nl),
                eyebrow_en = VALUES(eyebrow_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                lead_nl = VALUES(lead_nl),
                lead_en = VALUES(lead_en),
                footer_note_nl = VALUES(footer_note_nl),
                footer_note_en = VALUES(footer_note_en),
                button_label_nl = VALUES(button_label_nl),
                button_label_en = VALUES(button_label_en),
                button_url = VALUES(button_url),
                background = VALUES(background),
                tight_top = VALUES(tight_top),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $collectionId = $values['collection_id'] ?? null;
        $maxItems = $values['max_items'] ?? null;

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'source_type' => (string) ($values['source_type'] ?? 'portfolio'),
            'portfolio_scope' => (string) ($values['portfolio_scope'] ?? 'all'),
            'collection_id' => ($collectionId === null || $collectionId === '' || (int) $collectionId <= 0)
                ? null
                : (int) $collectionId,
            'max_items' => ($maxItems === null || $maxItems === '' || (int) $maxItems <= 0) ? null : (int) $maxItems,
            'show_filter_bar' => ($values['show_filter_bar'] ?? false) ? 1 : 0,
            'enable_lightbox' => ($values['enable_lightbox'] ?? false) ? 1 : 0,
            'fallback_link_url' => self::nullIfEmpty($values['fallback_link_url'] ?? null),
            'eyebrow_nl' => self::nullIfEmpty($values['eyebrow_nl'] ?? null),
            'eyebrow_en' => self::nullIfEmpty($values['eyebrow_en'] ?? null),
            'title_nl' => self::nullIfEmpty($values['title_nl'] ?? null),
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'lead_nl' => self::nullIfEmpty($values['lead_nl'] ?? null),
            'lead_en' => self::nullIfEmpty($values['lead_en'] ?? null),
            'footer_note_nl' => self::nullIfEmpty($values['footer_note_nl'] ?? null),
            'footer_note_en' => self::nullIfEmpty($values['footer_note_en'] ?? null),
            'button_label_nl' => self::nullIfEmpty($values['button_label_nl'] ?? null),
            'button_label_en' => self::nullIfEmpty($values['button_label_en'] ?? null),
            'button_url' => self::nullIfEmpty($values['button_url'] ?? null),
            'background' => (string) ($values['background'] ?? 'default'),
            'tight_top' => ($values['tight_top'] ?? false) ? 1 : 0,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * Permanently removes ONE instance — used by the page builder's
     * "Delete section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM item_galleries WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private static function nullIfEmpty(string|bool|int|null $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value !== null && $value !== '') ? $value : null;
    }
}
