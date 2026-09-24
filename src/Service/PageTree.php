<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The CMS's view of the page tree: every page once, a parent directly above
 * its own children, with its depth — the order of the Pages overview and of
 * every "Bovenliggende pagina" list (docs/pages/NESTING.md).
 *
 * Built from App\Service\PagePath's one-query tree, so it costs nothing
 * extra on a screen that also prints page URLs. Admin-only: a visitor never
 * sees this order, and no public URL depends on it.
 *
 * ROOT ORDER IS WHAT IT WAS. The overview listed the pages with their own
 * template first and then everything by sort_order
 * (PageRepository::findAllForAdmin()); the root pages keep exactly that
 * order, so nesting a page moves nothing else around. Siblings below a page
 * follow sort_order, then id.
 *
 * NOTHING GOES MISSING. A page whose chain is broken — a loop written in SQL,
 * a parent that is gone — is still listed, at the top level, so an editor
 * can open it and give it a valid place again.
 */
final class PageTree
{
    /**
     * @param string|null $group only the trees filed under this admin group
     *                           (App\Service\PageAdminGroup); null for all
     * @return list<array{id: int, depth: int, parent_id: int|null, has_children: bool}>
     */
    public static function ordered(?string $group = null): array
    {
        $nodes = PagePath::nodes();

        $roots = PagePath::childIds(null);
        usort($roots, static fn (int $a, int $b): int => self::rootKey($nodes[$a]) <=> self::rootKey($nodes[$b]));

        $rows = [];
        $seen = [];

        foreach ($roots as $rootId) {
            self::walk($rootId, 0, $rows, $seen);
        }

        // Whatever the walk did not reach sits in a loop: listed on its own.
        foreach (array_keys($nodes) as $id) {
            if (!isset($seen[$id])) {
                self::walk((int) $id, 0, $rows, $seen);
            }
        }

        if ($group === null) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => PagePath::effectiveGroup($row['id']) === $group
        ));
    }

    /**
     * @param list<array{id: int, depth: int, parent_id: int|null, has_children: bool}> $rows
     * @param array<int, true> $seen
     */
    private static function walk(int $id, int $depth, array &$rows, array &$seen): void
    {
        if (isset($seen[$id])) {
            return;
        }

        $seen[$id] = true;
        $children = PagePath::childIds($id);
        $parentId = (int) (PagePath::node($id)['parent_id'] ?? 0);

        $rows[] = [
            'id' => $id,
            'depth' => $depth,
            'parent_id' => ($depth > 0 && $parentId > 0) ? $parentId : null,
            'has_children' => $children !== [],
        ];

        foreach ($children as $childId) {
            self::walk($childId, $depth + 1, $rows, $seen);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rootKey(array $node): array
    {
        return [-(int) ($node['is_system'] ?? 0), (int) ($node['sort_order'] ?? 0), (int) $node['id']];
    }
}
