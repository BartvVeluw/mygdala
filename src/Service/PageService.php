<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Language\AdminTranslator;
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
            return self::reservedSlugMessage($slug);
        }

        if (self::slugIsTaken($repository, $slug, $languageCode, $excludeId)) {
            return 'Deze slug is al in gebruik door een andere pagina.';
        }

        return null;
    }

    /**
     * Why a reserved word cannot be a page's address, in words an editor can
     * act on: which module owns it, when a module does (the Portfolio owns
     * `portfolio`, also while it is off), else that the website itself uses
     * it. Before this an editor was only told "gereserveerd", or, for an
     * address made from the title, silently handed "portfolio-2".
     */
    public static function reservedSlugMessage(string $slug): string
    {
        $module = ReservedRoutes::moduleReserving($slug);

        return $module !== null
            ? AdminTranslator::trans('validation.slug_reserved_by_module', ['slug' => $slug, 'module' => $module->label()])
            : AdminTranslator::trans('validation.slug_reserved_by_site', ['slug' => $slug]);
    }

    /**
     * What an editor is told after generateSlug() had to move away from the
     * title's own address because that word is reserved — "Portfolio" became
     * /portfolio-2 — or null when the address is simply the title's. Said on
     * the new page's screen (api/admin/create-page.php), so the -2 is never
     * a mystery.
     */
    public static function generatedSlugNotice(string $title, string $generated): ?string
    {
        $base = substr(self::sanitizeSlug($title), 0, self::MAX_SLUG_LENGTH - 10);

        if ($base === '' || $base === $generated || !ReservedPaths::isReserved($base)) {
            return null;
        }

        return AdminTranslator::trans('page.slug_reserved_notice', [
            'reason' => self::reservedSlugMessage($base),
            'slug' => $generated,
        ]);
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
     * May this page sit under $parentId? Returns the message to show, or null
     * when the move is allowed (Pagina's 2.0, docs/pages/NESTING.md).
     *
     * The editor's parent list already leaves out every choice refused here —
     * the page itself, everything below it, every page with a fixed URL — so
     * this is the same rule again for a request that did not come from that
     * list: a forged or scripted POST can no more make a loop than a click
     * can.
     *
     *     A -> B -> C      C can never become A's parent, nor can B or A
     *
     * $page is the stored row, or null for a page that is being created (it
     * has no descendants yet and cannot be its own parent). A null $parentId
     * is a root page and always allowed.
     *
     * @param array<string, mixed>|null $page
     */
    public static function validateParent(?array $page, ?int $parentId): ?string
    {
        if ($parentId === null) {
            return null;
        }

        $pageId = $page === null ? 0 : (int) ($page['id'] ?? 0);

        if ($page !== null && PageContent::isRouteBound($page)) {
            return 'Deze pagina heeft een vast webadres en staat daarom altijd op het hoogste niveau.';
        }

        if ($pageId > 0 && $parentId === $pageId) {
            return 'Een pagina kan niet onder zichzelf staan.';
        }

        $parent = PagePath::node($parentId);

        if ($parent === null) {
            return 'De gekozen bovenliggende pagina bestaat niet (meer).';
        }

        if (PageContent::isRouteBound($parent)) {
            return "Onder een pagina met een vast webadres kunnen geen andere pagina's staan.";
        }

        if ($pageId > 0 && in_array($parentId, PagePath::descendantIds($pageId), true)) {
            return "Een pagina kan niet onder een van haar eigen onderliggende pagina's staan.";
        }

        $chain = PagePath::ancestorIds($parentId);

        if ($chain === null) {
            return 'De gekozen bovenliggende pagina heeft geen geldig pad.';
        }

        // The parent's own depth, the page itself, and everything that moves
        // along with it.
        $depth = count($chain) + 1 + 1 + ($pageId > 0 ? PagePath::subtreeHeight($pageId) : 0);

        if ($depth > PagePath::MAX_DEPTH) {
            return "Zo diep kunnen pagina's niet genest worden (hoogstens " . PagePath::MAX_DEPTH . ' niveaus).';
        }

        return null;
    }

    /**
     * Every page that may be offered as the parent of $page (null: a page
     * being created), for the editor's list: not the page itself, nothing
     * below it, and no page with a fixed URL. validateParent() refuses exactly
     * the same set, and a little more — a choice that would nest too deep.
     *
     * @param array<string, mixed>|null $page
     * @return list<int> page ids
     */
    public static function parentCandidates(?array $page): array
    {
        if ($page !== null && PageContent::isRouteBound($page)) {
            return [];
        }

        $pageId = $page === null ? 0 : (int) ($page['id'] ?? 0);
        $excluded = $pageId > 0 ? array_flip([$pageId, ...PagePath::descendantIds($pageId)]) : [];

        $candidates = [];
        foreach (PagePath::nodes() as $id => $node) {
            if (isset($excluded[$id]) || PageContent::isRouteBound($node)) {
                continue;
            }

            if (self::validateParent($page, (int) $id) === null) {
                $candidates[] = (int) $id;
            }
        }

        return $candidates;
    }

    /**
     * The admin group a page gets when it is saved under $parentId: its new
     * tree's (App\Service\PageAdminGroup). Only a root page chooses, and
     * then $requested counts — an unknown value keeps what it had.
     */
    public static function resolveAdminGroup(?int $parentId, string $requested, string $current): string
    {
        if ($parentId !== null) {
            return PagePath::effectiveGroup($parentId);
        }

        return PageAdminGroup::isValid($requested) ? $requested : PageAdminGroup::normalise($current);
    }

    /**
     * The redirects a save owes, from the paths before it and after it
     * (App\Service\PagePath::snapshot()): one per page and language whose
     * path really changed INTO another path, for a page that answered at its
     * old path and still answers at its new one.
     *
     * $redirectable says per page whether that last condition holds — for the
     * page being saved oldAddressWillRedirect(), for a page below it simply
     * whether it is published, because its own status does not change. A
     * language whose path went away (a slug cleared, an ancestor without an
     * address there) is no move: the old URL 404s like any gone page's.
     *
     * @param array<int, array<string, string|null>> $before
     * @param array<int, array<string, string|null>> $after
     * @param array<int, bool>                       $redirectable
     * @return list<array{from: string, to: string}> prefixed site-relative paths
     */
    public static function pathMoves(array $before, array $after, array $redirectable): array
    {
        $moves = [];

        foreach ($before as $pageId => $paths) {
            if (empty($redirectable[$pageId])) {
                continue;
            }

            foreach ($paths as $language => $old) {
                $new = $after[$pageId][$language] ?? null;

                if ($old === null || $new === null || $old === $new) {
                    continue;
                }

                $moves[] = [
                    'from' => \App\Service\Routing\LocalizedUrl::path($old, (string) $language),
                    'to' => \App\Service\Routing\LocalizedUrl::path($new, (string) $language),
                ];
            }
        }

        return $moves;
    }

    /**
     * Must the editor confirm moving this page under another parent?
     *
     * The parent's counterpart of urlChangeNeedsConfirmation(): a new parent
     * moves the page's address in every language, and the address of every
     * page below it. Asked when the parent really changes, for a page that is
     * not on a fixed URL, and only until the editor has confirmed exactly this
     * parent ($confirmedParent: the id they were shown, '0' for "no parent",
     * '' when nothing was confirmed).
     *
     * @param array<string, mixed> $page the stored `pages` row
     */
    public static function parentChangeNeedsConfirmation(array $page, ?int $newParentId, string $confirmedParent): bool
    {
        if (PageContent::isRouteBound($page)) {
            return false;
        }

        $current = (int) ($page['parent_id'] ?? 0);
        $new = $newParentId ?? 0;

        return $new !== $current && $confirmedParent !== (string) $new;
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
     * root, or a page carrying application-critical functionality), when
     * other pages sit under it (docs/pages/NESTING.md: never a cascade of a
     * whole subtree, never children that silently become root pages), or when
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

        // A page with pages under it is never deleted from under them: they
        // would lose their parent and their address with it. The database
        // refuses it too (pages.parent_id, ON DELETE RESTRICT); this is the
        // sentence the editor gets instead of an error.
        if ((new PageRepository())->countChildren($pageId) > 0) {
            throw new \RuntimeException(
                "Deze pagina heeft onderliggende pagina's. Verplaats of verwijder eerst de onderliggende pagina's en probeer het daarna opnieuw."
            );
        }

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
