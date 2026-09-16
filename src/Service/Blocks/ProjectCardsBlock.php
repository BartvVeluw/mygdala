<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\Language\AdminTranslator;

require_once dirname(__DIR__, 3) . '/partials/section-item-gallery.php';

/**
 * "Projecten": the Portfolio's projects on an ordinary CMS page, as cards that
 * open each project's own page. Registered by
 * App\Module\PortfolioModule::blockDefinitions(), so the block picker offers it
 * only while the Portfolio runs.
 *
 * NOT A SECOND GALLERY. It is App\Service\Blocks\ItemGalleryBlock's content
 * model with the source fixed to portfolio items: the same `item_galleries`
 * row (App\Repository\ItemGalleryRepository), read by the same
 * App\Service\ItemGalleryContent and drawn by the same
 * partials/section-item-gallery.php with the same stylesheet and script. The
 * projects, their order, their categories and every card's link come from the
 * Portfolio's gallery source, so a card links exactly as it does in any
 * gallery: to its published page, otherwise to its old project address,
 * otherwise nowhere (MODULES.md, "Portfolio"). Nothing in this file queries a
 * project, builds a card or resolves a link, and none of that belongs here.
 * Why it is a block type of its own at all: docs/content-blocks/DECISIONS.md.
 *
 * What it leaves out is the point of it. The gallery editor asks for a source
 * and offers settings a project does nothing with: a collection, zoom, a link
 * for cards without a page of their own, a closing text and a button.
 * rowValues() stores those fixed, so a project without a page of its own is a
 * plain card that goes nowhere.
 *
 * ONE ROW, ONE EDITOR. Both blocks keep their rows in `item_galleries`, so the
 * editors tell them apart by the page section that placed a row
 * (page_sections.section_type): admin/project-cards.php edits only this
 * block's rows, admin/item-gallery.php only the gallery's.
 */
final class ProjectCardsBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'project_cards';
    }

    public function meta(): array
    {
        return [
            'label' => 'Projecten',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Kies hier welke projecten dit blok toont en hoeveel. De projecten zelf, hun foto\'s en de pagina waar elk project naartoe linkt, beheer je in Portfolio.',
        ];
    }

    public function description(): string
    {
        return 'Je projecten uit Portfolio als kaarten met foto en titel. Heeft een project een eigen pagina, dan klikt de bezoeker daarheen door.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    /** The picture frame the Portfolio's own sidebar entry wears (admin/_header.php). */
    public function icon(): string
    {
        return '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M21 16l-5.5-5.5L7 19"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::GALLERY];
    }

    public function useCases(): array
    {
        return [
            'je projecten op een gewone pagina',
            'een selectie uitgelicht werk',
            'doorklikken naar de pagina van elk project',
        ];
    }

    /**
     * Every visible project, in the Portfolio's own order, as plain cards: no
     * heading and no filter buttons until the editor asks for them. Nothing
     * site-specific, since a page template creates blocks through here too
     * (CONTENT-BLOCKS.md).
     */
    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ItemGalleryRepository();
        $repository->upsertSection($pageSlug, $key, self::rowValues([]));

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

        // Only ever the Portfolio's projects under this name. A row whose
        // source says otherwise was changed outside both editors, and shows
        // nothing rather than somebody else's content. A missing row has no
        // source and no items either.
        if ($content['source_type'] !== PortfolioModule::GALLERY_SOURCE) {
            return;
        }

        // Like most blocks it follows the page: straight under a hero it drops
        // its own top spacing. The gallery keeps a stored setting for that
        // instead, from the homepage teaser it once was.
        if ($tightTop) {
            $content['tight_top'] = true;
        }

        render_section_item_gallery($content, $revealGroup);
    }

    /**
     * Cards the way the Portfolio's source hands them over when a project has
     * a page of its own: linked, with the arrow. The settings rowValues()
     * fixes stay fixed here too, so the preview never shows a zoom, a footer
     * text or a button this block cannot have.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        $items = [];
        foreach (range(0, 5) as $index) {
            $items[] = [
                'image_path' => $image['image_path'],
                'alt_nl' => $image['alt_nl'],
                'alt_en' => $image['alt_en'],
                ...$samples->itemFields('title', 'item', $index),
                ...$samples->itemFields('subtitle', 'category', $index),
                'categories' => '',
                'url' => BlockSamples::LINK,
                'is_detail_link' => true,
                'follows_fallback_link' => false,
            ];
        }

        $content = self::rowValues([
            ...$samples->fields('title', 'title'),
            ...$samples->fields('lead', 'lead'),
        ]);
        unset($content['is_active']);

        return [
            'id' => 0,
            ...$content,
            'filter_categories' => [],
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_item_gallery($content, $revealGroup);
    }

    /**
     * Its own title if it has one, otherwise which projects it shows, so two
     * of these on one page stay tellable apart in the page builder.
     */
    public function instanceTitle(array $pageSection): string
    {
        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        $title = (string) ($content['title_nl'] ?? '');
        if ($title !== '') {
            return $title;
        }

        return AdminTranslator::trans(
            $content['portfolio_scope'] === ItemGalleryContent::SCOPE_FEATURED
                ? 'block_projects.instance_featured'
                : 'block_projects.instance_all'
        );
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('project-cards', $pageSection);
    }

    /**
     * The gallery's own two files: this block renders the very same partial,
     * filter buttons included, so it needs the very same rules and behaviour.
     * App\Service\Blocks\ContactFormBlock shares the form block's files for the
     * same reason, and App\Service\PageAssets loads them once when both blocks
     * are on one page.
     */
    public function styles(): array
    {
        return ['assets/css/blocks/item-gallery.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/item-gallery.js'];
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
     * Everything a Projecten row stores: what its editor offers, taken from
     * $editable, and every gallery setting this block does not have, fixed.
     * create() and api/admin/update-project-cards.php both store through here,
     * so no request can set the source, and no save leaves a setting behind
     * that this block's editor does not show: a zoom, a fallback link or a
     * button nobody could switch off again.
     *
     * Validating $editable is the caller's job, before it gets here
     * (ItemGalleryContent::isPortfolioScope() and isBackground()), as for every
     * write through ItemGalleryRepository.
     *
     * @param array<string, mixed> $editable portfolio_scope, max_items, show_filter_bar, background, title_nl/en, lead_nl/en, is_active
     *
     * @return array<string, string|bool|int|null> in ItemGalleryRepository::upsertSection()'s shape
     */
    public static function rowValues(array $editable): array
    {
        $maxItems = $editable['max_items'] ?? null;

        return [
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => (string) ($editable['portfolio_scope'] ?? ItemGalleryContent::SCOPE_ALL),
            'collection_id' => null,
            'max_items' => $maxItems === null ? null : (int) $maxItems,
            'show_filter_bar' => (bool) ($editable['show_filter_bar'] ?? false),
            'enable_lightbox' => false,
            'fallback_link_url' => '',
            'eyebrow_nl' => '',
            'eyebrow_en' => '',
            'title_nl' => (string) ($editable['title_nl'] ?? ''),
            'title_en' => (string) ($editable['title_en'] ?? ''),
            'lead_nl' => (string) ($editable['lead_nl'] ?? ''),
            'lead_en' => (string) ($editable['lead_en'] ?? ''),
            'footer_note_nl' => '',
            'footer_note_en' => '',
            'button_label_nl' => '',
            'button_label_en' => '',
            'button_url' => '',
            'background' => (string) ($editable['background'] ?? 'default'),
            'tight_top' => false,
            'is_active' => (bool) ($editable['is_active'] ?? true),
        ];
    }
}
