<?php

namespace App\Service\Blocks;

require_once dirname(__DIR__, 3) . '/partials/section-shop-collections.php';

/**
 * The storefront's collection tiles — the strip shop.php used to hardcode
 * above its product grid. Only active collections holding at least one active
 * product appear, and that stays a Collecties concern; this block just says
 * where they render.
 */
final class ShopCollectionsBlock extends FixedBlockDefinition
{
    public function type(): string
    {
        return 'shop_collections';
    }

    public function meta(): array
    {
        return [
            'label' => 'Collectie-tegels',
            'manual_add' => false,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => ['shop'],
            'deletable' => false,
            'kind' => self::KIND_DYNAMIC,
            'badge_label' => 'Beheerd via Collecties',
            'note' => 'Alleen actieve collecties met minstens één actief product verschijnen hier.',
            'edit_links' => [['label' => 'Collecties', 'url' => '/admin/collections.php']],
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
            'bovenaan de winkelpagina, boven het productoverzicht',
        ];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        render_section_shop_collections();
    }
}
