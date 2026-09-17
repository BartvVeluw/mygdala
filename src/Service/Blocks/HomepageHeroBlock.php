<?php

namespace App\Service\Blocks;

use App\Repository\HomepageHeroRepository;
use App\Service\HomepageHeroContent;

require_once dirname(__DIR__, 3) . '/partials/section-homepage-hero.php';

/**
 * The homepage's own, richer hero — the reason the ordinary Page Hero is
 * denied on `index`. Exactly one exists, on the homepage, and it cannot be
 * deleted, so it is addressed by page_slug alone rather than by section_key.
 * Its words, and the words of each of its stats, are stored per website
 * language in block_translations (BlockLocalization).
 */
final class HomepageHeroBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'homepage_hero';
    }

    public function meta(): array
    {
        return [
            'label' => 'Openingssectie homepage',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => ['index'],
            'deletable' => false,
        ];
    }

    public function description(): string
    {
        return 'De grote openingssectie bovenaan de homepage: een achtergrondafbeelding of -video, met daarover de hoofdtitel, een korte introductie en een knop.';
    }

    public function category(): string
    {
        return BlockCategories::HERO;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 12.5h9"/><path d="M7 16h5"/><circle cx="16.5" cy="8.5" r="1.6"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HERO];
    }

    public function useCases(): array
    {
        return [
            'de homepage openen',
            'een nieuw product of een actie uitlichten',
        ];
    }

    /**
     * The words of the Hero and of each stat, per website language; the URLs,
     * the media, the layout, the highlight size and visibility are the same
     * in every language and stay in homepage_hero / homepage_hero_stats. The
     * lengths are the ones the editor always allowed, and what is required
     * is what it always required: eyebrow, title and the primary button in
     * the text form, the alt text in the image form, both texts of a stat.
     */
    public function translatableFields(): array
    {
        return [
            'homepage_hero' => [
                TranslatableField::plain('eyebrow', 150)->required(),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('title_highlight', 255),
                TranslatableField::plain('lead', 500),
                TranslatableField::plain('primary_label', 150)->required(),
                TranslatableField::plain('secondary_label', 150),
                TranslatableField::plain('image_alt', 255)->required(),
                TranslatableField::plain('badge_title', 150),
                TranslatableField::plain('badge_text', 500),
            ],
            'homepage_hero_stats' => [
                TranslatableField::plain('primary_text', 100)->required(),
                TranslatableField::plain('secondary_text', 150)->required(),
            ],
        ];
    }

    public function childTables(): array
    {
        return ['homepage_hero_stats' => ['parent' => 'homepage_hero', 'column' => 'homepage_hero_id']];
    }

    public function create(string $pageSlug): array
    {
        // Not offered by availableForPage() once attached (max one, and the
        // backfill migration always attaches it) — this only matters for a
        // from-scratch install that somehow reaches "add section" before the
        // Hero exists.
        $repository = new HomepageHeroRepository();
        $row = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);

        if ($row === null) {
            $repository->upsert(HomepageHeroContent::PAGE_SLUG, HomepageHeroContent::startingValues() + ['is_active' => true]);
            $row = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);

            // Generic starting words in the website's default language, the
            // language every other language falls back to until it is written.
            BlockLocalization::save('homepage_hero', (int) $row['id'], BlockLocalization::defaultLanguage(), HomepageHeroContent::startingWords());
        }

        return [(int) $row['id'], null];
    }

    public function deleteContent(array $pageSection): void
    {
        throw new \RuntimeException('The homepage hero cannot be deleted.');
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = HomepageHeroContent::current();
        if ($content['state'] !== HomepageHeroContent::STATE_ACTIVE) {
            return;
        }

        render_section_homepage_hero($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        $stats = [];
        foreach (range(0, 2) as $index) {
            $stats[] = [
                'primary_text' => $samples->localizedItem('figure', $index),
                'secondary_text' => $samples->localizedItem('figure_caption', $index),
            ];
        }

        return [
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'title_highlight' => $samples->localized('title_highlight'),
            'title_highlight_size' => HomepageHeroContent::HIGHLIGHT_SIZE_DEFAULT,
            'lead' => $samples->localized('lead'),
            'primary_label' => $samples->localized('button'),
            'primary_url' => BlockSamples::LINK,
            'secondary_label' => $samples->localized('button_secondary'),
            'secondary_url' => BlockSamples::LINK,
            'image_path' => $image['image_path'],
            'image_alt' => $image['alt'],
            'badge_title' => $samples->localized('badge_title'),
            'badge_text' => $samples->localized('badge_text'),
            'media_type' => HomepageHeroContent::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => HomepageHeroContent::LAYOUT_MEDIA_RIGHT,
            'stats' => $stats,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_homepage_hero($content);
    }

    /**
     * The hero owns its own look and its own entrance animation, and it is
     * the ONLY thing on this site that needs GSAP. Before step 4 that library
     * was a hand-written <script> tag in twelve page templates, eleven of
     * which never render a hero.
     */
    public function styles(): array
    {
        return ['assets/css/blocks/homepage-hero.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/homepage-hero.js'];
    }

    public function vendorScripts(): array
    {
        return ['gsap'];
    }

    public function tightensFollowingBlock(): bool
    {
        return true;
    }

    public function editUrl(array $pageSection): ?string
    {
        return '/admin/homepage-hero.php';
    }

    public function clearCache(): void
    {
        HomepageHeroContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'homepage_hero';
    }
}
