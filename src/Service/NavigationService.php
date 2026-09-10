<?php

namespace App\Service;

use App\Repository\NavigationRepository;

/**
 * Public read side of the CMS-managed main navigation — replaces
 * partials/nav-config.php. Builds the 2-level tree (top-level items with
 * their direct children only; see the nav_items migration for why depth is
 * capped at 2) and resolves every item's link via App\Service\LinkResolver,
 * so partials/header.php only ever deals in ready-to-render hrefs.
 *
 * Static, try/catch-with-fallback, same convention as SiteSettings/
 * InformationPageContent — a navigation problem must never break every
 * public page; on any failure this returns an empty tree rather than
 * throwing, and the site renders with just the brand/cart/language controls.
 */
class NavigationService
{
    /**
     * @return list<array{id:int,label_nl:string,label_en:string,href:?string,open_in_new_tab:bool,rel:?string,children:list<array<string,mixed>>}>
     */
    public static function tree(): array
    {
        try {
            $rows = (new NavigationRepository())->findVisibleForPublic();
        } catch (\Throwable $e) {
            error_log('[NavigationService] falling back to empty menu: ' . $e->getMessage());
            return [];
        }

        return self::buildTree($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function buildTree(array $rows): array
    {
        $byParent = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $byParent[$parentId ?? 0][] = $row;
        }

        $topLevel = $byParent[0] ?? [];
        usort($topLevel, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        $tree = [];
        foreach ($topLevel as $row) {
            $resolved = LinkResolver::resolve($row);
            if ($resolved === null) {
                continue;
            }

            $children = $byParent[(int) $row['id']] ?? [];
            usort($children, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

            $childItems = [];
            foreach ($children as $child) {
                $resolvedChild = LinkResolver::resolve($child);
                if ($resolvedChild === null) {
                    continue;
                }
                $childItems[] = [
                    'id' => (int) $child['id'],
                    'label_nl' => (string) $child['label_nl'],
                    'label_en' => (string) $child['label_en'],
                    'href' => $resolvedChild['href'],
                    'open_in_new_tab' => $resolvedChild['open_in_new_tab'],
                    'rel' => $resolvedChild['rel'],
                    'children' => [],
                ];
            }

            $tree[] = [
                'id' => (int) $row['id'],
                'label_nl' => (string) $row['label_nl'],
                'label_en' => (string) $row['label_en'],
                'href' => $resolved['href'],
                'open_in_new_tab' => $resolved['open_in_new_tab'],
                'rel' => $resolved['rel'],
                // Only ever set for link_type='route' — lets
                // partials/header.php mark aria-current="page" by the same
                // route key every public page already sets $activeNav to
                // (see README.md), without leaking any other row internals.
                'route_key' => $row['link_type'] === 'route' ? $row['target_route'] : null,
                'children' => $childItems,
            ];
        }

        return $tree;
    }
}
