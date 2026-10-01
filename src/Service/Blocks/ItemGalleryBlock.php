<?php

namespace App\Service\Blocks;

use App\Repository\ItemGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;

require_once dirname(__DIR__, 3) . '/partials/section-item-gallery.php';

/**
 * The Shop's Collectiegalerij: the products of one collection as a picture
 * grid, with an optional zoom, a link for cards without a page of their own,
 * a head, a closing text and a button.
 *
 * SHOP ONLY. Registered by App\Module\ShopModule::blockDefinitions(), so with
 * the Shop off it is neither offered nor rendered (its rows stay). Its source
 * is a closed list too (App\Service\ItemGallerySources), and every source
 * there belongs to one block type: this block only ever gets the Shop's
 * collection source. Until v0.1.15 it was Core and also showed portfolio
 * items, with a second picker card "Portfoliogalerij"; Portfolio items are
 * the Projecten block's now (ProjectCardsBlock), and
 * db/migrations/20261015110000 turned every gallery on portfolio items into
 * one, in place. A stored row whose source belongs to another block renders
 * nothing here, and its editor refuses to save one.
 *
 * The products behind it are not its content: they belong to Collecties and
 * Producten, which keep their own CRUD and media, so deleting this block
 * deletes only the placement and its settings. It shares item_galleries,
 * its content class and its partial with Projecten; which block a row is,
 * is the page section that placed it.
 */
final class ItemGalleryBlock extends BlockDefinition implements InspectsContent, PresentsCards
{
    public function type(): string
    {
        return 'item_gallery';
    }

    public function meta(): array
    {
        return [
            'label' => 'Collectiegalerij',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Kies welke collectie dit blok toont. Vergroting, maximum aantal items, kop, slottekst en knop zijn instellingen van dit blok; de producten zelf beheer je bij Collecties en Producten.',
        ];
    }

    public function description(): string
    {
        return 'De producten van één collectie als raster met beeld, met optioneel een vergroting bij het aanklikken. Welke collectie kies je in het blok.';
    }

    public function category(): string
    {
        return BlockCategories::SHOP;
    }

    public function icon(): string
    {
        return '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::GALLERY];
    }

    public function useCases(): array
    {
        return [
            'beeld uit een collectie tonen',
            'een collectie tussen je eigen tekst en beeld',
        ];
    }

    /**
     * The block's own words, per website language; the collection and every
     * display setting are the same in every language and stay in
     * item_galleries. ProjectCardsBlock shares the table and this declaration.
     * The lengths are the ones the editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'item_galleries' => [
                TranslatableField::plain('eyebrow', 255),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('lead', 600),
                TranslatableField::plain('footer_note', 600),
                TranslatableField::plain('button_label', 150),
            ],
        ];
    }

    /**
     * What the site search finds this block by (BlockDefinition::searchFields()).
     */
    public function searchFields(): array
    {
        return [
            'item_galleries' => [
                'eyebrow' => BlockSearchRole::TEXT,
                'title' => BlockSearchRole::HEADING,
                'lead' => BlockSearchRole::TEXT,
                'footer_note' => BlockSearchRole::TEXT,
                'button_label' => BlockSearchRole::NONE,
            ],
        ];
    }

    /**
     * A new Collectiegalerij on the Shop's collection source, without a
     * collection yet (its editor asks which) and with zoom on.
     */
    public function create(string $pageSlug): array
    {
        return $this->createWithSource($pageSlug, ItemGallerySources::defaultSourceFor($this->type()));
    }

