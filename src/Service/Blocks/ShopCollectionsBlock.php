<?php

namespace App\Service\Blocks;

use App\Service\CollectionContent;

require_once dirname(__DIR__, 3) . '/partials/section-shop-collections.php';

/**
 * The Shop's collection tiles — the strip shop.php used to hardcode above its
 * product grid. Only active collections holding at least one active product
 * appear, and that stays a Collecties concern; this block just says where
 * they render.
 *
 * AN ORDINARY SHOP BLOCK, like the product grid (ProductGridBlock): placed by
 * hand on any ordinary page, at most once per page, removable again. It used
 * to be a fixed block of the historical storefront page only; that page keeps
 * the tiles it has, nothing is copied or added.
 *
 * ITS OWN ROW, FOR ITS HEAD ONLY (ShopListingBlock): an optional eyebrow,
 * title and text above the tiles, per website language, and "Actief". The
 * collections, their names, pictures and texts stay the Shop's, and are never
 * copied into the block or into the site search's copy of the page. Without
 * a head it renders exactly what it always did (db/migrations/20261015100000
 * gave every existing strip its row and changed nothing else).
 *
 * Belongs to the Shop module: with the Shop off the type is not registered, so
 * it is neither offered nor rendered.
 */
final class ShopCollectionsBlock extends ShopListingBlock
{
    public function type(): string
    {
        return 'shop_collections';
    }

    public function meta(): array
    {
        return [
            'label' => 'Collectie-tegels',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'deletable' => true,
            'kind' => self::KIND_DYNAMIC,
            'badge_label' => 'Beheerd via Collecties',
            'note' => 'Alleen actieve collecties met minstens één actief product verschijnen hier. Hier geef je het blok desgewenst een bovenkop, titel en tekst; de collecties zelf beheer je bij Collecties.',
        ];
    }

    /** The collection tiles are styled by the Shop, not by Core. */
    public function styles(): array
    {
        return ['assets/css/shop/shop.css'];
    }

    public function description(): string
    {
        return 'De tegels naar je collecties, met een foto en de naam van elke collectie. De collecties zelf beheer je bij Collecties.';
    }

    public function category(): string
    {
        return BlockCategories::SHOP;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="7" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><rect x="13" y="13" width="8" height="7" rx="1.5"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::TILES];
    }

    public function useCases(): array
    {
        return [
            'boven het productgrid op je productoverzicht',
            'de collecties van de shop tussen je eigen tekst en beelden',
        ];
    }

    /**
     * The tiles, under the block's head if it has one. A row switched off in
     * its editor renders nothing.
     */
    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = $this->listingContent($pageSection);
        if ($content['state'] === \App\Service\ShopListingContent::STATE_HIDDEN) {
            return;
        }

        render_section_shop_collections(CollectionContent::activeForShop(), $content);
    }

    /**
     * Tiles in the shape CollectionContent hands them over, under the head's
     * sample words in ShopListingContent's shape. The picture path is stored
     * without its leading slash, which the partial adds.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $collections = [];
        foreach (range(0, 2) as $index) {
            $collections[] = [
                'id' => 0,
                'slug' => 'voorbeeld-' . ($index + 1),
                // One string per field, the shape CollectionContent hands
                // the partial.
                'name' => $samples->localizedItem('collection', $index),
                'description' => $samples->localizedItem('item_body', $index),
                'image_path' => ltrim(BlockSamples::IMAGE_PATH, '/'),
                'url' => BlockSamples::LINK,
            ];
        }

        return ['collections' => $collections] + self::sampleHead($samples);
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_shop_collections($content['collections'], $content);
    }
}
