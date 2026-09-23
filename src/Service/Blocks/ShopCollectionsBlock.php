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
 * NO CONTENT ROW. The tiles have no settings of their own. The page_sections
 * row's `section_id` is the page's own id, unique per page under the one-per-
 * page rule and so under UNIQUE(section_type, section_id); a historical row
 * keeps the id it always had.
 *
 * Belongs to the Shop module: with the Shop off the type is not registered, so
 * it is neither offered nor rendered.
 */
final class ShopCollectionsBlock extends BlockDefinition
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
            'note' => 'Alleen actieve collecties met minstens één actief product verschijnen hier. De collecties zelf beheer je bij Collecties.',
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
     * Nothing to create: the tiles have no settings. The row's section_id is
     * the page's own id (see the class docblock).
     */
    public function create(string $pageSlug): array
    {
        $page = (new \App\Repository\PageRepository())->findByContentKey($pageSlug);

        if ($page === null) {
            throw new \RuntimeException('Collection tiles can only be added to an existing page.');
        }

        return [(int) $page['id'], null];
    }

    /** Nothing of its own to delete: removing the block removes only its page_sections row. */
    public function deleteContent(array $pageSection): void
    {
    }

    /**
     * No editor of its own: collections are edited under Collecties. Adding
     * the block therefore lands back on the page, on the new row.
     */
    public function editUrl(array $pageSection): ?string
    {
        return null;
    }

    public function clearCache(): void
    {
    }

    public function contentTable(): ?string
    {
        return null;
    }

    public function translatableFields(): array
    {
        return [];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        render_section_shop_collections(CollectionContent::activeForShop());
    }

    /**
     * Tiles in the shape CollectionContent hands them over. The picture path
     * is stored without its leading slash, which the partial adds.
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

        return ['collections' => $collections];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_shop_collections($content['collections']);
    }
}
