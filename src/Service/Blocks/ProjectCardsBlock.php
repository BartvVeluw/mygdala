<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Module\PortfolioModule;
use App\Repository\ItemGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
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
 * Portfolio's gallery source, so a card behaves exactly as it does in any
 * gallery: its picture always zooms, and "Bekijk project" goes to a legacy
 * published page, otherwise to the item's own /portfolio/<slug>, otherwise
 * there is no button (MODULES.md, "Portfolio"). Nothing in this file queries a
 * project, builds a card or resolves a link, and none of that belongs here.
 * Why it is a block type of its own at all: docs/content-blocks/DECISIONS.md.
 *
 * What it leaves out is the point of it: a source to choose, a collection,
 * a zoom switch and a link for cards without a page of their own — settings a
 * project does nothing with, since a project's picture always zooms and its
 * card links only to its own page. rowValues() stores those fixed. What it
 * has besides the projects is the gallery's words: an optional head
 * (eyebrow, title, text) and an optional closing text with a button, such as
 * "Bekijk al het werk" under a selection on the homepage.
 *
 * THE PORTFOLIO'S ONLY WAY TO SHOW PROJECTS IN A BLOCK (v0.1.15). The gallery
 * used to offer portfolio items as a source too ("Portfoliogalerij"); that
 * block is the Shop's Collectiegalerij now, and
 * db/migrations/20261015110000 turned every gallery on portfolio items into
 * this block, in place: same row, same page position, visibility, Extra
 * vormgeving, card presentation, choice of projects and words. A legacy
 * "sluit aan op de sectie erboven" (`tight_top`) is kept as stored, and
 * rendered, though this editor does not offer it: like most blocks, this one
 * already drops its top room under a hero by itself.
 *
 * WHICH PROJECTS (Projecten 2.0): all visible ones, one category's, or picked
 * by hand, in the Portfolio's own order, newest or oldest first, by title, or
 * at random, 3 to 12 or all of them — the gallery's own settings
 * (App\Service\ItemGalleryContent::SCOPES and SORTS), applied by the
 * Portfolio's gallery source. Several of these on one page each keep their
 * own: a Wolven block, a D&D block and an Onderzetters block side by side.
 *
 * ONE ROW, ONE EDITOR. Both blocks keep their rows in `item_galleries`, so the
 * editors tell them apart by the page section that placed a row
 * (page_sections.section_type): admin/project-cards.php edits only this
 * block's rows, admin/item-gallery.php only the gallery's.
 */
