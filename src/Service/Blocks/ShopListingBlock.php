<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\ShopListingRepository;
use App\Service\ShopListingContent;

/**
 * What the Shop's two listing blocks — Productgrid (ProductGridBlock) and
 * Collectie-tegels (ShopCollectionsBlock) — have in common: a row of their own
 * in `shop_listing_blocks` that carries nothing but the "Actief" switch and an
 * optional head (BlockHead: eyebrow, title, text, per website language), one
 * editor (admin/shop-listing.php) and Extra vormgeving.
 *
 * WHAT THEY LIST IS NOT THEIR CONTENT. Products and collections are the
 * Shop's records with their own CMS screens and their own search provider.
 * So only the head is the block's words: it is all translatableFields() and
 * searchFields() name, and the site search never copies a product name or a
 * collection text into a page's index through these blocks.
 *
 * Two subclasses and no more: a third Shop block with different settings
 * gets a table of its own, not a column here.
 */
abstract class ShopListingBlock extends BlockDefinition
{
    /**
     * A new instance: shown, without a head, so it lists exactly what a
     * listing without words always did. A page template creates blocks
     * through here too, so nothing site-specific.
     */
    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ShopListingRepository();
        $repository->createSection($pageSlug, $key);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ShopListingRepository())->deleteSection($this->sectionId($pageSection));
    }

    /** The one editor of both listings: the head and "Actief". */
    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('shop-listing', $pageSection);
    }

    public function clearCache(): void
    {
        ShopListingContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return ShopListingRepository::TABLE;
    }

    /** The head only: what the block lists is the Shop's, not the block's. */
    public function translatableFields(): array
    {
        return [ShopListingRepository::TABLE => BlockHead::fields()];
    }

    /**
     * The block's own head and nothing else: products and collections are
     * found through the Shop's own search provider.
     */
    public function searchFields(): array
    {
        return [ShopListingRepository::TABLE => BlockHead::searchRoles()];
    }

    /** Its own title if it has one, else the block's name. */
    public function instanceTitle(array $pageSection): string
    {
        $title = BlockLocalization::name(ShopListingRepository::TABLE, $this->sectionId($pageSection), BlockHead::TITLE);

        return $title !== '' ? $title : parent::instanceTitle($pageSection);
    }

    /**
     * Background, lines and room, and the effects that stand still (glow,
     * pattern) only, as the gallery: falling sparks would move behind a grid
     * of things to click. The cards themselves keep the Shop's own look.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }

    /**
     * The row of this instance, or the empty structure when it has none (the
     * storefront of shop.php draws the grid without a page section).
     *
     * @param array<string, mixed> $pageSection
     *
     * @return array{state: string, id: int, eyebrow: string, title: string, lead: string}
     */
    protected function listingContent(array $pageSection): array
    {
        return ShopListingContent::forSection(
            (string) ($pageSection['page_slug'] ?? ''),
            (string) ($pageSection['section_key'] ?? '')
        );
    }

    /**
     * The library's head: the sample words, so the preview shows where the
     * block's own words go.
     *
     * @return array{eyebrow: string, title: string, lead: string}
     */
    protected static function sampleHead(BlockSamples $samples): array
    {
        return [
            BlockHead::EYEBROW => $samples->localized('eyebrow'),
            BlockHead::TITLE => $samples->localized('title'),
            BlockHead::LEAD => $samples->localized('lead'),
        ];
    }
}