    /** @return array{0: int, 1: string} */
    private function createWithSource(string $pageSlug, string $source): array
    {
        $key = self::newSectionKey();

        $repository = new ItemGalleryRepository();
        $repository->upsertSection($pageSlug, $key, [
            'source_type' => $source,
            'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
            // A collection has no categories, so no filter bar to switch on.
            'show_filter_bar' => false,
            'enable_lightbox' => true,
            'background' => 'default',
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ItemGalleryRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === ItemGalleryContent::STATE_HIDDEN) {
            return;
        }

        // Only this block's own source. A row whose source belongs to another
        // block (Projecten's portfolio items) was not written by this
        // block's editor, and shows nothing rather than somebody else's
        // content. A missing row has no source and no items either.
        if (!ItemGallerySources::belongsTo((string) $content['source_type'], $this->type())) {
            return;
        }

        render_section_item_gallery($content, $revealGroup);
    }

    /**
     * No source: a sample hands the partial its items directly, exactly as
     * ItemGalleryContent does after asking a source. The filter bar and the
     * zoom are on, so the preview shows what those two settings add; the
     * cards link nowhere, which is what makes them zoomable.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();
        $slugs = ['categorie-a', 'categorie-b'];

        $items = [];
        foreach (range(0, 5) as $index) {
            $items[] = [
                'image_path' => $image['image_path'],
                'alt' => $image['alt'],
                'title' => $samples->localizedItem('item', $index),
                'subtitle' => $samples->localizedItem('category', $index),
                'categories' => $slugs[$index % 2],
                'url' => '',
                'is_detail_link' => false,
                'follows_fallback_link' => false,
            ];
        }

        return [
            'id' => 0,
            'source_type' => '',
            'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
            'portfolio_category_id' => null,
            'item_sort' => ItemGalleryContent::SORTS[0],
            'collection_id' => null,
            'max_items' => null,
            'show_filter_bar' => true,
            'enable_lightbox' => true,
            'fallback_link_url' => '',
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'footer_note' => $samples->localized('note'),
            'button_label' => $samples->localized('button'),
            'button_url' => BlockSamples::LINK,
            'background' => 'default',
            'tight_top' => false,
            'filter_categories' => [
                ['slug' => $slugs[0], 'name' => $samples->localizedItem('category', 0)],
                ['slug' => $slugs[1], 'name' => $samples->localizedItem('category', 1)],
            ],
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_item_gallery($content, $revealGroup);
    }

    /**
     * Its own title if it has one, otherwise what it shows, so two galleries
     * on one page stay tellable apart even when neither carries a heading.
     */
    public function instanceTitle(array $pageSection): string
    {
        $title = BlockLocalization::name('item_galleries', $this->sectionId($pageSection), 'title');
        if ($title !== '') {
            return $title;
        }

        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        return ItemGalleryContent::sourceLabel((string) $content['source_type']);
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('item-gallery', $pageSection);
    }

    /**
     * The filter bar, and the site's one lightbox for a zoomable card. The
     * lightbox's script (assets/js/lightbox.js) and its .lightbox rules in
     * assets/css/core.css are shared with portfolio-detail.php, so they
     * belong to neither owner alone.
     */
    public function styles(): array
    {
        return ['assets/css/blocks/item-gallery.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/lightbox.js', 'assets/js/blocks/item-gallery.js'];
    }

    /**
     * Every shared card presentation (App\Service\Blocks\CardPresentation):
     * a product of a collection is a picture card here, the same card as a
     * project in Projecten, not the Shop's product card with a price.
     */
    public function cardPresentations(): array
    {
        return CardPresentation::ALL;
    }

    public function cardPresentation(array $pageSection): string
    {
        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        return CardPresentation::stored($content['card_presentation'] ?? null);
    }

    public function clearCache(): void
    {
        ItemGalleryContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'item_galleries';
    }

    /**
     * Content: pictures, or a source that can give them (a dynamic block is judged on its source, not on what it holds today) (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== ItemGalleryContent::STATE_ACTIVE) {
            return true;
        }

        return $content['items'] !== [] || ItemGalleryContent::isConfigured($content);
    }

    /**
     * Background, lines and room (its old own background moved here,
     * db/migrations/20261008100000), and the effects that stand still (glow,
     * pattern) only: falling sparks would move behind a grid of pictures,
     * where they compete with the pictures and the things to click.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }
}
