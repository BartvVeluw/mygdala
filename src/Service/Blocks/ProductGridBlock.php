<?php

namespace App\Service\Blocks;

require_once dirname(__DIR__, 3) . '/partials/section-product-grid.php';

/**
 * The storefront's product grid. The one APPLICATION-CRITICAL block: a page
 * carrying it cannot be unpublished or deleted, because that would take the
 * webshop offline (App\Service\PageContent::isProtected()). The protection
 * follows this block, never a page name.
 */
final class ProductGridBlock extends FixedBlockDefinition
{
    public function type(): string
    {
        return 'product_grid';
    }

    public function meta(): array
    {
        return [
            'label' => 'Productoverzicht',
            'manual_add' => false,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => ['shop'],
            'deletable' => false,
            'app_critical' => true,
            'kind' => self::KIND_DYNAMIC,
            'badge_label' => 'Beheerd via Producten',
            'note' => 'Producten, foto\'s en voorraad worden beheerd via Producten, niet als paginatekst.',
            'edit_links' => [['label' => 'Producten', 'url' => '/admin/products.php']],
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
        return 'Het overzicht van alle producten in de webshop, met foto, naam en prijs. De producten zelf beheer je bij Producten.';
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
            'de winkelpagina van de webshop',
        ];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        render_section_product_grid();
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
