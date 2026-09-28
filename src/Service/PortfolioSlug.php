<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PortfolioGalleryRepository;
use App\Service\Language\AdminTranslator;
use App\Service\Language\SiteLanguages;
use App\Service\Redirects\SlugChangeRedirects;

/**
 * The slug of a Portfolio item's project page: the last segment of
 * /portfolio/<slug> (MODULES.md, "Portfolio").
 *
 * ONE SLUG PER ITEM, LANGUAGE-NEUTRAL. portfolio_gallery_items.slug already
 * existed for the project page the Portfolio owned before phase 4B, with a
 * unique index, and every language answers it under its own prefix
 * (/en/portfolio/<slug>). Portfolio 2.0 edits that same column again rather
 * than adding a second one. A slug per language would need its own table and
 * its own routing (docs/multilingual/ROUTING.md, §7); that is a separate step.
 *
 * NORMALISED like a page's slug (App\Service\PageService::sanitizeSlug(): ASCII,
 * lowercase, runs of anything else become one hyphen), so a project and a page
 * named alike get the same spelling.
 *
 * UNIQUE ACROSS THE PORTFOLIO'S NAMESPACE. The address lives under the
 * module's own /portfolio/ namespace, which App\Service\ReservedRoutes already
 * keeps away from root pages, posts and the Redirect Manager. So the site's
 * reserved words do not apply here: /portfolio/contact is a project, never the
 * contact page. What a slug must not be is empty, another item's — or, since
 * Pages & Destinations 3.0, the address of a CMS page placed directly under
 * the Portfolio page, in any language (App\Service\ModuleSystemPages::
 * childPageHolding()): /portfolio/<slug> is one namespace for both, and
 * whoever saves second is refused rather than silently shadowed.
 */
final class PortfolioSlug
{
    public const MAX_LENGTH = 170;

    /** The base a title with no usable character falls back to. */
    public const FALLBACK = 'project';

    /** The typed or suggested slug in its one spelling; '' when nothing usable is left. */
    public static function normalise(string $raw): string
    {
        return substr(PageService::sanitizeSlug($raw), 0, self::MAX_LENGTH);
    }

    /**
     * A free slug made from a title: the normalised title, or "project", with
     * -2, -3 … appended until no other item has it. What the editor is
     * offered, and what a project page gets when it is switched on without a
     * slug typed.
     */
    public static function suggest(PortfolioGalleryRepository $repository, string $title, ?int $excludeId = null): string
    {
        $base = substr(self::normalise($title), 0, self::MAX_LENGTH - 10);
        $base = trim($base, '-');

        if ($base === '') {
            $base = self::FALLBACK;
        }

        $slug = $base;
        $suffix = 2;
        while ($repository->slugTakenByAnotherItem($slug, $excludeId) || self::heldByPage($slug) !== null) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * What is wrong with a normalised slug, as the message to show, or null
     * when it may be saved.
     */
    public static function problem(PortfolioGalleryRepository $repository, string $slug, ?int $excludeId = null): ?string
    {
        if ($slug === '') {
            return AdminTranslator::trans('validation.portfolio_slug_empty');
        }

        if ($repository->slugTakenByAnotherItem($slug, $excludeId)) {
            return AdminTranslator::trans('validation.portfolio_slug_taken', ['slug' => $slug]);
        }

        $page = self::heldByPage($slug);
        if ($page !== null) {
            return AdminTranslator::trans('validation.portfolio_slug_held_by_page', ['slug' => $slug, 'page' => $page['name']]);
        }

        return null;
    }

    /**
     * The CMS page directly under the Portfolio page that answers
     * /portfolio/<slug> in some language, or null.
     *
     * @return array{id: int, name: string}|null
     */
    private static function heldByPage(string $slug): ?array
    {
        return ModuleSystemPages::childPageHolding(PortfolioUrls::OVERVIEW_CONTENT_KEY, $slug);
    }

    /**
     * Whether a project page with these values is a public address: a
     * visible item, its project page switched on, and a slug to reach it by —
     * the conditions portfolio-detail.php renders a page on.
     */
    public static function isPublic(bool $isActive, bool $shown, ?string $slug): bool
    {
        return $isActive && $shown && $slug !== null && $slug !== '';
    }

    /**
     * After a save that renamed a PUBLIC project page: a 301 from the old
     * address to the new one, in every active website language, so a link or a
     * bookmark to the old /portfolio/<slug> never silently turns into a 404.
     *
     * Through App\Service\Redirects\SlugChangeRedirects, the one mechanism
     * pages and the Blog already use (REDIRECTS.md, "Wat een hernoeming
     * doet"): the same three steps, the same respect for a redirect an editor
     * wrote by hand, and an `origin` of slug_change. The slug is the same word
     * in every language, so each language gets its own pair under its own
     * prefix (/en/portfolio/old -> /en/portfolio/new); the default language
     * has none. portfolio-detail.php asks the Redirect Manager only once no
     * item answers the address (App\Service\Redirects\RedirectGate), so a
     * redirect can never shadow a live project.
     *
     * The same conditions as a page rename: the slug really changed, and the
     * page was public before AND is public after — hiding and renaming at once
     * would point the old address at a new 404. Never fails the save: a
     * redirect that could not be written is logged by SlugChangeRedirects.
     *
     * @return int how many languages got a redirect
     */
    public static function recordRename(
        bool $wasPublic,
        ?string $oldSlug,
        bool $isPublic,
        ?string $newSlug,
        ?SlugChangeRedirects $redirects = null
    ): int {
        if (!$wasPublic || !$isPublic || $oldSlug === null || $newSlug === null || $oldSlug === $newSlug) {
            return 0;
        }

        $redirects ??= new SlugChangeRedirects();
        $recorded = 0;

        foreach (SiteLanguages::activeCodes() as $language) {
            if ($redirects->record(
                ltrim(PortfolioGalleryContent::publicPath($oldSlug), '/'),
                ltrim(PortfolioGalleryContent::publicPath($newSlug), '/'),
                $language
            )) {
                $recorded++;
            }
        }

        return $recorded;
    }
}
