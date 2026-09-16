<?php

namespace App\Service;

use App\Repository\PageRepository;

/**
 * Single shared link-target resolver for both nav_items and footer_links —
 * neither App\Service\NavigationService nor FooterService nor their
 * templates independently build a URL from a row's link_type; they all call
 * here. See db/migrations/20260907210000_create_nav_items_table.php for the
 * full link_type rationale.
 *
 * resolve() takes a trusted, already-validated database row (never raw
 * request input — LINK_TYPES/ALLOWED_ACTIONS are also used at write time by
 * the admin API endpoints to reject an invalid link_type/action_key before
 * it ever reaches the database) and returns either a renderable link (a
 * real href) or an action (a controlled behaviour, currently only opening
 * the cookie-preferences modal — never arbitrary JavaScript from the CMS),
 * or null when the row is currently unresolvable (e.g. it points at a page
 * that was unpublished/deleted since). A null result means "render this
 * item as if it didn't exist" — callers must skip it rather than emit a
 * dead link, same fallback philosophy as SiteSettings/PageContent.
 */
class LinkResolver
{
    public const LINK_TYPES_NAV = ['page', 'route', 'external', 'none'];
    public const LINK_TYPES_FOOTER = ['page', 'route', 'external', 'action'];

    public const ALLOWED_ACTIONS = ['cookie_preferences'];

    /**
     * @param array<string, mixed> $row must contain 'link_type' and,
     *                                   depending on it, one of
     *                                   target_page_id/target_route/
     *                                   external_url/action_key
     *
     * @return array{href: ?string, open_in_new_tab: bool, rel: ?string, is_action: bool, action_key: ?string}|null
     */
    public static function resolve(array $row, ?PageRepository $pageRepository = null): ?array
    {
        $linkType = (string) ($row['link_type'] ?? '');
        $openInNewTab = !empty($row['open_in_new_tab']);

        switch ($linkType) {
            case 'none':
                return ['href' => null, 'open_in_new_tab' => false, 'rel' => null, 'is_action' => false, 'action_key' => null];

            case 'page':
                $pageId = $row['target_page_id'] ?? null;
                if ($pageId === null) {
                    return null;
                }
                $pageRepository ??= new PageRepository();
                try {
                    $page = $pageRepository->findByIdPublished((int) $pageId);
                } catch (\Throwable $e) {
                    error_log('[LinkResolver] page lookup failed: ' . $e->getMessage());
                    return null;
                }
                if ($page === null) {
                    return null;
                }
                // A page served from a MODULE's own template — /shop.php —
                // still has its row and is still editable while that module
                // is switched off, but its URL answers 404. Linking to it
                // would send visitors there, so it behaves exactly like a
                // route key that is no longer registered: the link is
                // dropped, the stored row is untouched, and switching the
                // module back on brings the link back.
                if (!PageContent::isServedByAnEnabledModule($page)) {
                    return null;
                }

                return [
                    // PageContent::publicUrl() handles both kinds of page:
                    // a dynamic page's current /<slug>, and a system page's
                    // fixed route ('/', '/shop.php', ...). Resolving it here,
                    // per render, is what makes a later slug change follow
                    // through to every nav/footer link automatically.
                    'href' => PageContent::publicUrl($page),
                    'open_in_new_tab' => $openInNewTab,
                    'rel' => $openInNewTab ? 'noopener noreferrer' : null,
                    'is_action' => false,
                    'action_key' => null,
                ];

            case 'route':
                $routeKey = (string) ($row['target_route'] ?? '');
                $url = RouteRegistry::url($routeKey);
                if ($url === null) {
                    return null;
                }

                return [
                    'href' => $url,
                    'open_in_new_tab' => $openInNewTab,
                    'rel' => $openInNewTab ? 'noopener noreferrer' : null,
                    'is_action' => false,
                    'action_key' => null,
                ];

            case 'external':
                $url = trim((string) ($row['external_url'] ?? ''));
                if ($url === '') {
                    return null;
                }

                return [
                    'href' => $url,
                    'open_in_new_tab' => $openInNewTab,
                    'rel' => $openInNewTab ? 'noopener noreferrer' : null,
                    'is_action' => false,
                    'action_key' => null,
                ];

            case 'action':
                $actionKey = (string) ($row['action_key'] ?? '');
                if (!in_array($actionKey, self::ALLOWED_ACTIONS, true)) {
                    return null;
                }

                return ['href' => null, 'open_in_new_tab' => false, 'rel' => null, 'is_action' => true, 'action_key' => $actionKey];

            default:
                return null;
        }
    }

    /**
     * Server-side validation of a submitted link_type + its companion
     * field, shared by the nav-item and footer-link admin endpoints.
     * Returns an error message, or null when valid.
     *
     * @param list<string> $allowedTypes
     */
    public static function validate(
        string $linkType,
        ?int $targetPageId,
        ?string $targetRoute,
        ?string $externalUrl,
        ?string $actionKey,
        array $allowedTypes,
        PageRepository $pageRepository
    ): ?string {
        // Editor-facing Dutch without field names: the header and footer
        // editors call these "a page of this website", "a fixed part of the
        // website" and "another address" (HEADER-FOOTER.md).
        if (!in_array($linkType, $allowedTypes, true)) {
            return 'Kies waar de link heen gaat.';
        }

        switch ($linkType) {
            case 'page':
                if ($targetPageId === null || $pageRepository->findById($targetPageId) === null) {
                    return 'Kies een bestaande pagina.';
                }
                break;
            case 'route':
                if ($targetRoute === null || !RouteRegistry::exists($targetRoute)) {
                    return 'Kies een onderdeel van de website.';
                }
                break;
            case 'external':
                if ($externalUrl === null || $externalUrl === '' || !self::isValidUrl($externalUrl)) {
                    return 'Vul een volledig adres in dat begint met https://, of een adres op deze website dat begint met /.';
                }
                break;
            case 'action':
                if ($actionKey === null || !in_array($actionKey, self::ALLOWED_ACTIONS, true)) {
                    return 'Ongeldige actie.';
                }
                break;
            case 'none':
                break;
        }

        return null;
    }

    /**
     * Accepts an absolute http(s) URL or a root-relative site path (e.g.
     * "/diensten.php#hout") — the latter is how internal anchor links are
     * stored (see the Materialen footer column backfill), since those
     * aren't a registered route or CMS page of their own.
     */
    public static function isValidUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        $filtered = filter_var($url, FILTER_VALIDATE_URL);

        return $filtered !== false && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'));
    }
}
