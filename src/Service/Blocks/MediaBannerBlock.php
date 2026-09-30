<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\MediaBannerRepository;
use App\Service\Media\MediaSequence;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\MediaBannerContent;

require_once dirname(__DIR__, 3) . '/partials/section-media-banner.php';

/**
 * Mediabanner: one picture or one video from the Media Library as a section of
 * its own — or several, one after the other (App\Service\Media\MediaSequence)
 * — inside the container or across the whole page, in one of four heights
 * (CONTENT-BLOCKS.md, "Mediabanner"). No words, so nothing of it is in
 * block_translations: the picture's alt text is the library's, and every
 * setting is the same in every language.
 *
 * Whether it shows a picture or a video is the chosen library item's own
 * kind, never a setting of the block (App\Service\MediaBannerContent).
 */
final class MediaBannerBlock extends BlockDefinition implements InspectsContent
{
    public function type(): string
    {
        return 'media_banner';
    }

    public function meta(): array
    {
        return [
            'label' => 'Mediabanner',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Een grote afbeelding of video uit de mediabibliotheek, of meerdere na elkaar, over de breedte van de inhoud of van de hele pagina, in een hoogte die je kiest. Zonder tekst erover.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    public function icon(): string
    {
        return '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 16l5-4.5 4 3.5 3-2.5 4.5 4"/><circle cx="16.5" cy="9.5" r="1.4"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::MEDIA];
    }

    public function useCases(): array
    {
        return [
            'een grote sfeerfoto tussen twee tekstblokken',
            'een korte video van het werk of het product',
            'een brede afbeelding als rustpunt op de pagina',
        ];
    }

    public function translatableFields(): array
    {
        return [];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new MediaBannerRepository();
        $repository->createSection($pageSlug, $key);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new MediaBannerRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = MediaBannerContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === MediaBannerContent::STATE_HIDDEN) {
            return;
        }

        render_section_media_banner($content, $tightTop);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        return [
            'state' => MediaBannerContent::STATE_ACTIVE,
            'kind' => MediaType::IMAGE,
            'src' => $image['image_path'],
            'mime' => '',
            'alt' => $image['alt'],
            'intrinsic_width' => $image['width'],
            'intrinsic_height' => $image['height'],
            'poster' => '',
            'width' => MediaBannerContent::DEFAULT_WIDTH,
            'height' => MediaBannerContent::DEFAULT_HEIGHT,
            // No presentation of its own: the partial prints the sample
            // picture plainly, the middle and cover.
            'presentation' => new \App\Service\Media\ResponsiveImage(),
            'autoplay' => false,
            'loop' => false,
            'controls' => false,
            'items' => [],
            'transition' => MediaSequence::DEFAULT_TRANSITION,
            'duration' => MediaSequence::DEFAULT_DURATION,
            'nav' => MediaSequence::DEFAULT_CONTROLS,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_media_banner($content);
    }

    /** The chosen item's name, so two banners on one page are told apart in the page builder. */
    public function instanceTitle(array $pageSection): string
    {
        $row = (new MediaBannerRepository())->findById($this->sectionId($pageSection));
        $item = MediaService::find($row === null ? null : (int) ($row['media_id'] ?? 0));

        return $item === null ? '' : $item->displayName();
    }

    /** The banner's own sizes, and the media sequence it shares with the Paginakop. */
    public function styles(): array
    {
        return ['assets/css/responsive-media.css', 'assets/css/media-sequence.css', 'assets/css/blocks/media-banner.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/media-sequence.js', 'assets/js/blocks/media-banner.js'];
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('media-banner', $pageSection);
    }

    public function clearCache(): void
    {
        MediaBannerContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'media_banners';
    }

    /**
     * Content: a picture or a video; its words alone show nothing (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = MediaBannerContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== MediaBannerContent::STATE_ACTIVE) {
            return true;
        }

        return in_array((string) ($content['kind'] ?? ''), [MediaType::IMAGE, MediaType::VIDEO], true) && (string) ($content['src'] ?? '') !== '';
    }

    /**
     * Background, lines and room, no effect: the picture or video is the
     * block, and an effect would only sit behind it.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::only(true, true, true);
    }
}
