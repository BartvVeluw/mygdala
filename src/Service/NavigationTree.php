<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\NavigationRepository;

/**
 * The menu's tree, read from the flat nav_items rows one query returned
 * (HEADER-FOOTER.md, "Verplaatsen"). Pure: no SQL, no words. It answers the
 * structural questions every move asks, and it answers them the same way for
 * the overview's drag hints, the editor's Parent list and the server's
 * refusal, so the screen can never offer what the endpoint refuses.
 *
 * ONLY THE MENU. Header buttons are nav_items too, but they live in their own
 * list on the top level and never have or join a submenu
 * (App\Service\NavigationPresentation). A button is in no tree here: it can
 * be nobody's parent and it does not move between levels.
 *
 * THE LIMIT is NavigationRepository::MAX_DEPTH, the levels the public header
 * renders. A move is allowed when the new parent's level plus the height of
 * the moved item's own subtree stays within it: a link with a submenu cannot
 * be dropped where its submenu would end up on a fourth level.
 *
 * NO LOOP, WHATEVER THE DATABASE HOLDS. Every walk keeps a set of visited ids,
 * so a cycle written by hand ends the walk instead of the request. A row whose
 * chain never reaches the top (a loop, a missing parent) has no level: it is
 * listed in unreachableIds(), never offered as a parent, and the only place it
 * may move to is the top level, which repairs it.
 */
final class NavigationTree
{
    /** @var array<int, array<string, mixed>> menu rows by id */
    private array $rows = [];

    /** @var array<int, list<int>> child ids by parent id (0 = top level), in their order */
    private array $children = [];

    /** @var array<int, int> level by id, for every row reachable from the top */
    private array $levels = [];

    /** @var list<array{id: int, level: int}> */
    private array $ordered = [];

    /**
     * @param list<array<string, mixed>> $rows nav_items rows, buttons included (they are skipped)
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            if (NavigationPresentation::isButton($row) && $row['parent_id'] === null) {
                continue;
            }
            $this->rows[(int) $row['id']] = $row;
        }

        $ordered = array_values($this->rows);
        usort($ordered, static fn (array $a, array $b): int => [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']]);
        foreach ($ordered as $row) {
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $this->children[$parentId][] = (int) $row['id'];
        }

        $this->walk(0, 1);
    }

    /** Depth-first from the top level; a visited id is never entered twice. */
    private function walk(int $parentId, int $level): void
    {
        foreach ($this->children[$parentId] ?? [] as $id) {
            if (isset($this->levels[$id])) {
                continue;
            }
            $this->levels[$id] = $level;
            $this->ordered[] = ['id' => $id, 'level' => $level];
            $this->walk($id, $level + 1);
        }
    }

    public function has(int $id): bool
    {
        return isset($this->rows[$id]);
    }

    /** @return array<string, mixed>|null */
    public function row(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    /**
     * Every reachable menu row in the overview's order: a parent, then its
     * submenu, recursively. Rows on a level below MAX_DEPTH (written by hand)
     * are listed too, so the CMS never hides a stored item.
     *
     * @return list<array{id: int, level: int}>
     */
    public function ordered(): array
    {
        return $this->ordered;
    }

    /** 1 on the top level; null for a row that does not reach the top. */
    public function levelOf(int $id): ?int
    {
        return $this->levels[$id] ?? null;
    }

    /** @return list<int> the ids of one group, in order (null = the top level) */
    public function childIds(?int $parentId): array
    {
        return $this->children[$parentId ?? 0] ?? [];
    }

    /** @return list<int> every id below $id, at any depth */
    public function descendantIds(int $id): array
    {
        $found = [];
        $queue = $this->children[$id] ?? [];
        while ($queue !== []) {
            $childId = array_shift($queue);
            if ($childId === $id || isset($found[$childId])) {
                continue;
            }
            $found[$childId] = true;
            foreach ($this->children[$childId] ?? [] as $grandchildId) {
                $queue[] = $grandchildId;
            }
        }

        return array_keys($found);
    }

    /** The levels an item takes up with its submenu: 1 for an item without one. */
    public function heightOf(int $id): int
    {
        return $this->height($id, [$id => true]);
    }

    /** @param array<int, true> $seen */
    private function height(int $id, array $seen): int
    {
        $tallest = 0;
        foreach ($this->children[$id] ?? [] as $childId) {
            if (isset($seen[$childId])) {
                continue;
            }
            $tallest = max($tallest, $this->height($childId, $seen + [$childId => true]));
        }

        return 1 + $tallest;
    }

    /**
     * Menu rows the top level cannot reach: a loop, or a parent that is gone.
     *
     * @return list<int>
     */
    public function unreachableIds(): array
    {
        return array_values(array_diff(array_keys($this->rows), array_keys($this->levels)));
    }

    /**
     * Why $id may not be placed under $parentId (null = the top level), as a
     * translation key; null when it may. One rule set for every way an item
     * moves: a drag, the Parent list, a hand-made POST.
     */
    public function placementError(int $id, ?int $parentId): ?string
    {
        $item = $this->rows[$id] ?? null;
        if ($item === null) {
            return 'validation.nav_place_not_found';
        }

        if ($parentId === null) {
            return null;
        }

        if ($parentId === $id) {
            return 'validation.nav_place_self';
        }

        $parent = $this->rows[$parentId] ?? null;
        if ($parent === null) {
            // Unknown, or a header button: neither has a submenu.
            return 'validation.nav_place_parent_unknown';
        }

        if (in_array($parentId, $this->descendantIds($id), true)) {
            return 'validation.nav_place_descendant';
        }

        // A heading without a destination only exists on the top level.
        if ((string) $item['link_type'] === 'none') {
            return 'validation.submenu_item_eigen_link_hebben';
        }

        $parentLevel = $this->levelOf($parentId);
        if ($parentLevel === null || $parentLevel + $this->heightOf($id) > NavigationRepository::MAX_DEPTH) {
            return 'validation.nav_place_too_deep';
        }

        return null;
    }

    /**
     * The parents $id may be placed under, in the overview's order, with
     * their level: never the item itself, nothing below it, nothing whose
     * level would push its submenu past MAX_DEPTH. For a new item ($id
     * null) every reachable menu row above the deepest level.
     *
     * @return list<array{id: int, level: int}>
     */
    public function parentCandidates(?int $id): array
    {
        $candidates = [];
        foreach ($this->ordered as $entry) {
            if ($id === null) {
                if ($entry['level'] < NavigationRepository::MAX_DEPTH) {
                    $candidates[] = $entry;
                }
                continue;
            }
            if ($this->placementError($id, $entry['id']) === null) {
                $candidates[] = $entry;
            }
        }

        return $candidates;
    }
}
