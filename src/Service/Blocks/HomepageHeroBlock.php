<?php

namespace App\Service\Blocks;

use App\Repository\HomepageHeroRepository;
use App\Service\HomepageHeroContent;

require_once dirname(__DIR__, 3) . '/partials/section-homepage-hero.php';

/**
 * The homepage's own, richer hero — the reason the ordinary Page Hero is
 * denied on `index`. Exactly one exists, on the homepage, and it cannot be
 * deleted, so it is addressed by page_slug alone rather than by section_key.
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

    public function create(string $pageSlug): array
    {
        // Not offered by availableForPage() once attached (max one, and the
        // backfill migration always attaches it) — this only matters for a
        // from-scratch install that somehow reaches "add section" before the
        // Hero exists.
        $repository = new HomepageHeroRepository();

        if ($repository->findBySlug(HomepageHeroContent::PAGE_SLUG) === null) {
            $repository->upsert(HomepageHeroContent::PAGE_SLUG, HomepageHeroContent::startingValues() + ['is_active' => true]);
        }

        $row = $repository->findBySlug(HomepageHeroContent::PAGE_SLUG);

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
