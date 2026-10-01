<?php

namespace App\Service\Blocks;

require_once dirname(__DIR__, 3) . '/partials/section-product-grid.php';

/**
 * The Shop's product grid: every product in the shop, as cards filled by
 * assets/js/shop/shop.js from GET /api/products.php.
 *
 * AN ORDINARY SHOP BLOCK. It used to be a fixed, application-critical block
 * of the one storefront page (content_key `shop`): not addable, not
 * deletable, and it made that page impossible to unpublish or delete. Since
 * the product overview is a page the owner chooses
 * (App\Service\ShopOverview, MODULES.md "Shop"), the grid is placed like any
 * other block: by hand, on any ordinary page, where the owner wants it
 * between the rest of the content, at most once per page, and removable
 * again. The storefront page keeps the grid it has; nothing is copied or
 * moved.
 *
 * ITS OWN ROW, FOR ITS HEAD ONLY (ShopListingBlock): an optional eyebrow,
 * title and text above the grid, per website language, and "Actief". Which
 * products show, their names, pictures and prices stay the Shop's: the grid
 * is filled live, and nothing of a product is ever copied into the block or
 * into the site search's copy of the page. Without a head it renders exactly
 * what it always did (db/migrations/20261015100000 gave every existing grid
 * its row and changed nothing else).
 *
 * Belongs to the Shop module: with the Shop off the type is not registered, so
 * it is neither offered nor rendered (MODULES.md, "Blokken van een
 * uitgeschakelde module").
 */
final class ProductGridBlock extends ShopListingBlock
{
    public function type(): string
    {
        return 'product_grid';
    }

    public function meta(): array
    {
        return [
            'label' => 'Productgrid',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'deletable' => true,
            'kind' => self::KIND_DYNAMIC,
            'badge_label' => 'Beheerd via Producten',
            'note' => 'Toont alle producten van de shop. Hier geef je het blok desgewenst een bovenkop, titel en tekst; producten, foto\'s en prijzen beheer je bij Producten.',
        ];
    }

    /**
     * Shop-owned assets, asked for by a Shop block: the grid is filled
     * client-side from GET /api/products.php by assets/js/shop/shop.js.
     */
    public function styles(): array
    {
        return ['assets/css/shop/shop.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/shop/shop.js'];
    }

    public function description(): string
    {
        return 'Alle producten van de webshop als kaarten met foto, naam en prijs, op de plek in de pagina waar je het blok zet. De producten zelf beheer je bij Producten.';
    }

    public function category(): string
    {
        return BlockCategories::SHOP;
    }

    public function icon(): string
    {
        return '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::PRODUCTS];
    }

    public function useCases(): array
    {
        return [
            'de pagina die je bij Shop-instellingen als productoverzicht kiest',
            'alle producten tussen je eigen tekst en beelden',
        ];
    }

    /**
     * The grid, under the block's head if it has one. A row switched off in
     * its editor renders nothing; no row at all (shop.php's storefront, which
     * has no page section) is the grid without a head.
     */
    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = $this->listingContent($pageSection);
        if ($content['state'] === \App\Service\ShopListingContent::STATE_HIDDEN) {
            return;
        }

        render_section_product_grid($content);
    }

    /**
     * No sample, on purpose — the one block the library cannot preview. Its
     * cards are not in its markup: assets/js/shop/shop.js draws them after
     * asking /api/products.php for the live catalogue. A sample has nothing
     * to hand the partial, and real products would make the preview depend
     * on this shop's data. The library shows the schematic drawing with a
     * note instead (admin/content-blocks.php).
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        return null;
    }
}
