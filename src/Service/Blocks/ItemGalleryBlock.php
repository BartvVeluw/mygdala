<?php

namespace App\Service\Blocks;

use App\Repository\ItemGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;

require_once dirname(__DIR__, 3) . '/partials/section-item-gallery.php';

/**
 * ONE gallery block whose content source is a setting (phase 4): portfolio
 * items, or the products of one collection. The Portfolio grid and the
 * homepage's "greep uit eerder werk" are two instances of it, which is why it
 * knows nothing about "portfolio" and is allowed everywhere.
 *
 * Its filter bar, lightbox and item cap are settings of the instance. The
 * source list itself stays a CLOSED whitelist in App\Service\ItemGallerySources,
 * every entry of it contributed by the module that owns the content —
 * a security boundary, not a style choice: a stored source key that is not on
 * that list renders nothing rather than reaching a table of its own choosing.
 *
 * The items behind it are not its content: they belong to Portfolio and
 * Collecties, which keep their own CRUD and uploaded media, so deleting this
 * block deletes only the placement and its settings.
 */
final class ItemGalleryBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'item_gallery';
    }

    public function meta(): array
    {
        return [
            'label' => 'Portfolio-/collectiegalerij',
            // Offered only while an enabled module has a source for it: with
            // the Portfolio and the Shop both off there is nothing it could
            // show. Instances that already exist are left exactly as they are.
            'manual_add' => ItemGallerySources::available() !== [],
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Kies zelf wat dit blok toont: portfolio-items of de producten van één collectie. Filterbalk, lightbox en maximum aantal items zijn instellingen van dit blok; de items zelf beheer je via Portfolio of Collecties.',
        ];
    }

    public function description(): string
    {
        return 'Een raster met beeld uit je portfolio of uit een collectie, met optioneel een filterbalk en een vergroting bij het aanklikken.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
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
            'een portfolio-overzicht',
            'uitgelicht werk op de homepage',
            'beeld uit een collectie tonen',
        ];
    }

    /**
     * The block's own words, per website language; the source and every
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

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        // Defaults to the block in its most familiar shape: the first source
        // an enabled module offers — portfolio items while the Portfolio runs
        // — with a filter bar and zoom.
        $repository = new ItemGalleryRepository();
        $repository->upsertSection($pageSlug, $key, [
            'source_type' => ItemGallerySources::defaultSource(),
            'portfolio_scope' => ItemGalleryContent::SCOPE_ALL,
            'show_filter_bar' => true,
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
     * Its own title if it has one, otherwise what it shows — "Portfolio-items"
     * / a collection's name — so two galleries on one page stay tellable apart
     * even when neither carries a heading.
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

    public function clearCache(): void
    {
        ItemGalleryContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'item_galleries';
    }
}
