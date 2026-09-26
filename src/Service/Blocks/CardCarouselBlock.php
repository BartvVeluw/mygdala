<?php

namespace App\Service\Blocks;

use App\Repository\CardCarouselRepository;
use App\Service\CardCarouselContent;

require_once dirname(__DIR__, 3) . '/partials/section-card-carousel.php';

/**
 * An editor-curated carousel of cards (phase 3, replacing the auto-filled
 * services carousel): you decide which cards it holds and how many, and a
 * card without an image falls back to the fixed icon.
 *
 * Its cards reference Media Library images, which it never deletes (see
 * deleteFiles()). The words of the carousel, of every card and of every
 * card's tags are stored per website language in block_translations
 * (BlockLocalization), each on its own row's id: three levels deep.
 */
final class CardCarouselBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'card_carousel';
    }

    public function meta(): array
    {
        return [
            'label' => 'Kaarten-carrousel',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
            'note' => 'Je bepaalt zelf welke kaarten erin staan en hoeveel; een kaart zonder afbeelding toont het vaste icoon.',
        ];
    }

    public function description(): string
    {
        return 'Kaarten met beeld en tekst die de bezoeker zijwaarts doorbladert. Handig als er meer is dan er naast elkaar past.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    public function icon(): string
    {
        return '<rect x="8" y="5" width="8" height="14" rx="1.5"/><path d="M4.5 8.5v7"/><path d="M19.5 8.5v7"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::CAROUSEL];
    }

    public function useCases(): array
    {
        return [
            'een reeks diensten of projecten',
            'uitgelichte producten of referenties',
        ];
    }

    /**
     * The heading on the carousel's row, a card's words on the card's row and
     * a tag's label on the tag's row, per website language. What the editor
     * always required in Dutch is required in the default language: a card's
     * title and a tag's label; the heading is optional. The lengths are the
     * ones the editors always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'card_carousels' => [
                TranslatableField::plain('eyebrow', 255),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('lead', 1000),
            ],
            'carousel_cards' => [
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('body', 500),
                TranslatableField::plain('image_alt', 255),
                TranslatableField::plain('link_label', 150),
                // Printed above the title; empty = no label (db/migrations/20260923170000).
                TranslatableField::plain('number_label', 40),
            ],
            'carousel_card_tags' => [
                TranslatableField::plain('label', 60)->required(),
            ],
        ];
    }

    public function childTables(): array
    {
        return [
            'carousel_cards' => ['parent' => 'card_carousels', 'column' => 'carousel_id'],
            'carousel_card_tags' => ['parent' => 'carousel_cards', 'column' => 'card_id'],
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new CardCarouselRepository();
        $repository->upsertCarousel($pageSlug, $key, ['is_active' => true]);

        $id = (int) $repository->findBySlugAndKey($pageSlug, $key)['id'];

        // A generic starting title in the website's default language, the
        // language every other language falls back to until it is written.
        BlockLocalization::save('card_carousels', $id, BlockLocalization::defaultLanguage(), [
            'title' => 'Nieuwe carrousel — pas deze titel aan',
        ]);

        return [$id, $key];
    }

    /**
     * Deliberately nothing.
     *
     * This block's images are Media Library items now, and a media item is
     * SHARED: the same photo may be on three other pages and in the site's
     * branding. Removing an instance of this block removes its references
     * (deleteContent() below), never the files behind them. Deleting a file
     * is the Media Library's own decision, and it refuses while anything
     * still uses it — see App\Service\Media\MediaService::delete().
     *
     * Left as an explicit override rather than falling through to the base
     * class, so that "why does this block not clean up its images?" has an
     * answer in the file somebody would look in.
     */
    public function deleteFiles(array $pageSection): void
    {
    }

    public function deleteContent(array $pageSection): void
    {
        (new CardCarouselRepository())->deleteCarousel($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = CardCarouselContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === CardCarouselContent::STATE_HIDDEN) {
            return;
        }

        render_section_card_carousel($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        $cards = [];
        foreach (range(0, 3) as $index) {
            $cards[] = [
                'id' => 0,
                'index_label' => sprintf('%02d', $index + 1),
                'image_path' => $image['image_path'],
                'image_alt' => $image['alt'],
                'image_width' => $image['width'],
                'image_height' => $image['height'],
                'title' => $samples->localizedItem('item', $index),
                'body' => $samples->localizedItem('item_body', $index),
                'link_label' => $samples->localized('button'),
                'link_url' => BlockSamples::LINK,
                'tags' => [
                    ['label' => $samples->localizedItem('tag', 0)],
                    ['label' => $samples->localizedItem('tag', 1)],
                ],
            ];
        }

        return [
            'id' => 0,
            'desktop_layout' => CardCarouselContent::LAYOUT_ORBIT,
            'header_align' => CardCarouselContent::headerAlign(''),
            'image_height' => CardCarouselContent::imageHeight(''),
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'cards' => $cards,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_card_carousel($content);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('card_carousels', $this->sectionId($pageSection), 'title');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('card-carousel', $pageSection);
    }

    public function styles(): array
    {
        return ['assets/css/blocks/card-carousel.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/card-carousel.js'];
    }

    public function clearCache(): void
    {
        CardCarouselContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'card_carousels';
    }
}
