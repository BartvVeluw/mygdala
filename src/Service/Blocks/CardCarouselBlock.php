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
 * Its cards carry uploaded images, so it cleans those up before its rows
 * disappear.
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

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new CardCarouselRepository();
        $repository->upsertCarousel($pageSlug, $key, [
            'eyebrow_nl' => '',
            'eyebrow_en' => '',
            'title_nl' => 'Nieuwe carrousel — pas deze titel aan',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
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

    public function instanceTitle(array $pageSection): string
    {
        return (string) (CardCarouselContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
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
