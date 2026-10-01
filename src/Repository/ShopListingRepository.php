<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the own rows of the Shop's Productgrid and Collectie-tegels
 * (`shop_listing_blocks`, db/migrations/20261015100000): one row per instance,
 * addressed by (page_slug, section_key) like every block, holding nothing but
 * its address and `is_active` (every block table has one; no editor switches
 * it today, the page builder's eye hides the block). The block's head lives
 * in block_translations (App\Service\Blocks\BlockHead); the products and
 * collections it lists stay in the Shop's own tables.
 *
 * Both block types share the table, as the gallery and Projecten share
 * item_galleries: which type a row belongs to is the page section that
 * placed it (page_sections.section_type).
 */
final class ShopListingRepository extends Repository
{
    public const TABLE = 'shop_listing_blocks';

    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM shop_listing_blocks WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** A new instance: shown, without a head. */
    public function createSection(string $pageSlug, string $sectionKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO shop_listing_blocks (page_slug, section_key, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * Removes one instance's row — the page builder's "Delete section"
     * (App\Service\SectionRegistry::delete()). No product or collection is
     * touched.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM shop_listing_blocks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
