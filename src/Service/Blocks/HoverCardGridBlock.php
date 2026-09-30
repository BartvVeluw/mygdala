<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\HoverCardGridRepository;
use App\Service\HoverCardGridContent;

require_once dirname(__DIR__, 3) . '/partials/section-hover-card-grid.php';

/**
 * Hover kaarten grid: an optional heading above a grid of picture cards that
 * react when a pointer or the keyboard reaches them — the text appears, the
 * picture zooms or turns into a second one, and an organic card changes its
 * shape (CONTENT-BLOCKS.md, "Hover kaarten grid"). For selling points,
 * categories, products, projects or promotions.
 *
 * Every card and every choice belongs to the instance
 * (App\Service\HoverCardGridContent); the words — the heading and each card's
 * badge, title, text and link label — are stored per website language in
 * block_translations, all of them optional. A card's pictures are Media
 * Library items, so removing a grid removes references and never a file
 * (deleteFiles() keeps its empty default).
 */
final class HoverCardGridBlock extends BlockDefinition implements InspectsContent
{
    public function type(): string
    {
        return 'hover_card_grid';
    }

    public function meta(): array
    {
        return [
            'label' => 'Hover kaarten grid',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Kaarten met een afbeelding in een raster. Beweeg je erover of kom je er met het toetsenbord op, dan verschijnt de tekst, zoomt de foto in of wisselt hij naar een tweede foto. Rechthoekig, afgerond, rond of in een organische vorm.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    public function icon(): string
    {
        return '<rect x="3" y="3.5" width="8" height="8" rx="2"/><rect x="13" y="3.5" width="8" height="8" rx="4"/><path d="M3 15.5c2-1.5 4.5-1.5 8 0v5H3z"/><rect x="13" y="12.5" width="8" height="8" rx="2"/><path d="M15 18.5h4"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::TILES];
    }

    public function useCases(): array
    {
        return [
            'je sterkste punten, elk met een eigen foto',
            'categorieën of diensten die doorlinken',
            'producten of projecten als aantrekkelijke teasers',
            'acties of nieuws in opvallende kaarten',
        ];
    }

    /**
     * The heading on the grid's row and, per card, a badge, a title, a text
     * and the label of its link, per website language. None of them is
     * required: a card is its picture first, and a grid needs no heading.
     * What is the same in every language — the choices, a card's pictures
     * and its link's destination — stays in the two tables.
     */
    public function translatableFields(): array
    {
        return [
            HoverCardGridContent::TABLE => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('lead', 500),
            ],
            HoverCardGridContent::ITEMS => [
                TranslatableField::plain('badge', 60),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('body', 500),
                TranslatableField::plain('link_label', 150),
            ],
        ];
    }

    public function childTables(): array
    {
        return [HoverCardGridContent::ITEMS => ['parent' => HoverCardGridContent::TABLE, 'column' => 'hover_card_grid_id']];
    }

    /**
     * A new grid has no cards and every choice at its default: it renders
     * nothing until a card has a picture, so it can be placed first and
     * filled later. No starting words: every word of this block is optional,
     * and an optional word nobody chose would be a word on the page nobody
     * wrote.
     */
    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new HoverCardGridRepository();
        $repository->createSection($pageSlug, $key);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new HoverCardGridRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = HoverCardGridContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === HoverCardGridContent::STATE_HIDDEN) {
            return;
        }

        render_section_hover_card_grid($content, $revealGroup);
    }

    /**
     * Three overlay cards with the library's sample words and picture: one
     * with a badge and a link (its text shows when it is reached), one with
     * a text that is always in view, and one picture on its own.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();
        $picture = [
            'src' => $image['image_path'],
            'alt' => $image['alt'],
            'width' => $image['width'],
            'height' => $image['height'],
        ];
        $card = static fn (string $badge, string $title, string $body, string $label, string $href): array => [
            'image' => $picture,
            'hover_image' => null,
            'badge' => $badge,
            'title' => $title,
            'body' => $body,
            'link_label' => $label,
            'href' => $href,
        ];

        return [
            'state' => HoverCardGridContent::STATE_ACTIVE,
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
        ] + HoverCardGridContent::settings([]) + [
            'cards' => [
                $card($samples->localized('badge_title'), $samples->localizedItem('item', 0), $samples->localizedItem('item_body', 0), $samples->localized('button'), BlockSamples::LINK),
                $card('', $samples->localizedItem('item', 1), $samples->localizedItem('item_body', 1), '', ''),
                $card('', $samples->localizedItem('item', 2), '', '', ''),
            ],
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_hover_card_grid($content, $revealGroup);
    }

    /** The grid's own title, else its first card's, so two grids on one page are told apart in the page builder. */
    public function instanceTitle(array $pageSection): string
    {
        $gridId = $this->sectionId($pageSection);
        $title = BlockLocalization::name(HoverCardGridContent::TABLE, $gridId, 'title');
        if ($title !== '') {
            return $title;
        }

        foreach ((new HoverCardGridRepository())->findItemsByGridId($gridId) as $item) {
            $cardTitle = BlockLocalization::name(HoverCardGridContent::ITEMS, (int) $item['id'], 'title');
            if ($cardTitle !== '') {
                return $cardTitle;
            }
        }

        return '';
    }

    public function styles(): array
    {
        // The shared picture rules first (Responsive Media 2.0), as
        // CardCarouselBlock does.
        return ['assets/css/responsive-media.css', 'assets/css/blocks/hover-card-grid.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/hover-card-grid.js'];
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('hover-card-grid', $pageSection);
    }

    public function clearCache(): void
    {
        HoverCardGridContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return HoverCardGridContent::TABLE;
    }

    /**
     * Content: at least one card (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = HoverCardGridContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== HoverCardGridContent::STATE_ACTIVE) {
            return true;
        }

        return ($content['cards'] ?? []) !== [];
    }
}
