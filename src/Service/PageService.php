<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Routing\ReservedPaths;

/**
 * The write-side rules of the unified CMS page model: slug normalisation and
 * validation, content-key generation, system-page protection, and safe
 * deletion. App\Repository\PageRepository is deliberately dumb SQL (this
 * project's repository convention) — every rule that decides whether a
 * write is allowed lives here, and both admin endpoints
 * (api/admin/create-page.php, update-page.php, delete-page.php) go through
 * it.
 *
 * Slug handling mirrors the pattern the information pages already used (and
 * Portfolio items before them): sanitize what the admin typed, only ever
 * auto-generate when the field was left blank, and never silently
 * regenerate an existing page's slug from a changed title — a published URL
 * changes only when the administrator explicitly saves a different value.
 */
class PageService
{
    public const MAX_SLUG_LENGTH = 170;
    public const MAX_TITLE_LENGTH = 200;
    public const MAX_META_TITLE_LENGTH = 255;
    public const MAX_META_DESCRIPTION_LENGTH = 500;

    /**
     * Normalises an admin-submitted slug: lowercase, ASCII-transliterated,
     * runs of non-[a-z0-9] collapsed to a single "-", trimmed of leading/
     * trailing "-". Returns '' for empty/unsalvageable input so the caller
     * can fall back to generateSlug(). Same implementation the information
     * pages used, so no existing slug would normalise differently now.
     */
    public static function sanitizeSlug(string $rawSlug): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $rawSlug) : $rawSlug;
        $slug = strtolower((string) ($ascii !== false ? $ascii : $rawSlug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return substr($slug, 0, self::MAX_SLUG_LENGTH);
    }

    /**
     * A URL-safe slug derived from the title, made unique against existing
     * pages AND against App\Service\ReservedRoutes — only used when the
     * admin left the slug field blank while creating a page.
     */
    public static function generateSlug(
        PageRepository $repository,
        string $title,
        string $languageCode,
        ?int $excludeId = null
    ): string {
        $base = self::sanitizeSlug($title);

        if ($base === '') {
            $base = 'pagina';
        }

        $base = substr($base, 0, self::MAX_SLUG_LENGTH - 10);

        $slug = $base;
        $suffix = 2;
        while (self::slugIsTaken($repository, $slug, $languageCode, $excludeId) || ReservedPaths::isReserved($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Validates a sanitized, non-empty slug an admin typed by hand — the two
     * rules generateSlug() enforces automatically. Returns the message to
     * show, or null when the slug is fine.
     */
    public static function validateSlug(
        PageRepository $repository,
        string $slug,
        ?int $excludeId,
        string $languageCode
    ): ?string {
        if (ReservedPaths::isReserved($slug)) {
            return 'Deze slug is gereserveerd voor een bestaande pagina/route van de website en kan niet worden gebruikt.';
        }

        if (self::slugIsTaken($repository, $slug, $languageCode, $excludeId)) {
            return 'Deze slug is al in gebruik door een andere pagina.';
        }

        return null;
    }

    /**
     * Is this address already another page's, IN THIS LANGUAGE?
     *
     * Two questions since Multilingual 2.0 phase 6, and both have to be asked:
     *
     *   - `page_translations` holds the address of every language, so that is
     *     where a clash between two pages' Dutch slugs, or two pages' English
     *     ones, shows up. Dutch and English may share a word — /over-ons and
     *     /en/over-ons are different URLs — so the check is scoped to one
     *     language and never across them;
     *   - `pages.slug` is still the page's neutral key, kept in step with the
     *     DEFAULT language's address (docs/multilingual/ROUTING.md). It is
     *     unique for its own reasons — stored redirects and `content_key`
     *     were derived from it — so a default-language slug has to clear that
     *     column too, even while a row exists in both places.
     *
     * A lookup that fails counts as TAKEN. Refusing a save the editor can
     * retry is the safe direction; handing out an address that turns out to
     * be somebody else's is not.
     */
    private static function slugIsTaken(
        PageRepository $repository,
        string $slug,
        string $languageCode,
        ?int $excludeId
    ): bool {
        try {
            if (PageLocalization::slugExists($slug, $languageCode, $excludeId)) {
                return true;
            }
        } catch (\Throwable $e) {
            error_log('[PageService] localized slug check failed for "' . $slug . '": ' . $e->getMessage());

            return true;
        }

        if ($languageCode !== PageLocalization::defaultLanguage()) {
            return false;
        }

        return $repository->slugExists($slug, $excludeId);
    }

    /**
     * A fresh, unique content_key for a brand-new page. It starts out equal
     * to the initial slug (readable in the database and in every section
     * table's page_slug column), but never changes afterwards, so it stays
     * correct even once the public slug is renamed — see
     * db/migrations/20260908100000_create_pages_table.php.
     *
     * Uniqueness is checked separately from the slug because a content_key
     * can outlive the slug it was derived from: page A can be created as
     * "garantie" (content_key "garantie"), renamed to "waarborg", and a new
     * page can then legitimately claim the slug "garantie" — which must get
     * its own, different content_key.
     */
    public static function generateContentKey(PageRepository $repository, string $slug): string
    {
        $base = $slug !== '' ? $slug : 'pagina';

        if (!$repository->contentKeyExists($base)) {
            return $base;
        }

        $suffix = 2;
        while ($repository->contentKeyExists($base . '-' . $suffix)) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /**
     * How many navigation items and footer links currently point at this
     * page. A page with any reference cannot be deleted (see delete()) — the
     * admin is told to unlink it first, instead of the CMS silently leaving
     * a hole in the menu.
     *
     * @return array{nav: int, footer: int, total: int}
     */
    public static function references(int $pageId): array
    {
        $nav = (new NavigationRepository())->countByTargetPageId($pageId);
        $footer = (new FooterRepository())->countLinksByTargetPageId($pageId);

        return ['nav' => $nav, 'footer' => $footer, 'total' => $nav + $footer];
    }

    /**
     * A human-readable Dutch summary of references(), for the admin UI.
     */
    public static function describeReferences(array $references): string
    {
        $parts = [];
        if ($references['nav'] > 0) {
            $parts[] = $references['nav'] . ' navigatie-item' . ($references['nav'] === 1 ? '' : 's');
        }
        if ($references['footer'] > 0) {
            $parts[] = $references['footer'] . ' footerlink' . ($references['footer'] === 1 ? '' : 's');
        }

        return implode(' en ', $parts);
    }

    /**
     * Must the editor confirm this save before it is written?
     *
     * Yes exactly when it moves the page to a web address the editor has not
     * confirmed yet. $confirmedSlug is what the confirmation card on
     * admin/page.php carries: the address the editor was shown and agreed
     * to. A new address that differs from it — typed after confirming, or
     * never shown at all — is asked about again. A draft asks too: no
     * redirect is at stake there, but the editor still learns what the change
     * means before it happens. A page served at a fixed URL never moves, so
     * it never asks.
     *
     * Only the decision lives here; api/admin/update-page.php asks it before
     * it writes anything. See REDIRECTS.md.
     *
     * @param array<string, mixed> $page    the stored `pages` row
     * @param string               $newSlug the sanitized slug this save would write
     */
    public static function urlChangeNeedsConfirmation(
        array $page,
        string $newSlug,
        string $confirmedSlug,
        string $languageCode
    ): bool {
        if (PageContent::isRouteBound($page)) {
            return false;
        }

        // The address that is moving is THIS LANGUAGE's, not the page's
        // neutral key: renaming the English version leaves /over-ons exactly
        // where it is, and the editor must be asked about the URL they are
        // actually changing. A language that has no address yet is not moving
        // anything, so giving it one asks nothing.
        $current = self::currentSlug($page, $languageCode);

        if ($current === null) {
            return false;
        }

        return $newSlug !== $current && $newSlug !== $confirmedSlug;
    }

    /**
     * The address this page has in one language right now, or null when it
     * has none there.
     *
     * @param array<string, mixed> $page
     */
    public static function currentSlug(array $page, string $languageCode): ?string
    {
        return PageContent::localizedSlug($page, $languageCode);
    }

    /**
     * Will the page's current address keep working after a save that changes
     * its slug, through an automatic redirect?
     *
     * The three conditions under which api/admin/update-page.php hands a
     * changed slug to App\Service\Redirects\SlugChangeRedirects, in one place:
     * the endpoint asks before it records one, and admin/page.php asks before
     * it tells the editor what confirming a new address will do, so the two
     * can never say different things.
     *
     *   - the page has no fixed URL: a route-bound page's address never moves;
     *   - it WAS published: a draft's address was never a working URL, so
     *     there is nothing to keep — which is also why a brand-new page,
     *     created as a draft, never produces one;
     *   - it STAYS published: renaming while taking the page offline would
     *     point the old address at a new one that answers 404.
     *
     * Whether the slug changed at all is the caller's first question, not
     * this method's.
     *
     * @param array<string, mixed> $page      the stored `pages` row, before the save
     * @param string               $newStatus the status the save writes
     */
    public static function oldAddressWillRedirect(array $page, string $newStatus): bool
    {
        return !PageContent::isRouteBound($page)
            && PageContent::isPublished($page)
            && $newStatus === PageContent::STATUS_PUBLISHED;
    }

    /**
     * Permanently deletes a content page: every section attached to it
     * (including that section's own content row, child rows and uploaded
     * media, via App\Service\SectionRegistry::delete()), then the page row
     * itself.
     *
     * Refuses — with a message meant to be shown to the admin — when the
     * page is protected (App\Service\PageContent::isProtected(): the site
     * root, or a page carrying application-critical functionality), or when
     * navigation/footer links still point at it. Blocking rather than
     * cascading is deliberate: silently
     * removing someone's menu items as a side effect of deleting a page is
     * exactly the kind of invisible data loss this project avoids elsewhere
     * too (see NavigationRepository::delete()'s "item with children" refusal).
     *
     * Ordering note: sections are removed first and the page row last, so a
     * failure partway through leaves a still-consistent (if partly emptied)
     * page rather than orphaned section content. Each SectionRegistry::delete()
     * runs in its own transaction — one outer transaction around all of them
     * is not possible here, because MySQL/PDO has no nested transactions and
     * SectionRegistry::delete() (used identically by the page builder's own
     * per-section delete) opens its own.
     *
     * @param array<string, mixed> $page a `pages` row
     *
     * @throws \RuntimeException when the page must not be deleted
     */
    public static function delete(array $page): void
    {
        if (PageContent::isProtected($page)) {
            throw new \RuntimeException(
                PageContent::isSiteRoot($page)
                    ? 'De homepage is het startpunt van de website en kan niet worden verwijderd.'
                    : 'Deze pagina bevat functionaliteit waar de webshop van afhankelijk is en kan niet worden verwijderd.'
            );
        }

        $pageId = (int) $page['id'];

        $references = self::references($pageId);
        if ($references['total'] > 0) {
            throw new \RuntimeException(
                'Deze pagina wordt nog gebruikt door ' . self::describeReferences($references)
                . '. Verwijder of wijzig die link(s) eerst en probeer het daarna opnieuw.'
            );
        }

        $sectionRepository = new PageSectionRepository();

        foreach ($sectionRepository->findForPage($pageId) as $pageSection) {
            $type = (string) $pageSection['section_type'];

            if (SectionRegistry::isDeletable($type)) {
                SectionRegistry::delete($pageSection, $sectionRepository);
                continue;
            }

            // A non-deletable type is either the Homepage Hero (which lives
            // exclusively on the undeletable homepage) or a fixed block whose
            // content is owned elsewhere entirely — the Diensten material
            // sections, the portfolio gallery, the quote form. Detaching is
            // the right move for both: the page position disappears with the
            // page, the content behind it stays where it is managed, and a
            // non-deletable type can never make a page permanently
            // undeletable.
            $sectionRepository->delete((int) $pageSection['id']);
        }

        (new PageRepository())->delete($pageId);

        PageContent::clearCache();
    }
}
