<?php

namespace App\Service;

use App\Repository\NavigationRepository;
use App\Service\Routing\RequestLanguage;

/**
 * Public read side of the CMS-managed header navigation — replaces
 * partials/nav-config.php. Builds the menu tree (three levels at most,
 * NavigationRepository::MAX_DEPTH) and the list of header buttons, and
 * resolves every item's link via App\Service\LinkResolver, so
 * partials/header.php only ever deals in ready-to-render hrefs.
 *
 * The menu tree is its own tree. It is never derived from the pages' own
 * nesting (pages.parent_id, docs/pages/NESTING.md): a deeply nested page can
 * be a top-level menu link, and a top-level link to a root page can have a
 * submenu of anything.
 *
 * Menu links and header buttons are the same kind of row with a different
 * presentation (App\Service\NavigationPresentation): header() reads the table
 * once and splits it. A button whose target cannot be reached right now — an
 * unpublished page, a route of a switched-off module — is left out exactly
 * like a menu link, and its row is left alone.
 *
 * LABELS are App\Service\NavigationLocalization's: each item carries its
 * label as one string in the language of the request, already resolved by
 * the one fallback, loaded for the whole header in one query. Neither this
 * class nor the partial decides a fallback.
 *
 * Static, try/catch-with-fallback, same convention as SiteSettings/
 * InformationPageContent — a navigation problem must never break every
 * public page; on any failure this returns an empty menu and no buttons
 * rather than throwing, and the site renders with just the brand/cart/
 * language controls.
 */
class NavigationService
{
    /**
     * The menu tree and the header buttons from one query.
     *
     * @return array{items: list<array<string, mixed>>, buttons: list<array{id:int,label:string,href:string,open_in_new_tab:bool,rel:?string,class:string}>}
     */
    public static function header(): array
    {
        try {
            $rows = (new NavigationRepository())->findVisibleForPublic();
            NavigationLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));
            // Every linked page and its addresses, in two queries however
            // many links there are (App\Service\LinkResolver::preloadPages()).
            LinkResolver::preloadPages($rows);
        } catch (\Throwable $e) {
            error_log('[NavigationService] falling back to an empty header: ' . $e->getMessage());
            return ['items' => [], 'buttons' => []];
        }

        return ['items' => self::buildTree($rows), 'buttons' => self::buildButtons($rows)];
    }

    /**
     * @return list<array{id:int,label:string,href:?string,open_in_new_tab:bool,rel:?string,children:list<array<string,mixed>>}>
     */
    public static function tree(): array
    {
        return self::header()['items'];
    }

    /**
     * The menu: every row presented as a link. Header buttons are skipped
     * here; buildButtons() owns them.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function buildTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            if (NavigationPresentation::isButton($row)) {
                continue;
            }
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $byParent[$parentId ?? 0][] = $row;
        }

        return self::buildLevel($byParent, 0, 1);
    }

    /**
     * One level of the menu, each item with its own submenu below it. An
     * item that does not resolve is left out together with everything under
     * it: a hidden or unreachable parent takes its submenu along, as it
     * always did. Nothing below NavigationRepository::MAX_DEPTH is read, so a
     * deeper row written into the database by hand never reaches the page.
     *
     * @param array<int, list<array<string, mixed>>> $byParent rows by parent id, 0 for the top level
     * @return list<array<string, mixed>>
     */
    private static function buildLevel(array $byParent, int $parentKey, int $level): array
    {
        $rows = $byParent[$parentKey] ?? [];
        usort($rows, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $items = [];
        foreach ($rows as $row) {
            $resolved = LinkResolver::resolve($row);
            if ($resolved === null) {
                continue;
            }

            $items[] = [
                'id' => (int) $row['id'],
                'label' => NavigationLocalization::label((int) $row['id'], RequestLanguage::current()),
                'href' => $resolved['href'],
                'open_in_new_tab' => $resolved['open_in_new_tab'],
                'rel' => $resolved['rel'],
                // Only ever set for link_type='route' — lets
                // partials/header.php mark aria-current="page" by the same
                // route key every public page already sets $activeNav to
                // (see README.md), without leaking any other row internals.
                'route_key' => $row['link_type'] === 'route' ? $row['target_route'] : null,
                'children' => $level < NavigationRepository::MAX_DEPTH
                    ? self::buildLevel($byParent, (int) $row['id'], $level + 1)
                    : [],
            ];
        }

        return $items;
    }

    /**
     * The header buttons, in their own order. A button needs somewhere to go
     * and something to say: one that resolves to no href, or that has no
     * label in the website's DEFAULT language, is not rendered — the same two
     * rules the single header CTA had, with the default language deciding
     * (a translation alone never makes a button appear). The class comes from
     * the closed variant list, never from the row.
     *
     * Only top-level rows count. The admin endpoints never store a button
     * inside a submenu; a row that somehow is one is not shown rather than
     * shown in the wrong place.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{id:int,label:string,href:string,open_in_new_tab:bool,rel:?string,class:string}>
     */
    public static function buildButtons(array $rows): array
    {
        $buttons = array_values(array_filter(
            $rows,
            static fn (array $row): bool => NavigationPresentation::isButton($row) && $row['parent_id'] === null
        ));
        usort($buttons, static fn (array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);

        $result = [];
        foreach ($buttons as $row) {
            if (!NavigationLocalization::hasDefaultLabel((int) $row['id'])) {
                continue;
            }

            $resolved = LinkResolver::resolve($row);
            if ($resolved === null || $resolved['href'] === null) {
                continue;
            }

            $result[] = [
                'id' => (int) $row['id'],
                'label' => NavigationLocalization::label((int) $row['id'], RequestLanguage::current()),
                'href' => $resolved['href'],
                'open_in_new_tab' => $resolved['open_in_new_tab'],
                'rel' => $resolved['rel'],
                'class' => NavigationPresentation::buttonClass(NavigationPresentation::variantOf($row)),
            ];
        }

        return $result;
    }

    /**
     * Is this menu item the page the visitor is on?
     *
     * Two ways, because there are two kinds of target. A ROUTE link matches
     * the route key the template sets as $activeNav, which is how every
     * public page has always said where it is. A PAGE link has no route key —
     * Diensten, Contact and every page an editor creates are page links since
     * 20260908260000 — so it matches when its href is the path being
     * requested. /index.php and / are the same page. External links never
     * match: they are, by definition, not this site's current page.
     *
     * @param array<string, mixed> $item one entry of buildTree()
     */
    public static function isCurrent(array $item, ?string $activeNav, string $requestPath): bool
    {
        $routeKey = $item['route_key'] ?? null;
        if ($routeKey !== null && $activeNav !== null && $routeKey === $activeNav) {
            return true;
        }

        $href = $item['href'] ?? null;
        if (!is_string($href) || !str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return false;
        }

        return self::normalisePath($href) === self::normalisePath($requestPath);
    }

    private static function normalisePath(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = rtrim($path, '/');

        return $path === '' || $path === '/index.php' ? '/' : $path;
    }
}