final class ProjectCardsBlock extends BlockDefinition implements InspectsContent, PresentsCards
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

    /** The Portfolio's own drawer. */
    public function category(): string
    {
        return BlockCategories::PORTFOLIO;
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
     * The gallery's own declaration, word for word: both blocks keep their
     * words on the same item_galleries rows, and a table has one set of
     * fields (BlockDefinitionContractTest). This block's editor offers all
     * five: the head, the closing text and the button's label.
     */
    public function translatableFields(): array
    {
        return (new ItemGalleryBlock())->translatableFields();
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
     * Every visible project, in the Portfolio's own order, as plain cards: no
     * heading and no filter buttons until the editor asks for them, so no
     * words either. Nothing site-specific, since a page template creates
     * blocks through here too (CONTENT-BLOCKS.md).
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
        if (!ItemGallerySources::belongsTo((string) $content['source_type'], $this->type())) {
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
                'alt' => $image['alt'],
                'title' => $samples->localizedItem('item', $index),
                'subtitle' => $samples->localizedItem('category', $index),
                'categories' => '',
                'url' => BlockSamples::LINK,
                'is_detail_link' => true,
                'follows_fallback_link' => false,
            ];
        }

        $content = self::rowValues([]);
        unset($content['is_active']);

        return [
            'id' => 0,
            ...$content,
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'footer_note' => $samples->none(),
            'button_label' => $samples->none(),
            'filter_categories' => [],
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_item_gallery($content, $revealGroup);
    }

    /**
     * Its own title if it has one, otherwise which projects it shows — all,
     * one category by name, or picked by hand — so two of these on one page
     * stay tellable apart in the page builder.
     */
    public function instanceTitle(array $pageSection): string
    {
        $title = BlockLocalization::name('item_galleries', $this->sectionId($pageSection), 'title');
        if ($title !== '') {
            return $title;
        }

        $content = ItemGalleryContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['portfolio_scope'] === ItemGalleryContent::SCOPE_CATEGORY) {
            foreach (ItemGallerySources::categoryChoices(PortfolioModule::GALLERY_SOURCE) as $category) {
                if ((int) $category['id'] === (int) $content['portfolio_category_id']) {
                    return AdminTranslator::trans('block_projects.instance_category', ['name' => $category['name']]);
                }
            }
        }

        return AdminTranslator::trans(
            $content['portfolio_scope'] === ItemGalleryContent::SCOPE_MANUAL
                ? 'block_projects.instance_manual'
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
        return ['assets/js/lightbox.js', 'assets/js/blocks/item-gallery.js'];
    }

    /**
     * Every shared card presentation (App\Service\Blocks\CardPresentation):
     * the cards of this block are content cards, the same in every source.
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
     * Everything a Projecten row stores that is the same in every language:
     * what its editor offers, taken from $editable, and every gallery setting
     * this block does not have, fixed. create() and
     * api/admin/update-project-cards.php both store through here, so no
     * request can set the source, and no save leaves a setting behind that
     * this block's editor does not show: a zoom or a fallback link nobody
     * could switch off again. One exception: `tight_top`, a legacy setting
     * of galleries that became Projecten, is what the caller hands over (the
     * endpoint: what is stored), never a request's. The words go through
     * rowWords().
     *
     * Validating $editable is the caller's job, before it gets here
     * (App\Service\ItemGallerySelection and ItemGalleryContent::isBackground()),
     * as for every write through ItemGalleryRepository.
     *
     * @param array<string, mixed> $editable portfolio_scope, portfolio_category_id, item_sort, max_items, show_filter_bar, button_url, background, tight_top, is_active
     *
     * @return array<string, string|bool|int|null> in ItemGalleryRepository::upsertSection()'s shape
     */
    public static function rowValues(array $editable): array
    {
        $maxItems = $editable['max_items'] ?? null;

        return [
            'source_type' => PortfolioModule::GALLERY_SOURCE,
            'portfolio_scope' => (string) ($editable['portfolio_scope'] ?? ItemGalleryContent::SCOPE_ALL),
            'portfolio_category_id' => ($editable['portfolio_category_id'] ?? null) === null ? null : (int) $editable['portfolio_category_id'],
            'collection_id' => null,
            'max_items' => $maxItems === null ? null : (int) $maxItems,
            'item_sort' => (string) ($editable['item_sort'] ?? ItemGalleryContent::SORTS[0]),
            'show_filter_bar' => (bool) ($editable['show_filter_bar'] ?? false),
            'enable_lightbox' => false,
            'fallback_link_url' => '',
            'button_url' => (string) ($editable['button_url'] ?? ''),
            'background' => (string) ($editable['background'] ?? 'default'),
            'tight_top' => (bool) ($editable['tight_top'] ?? false),
            'is_active' => (bool) ($editable['is_active'] ?? true),
        ];
    }

    /**
     * The words of one language a Projecten save stores, for
     * BlockLocalization::save(): every field of item_galleries, each from
     * $editable or empty, so a save writes exactly the words its editor
     * shows and nothing else.
     *
     * @param array<string, string> $editable eyebrow, title, lead, footer_note, button_label
     *
     * @return array<string, string> every field of item_galleries
     */
    public static function rowWords(array $editable): array
    {
        $words = [];
        foreach (['eyebrow', 'title', 'lead', 'footer_note', 'button_label'] as $field) {
            $words[$field] = (string) ($editable[$field] ?? '');
        }

        return $words;
    }

    /**
     * Content: projects, or a choice that can give them (the Portfolio source, judged on its configuration) (InspectsContent, SectionRegistry::isEmpty()).
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
     * As the gallery it draws (ItemGalleryBlock): background, lines and room,
     * and the effects that stand still (glow, pattern) only: falling sparks
     * would move behind a grid of projects, where they compete with the
     * pictures and the things to click.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }
}
