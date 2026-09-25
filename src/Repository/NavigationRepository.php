<?php

namespace App\Repository;

use App\Service\NavigationPresentation;

/**
 * All nav_items SQL. See db/migrations/20260907210000_create_nav_items_table.php
 * for the schema rationale. This repository only ever deals in flat rows —
 * App\Service\NavigationService builds the tree (MAX_DEPTH levels) and
 * resolves links.
 *
 * NO WORDS HERE. An item's label is stored per website language in
 * nav_item_translations and read and written only through
 * App\Service\NavigationLocalization (Multilingual 2.0 phase 4). What this
 * class stores is the same in every language.
 *
 * ORDER IS KEPT PER GROUP. A group is one parent (NULL for the top level)
 * plus one presentation: the menu links and the header buttons are both
 * top-level rows, but they are shown in different places and ordered
 * independently (App\Service\NavigationPresentation). reorder(), move() and a
 * new row's position therefore all work inside that group only.
 */
class NavigationRepository extends Repository
{
    /**
     * The menu has three levels at most: a top-level link, its submenu, and
     * one submenu below a submenu item (HEADER-FOOTER.md, "Drie niveaus").
     * The schema has no limit of its own (parent_id is a plain
     * self-reference): canBeParent() keeps a fourth level from being stored,
     * and App\Service\NavigationService never renders one either.
     */
    public const MAX_DEPTH = 3;

    /**
     * Every item (visible or not) as a flat list — App\Service\NavigationService
     * groups these into a tree by parent_id itself, sorting each
     * parent's own children by sort_order, so the exact row order returned
     * here only needs to be sort_order-within-parent, not already nested.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForAdmin(): array
    {
        $stmt = $this->db->query('SELECT * FROM nav_items ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findVisibleForPublic(): array
    {
        $stmt = $this->db->query('SELECT * FROM nav_items WHERE is_visible = 1 ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM nav_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countChildren(int $id): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) AS c FROM nav_items WHERE parent_id = :id');
        $stmt->execute(['id' => $id]);

        return (int) $stmt->fetch()['c'];
    }

    /**
     * How many navigation items currently point at one CMS page
     * (link_type='page'), header buttons included. Used by
     * App\Service\PageService::references() to refuse deleting a page that is
     * still linked from the header, and to tell the admin exactly what to
     * unlink first — rather than silently blanking the link out
     * (nav_items.target_page_id's foreign key is ON DELETE RESTRICT for the
     * same reason; see
     * db/migrations/20260908100400_repoint_page_links_to_pages.php).
     */
    public function countByTargetPageId(int $pageId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c FROM nav_items WHERE link_type = 'page' AND target_page_id = :page_id"
        );
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetch()['c'];
    }

    /**
     * The navigation items that point at one CMS page (link_type='page'),
     * for the list the page editor shows before a page's web address changes
     * (App\Service\PageUsage). Hidden items are included: they still point
     * at the page and still follow it. `presentation` tells a menu link from
     * a header button.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTargetPageId(int $pageId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, parent_id, is_visible, presentation FROM nav_items
              WHERE link_type = 'page' AND target_page_id = :page_id
              ORDER BY presentation DESC, sort_order ASC, id ASC"
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /**
     * Enforces the MAX_DEPTH cap: $parentId is only a valid parent when it
     * exists, is a MENU LINK (a header button has no dropdown) and sits above
     * the deepest level, so the new child lands on level MAX_DEPTH at most.
     * Used by create-nav-item.php before writing a submenu item's parent_id,
     * and by the editor and the overview before they offer a parent at all.
     *
     * No cycle can come from this: parent_id is written once, when a row is
     * created, and never changed afterwards (update-nav-item.php does not
     * move items). A new row cannot be anybody's ancestor yet, so pointing
     * it at an existing row can never close a loop. depthOf() still refuses
     * a chain it cannot walk to the top, which covers a loop or an over-deep
     * chain written into the database by hand.
     */
    public function canBeParent(int $parentId): bool
    {
        $parent = $this->findById($parentId);
        if ($parent === null || NavigationPresentation::isButton($parent)) {
            return false;
        }

        $depth = $this->depthOf($parentId);

        return $depth !== null && $depth < self::MAX_DEPTH;
    }

    /**
     * An item's level: 1 on the top level, 2 in a submenu, 3 in a submenu of
     * a submenu item. Null for an unknown id, and for a chain that does not
     * reach the top within MAX_DEPTH steps: a loop, a missing parent, or a
     * level deeper than the navigation has.
     */
    public function depthOf(int $id): ?int
    {
        $currentId = $id;
        for ($depth = 1; $depth <= self::MAX_DEPTH; $depth++) {
            $row = $this->findById($currentId);
            if ($row === null) {
                return null;
            }
            if ($row['parent_id'] === null) {
                return $depth;
            }
            $currentId = (int) $row['parent_id'];
        }

        return null;
    }

    /**
     * A new row goes to the end of its own group. presentation and
     * button_variant are optional, so a caller that predates header buttons
     * still creates a plain menu link.
     *
     * @param array{link_type:string,target_page_id:?int,target_route:?string,external_url:?string,open_in_new_tab:bool,parent_id:?int,is_visible:bool,presentation?:string,button_variant?:string} $data
     */
    public function create(array $data): int
    {
        $presentation = NavigationPresentation::of($data);

        $stmt = $this->db->prepare(
            'INSERT INTO nav_items
                (link_type, target_page_id, target_route, external_url, open_in_new_tab, presentation, button_variant, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES
                (:link_type, :target_page_id, :target_route, :external_url, :open_in_new_tab, :presentation, :button_variant, :parent_id, :sort_order, :is_visible, NOW(), NOW())'
        );
        $stmt->execute([
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
            'presentation' => $presentation,
            'button_variant' => NavigationPresentation::variantOf($data),
            'parent_id' => $data['parent_id'],
            'sort_order' => $this->nextSortOrder($data['parent_id'], $presentation),
            'is_visible' => $data['is_visible'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * parent_id is never changed here (see api/admin/update-nav-item.php).
     * The presentation may change: a link that becomes a button, or back,
     * moves to the END of the group it joins, so it never lands in the middle
     * of an order the editor arranged there. Without presentation or
     * button_variant in $data the stored values are kept.
     *
     * @param array{link_type:string,target_page_id:?int,target_route:?string,external_url:?string,open_in_new_tab:bool,is_visible:bool,presentation?:string,button_variant?:string} $data
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return;
        }

        $presentation = NavigationPresentation::of(array_key_exists('presentation', $data) ? $data : $existing);
        $variant = NavigationPresentation::variantOf(array_key_exists('button_variant', $data) ? $data : $existing);

        $parentId = $existing['parent_id'] === null ? null : (int) $existing['parent_id'];
        $sortOrder = $presentation === NavigationPresentation::of($existing)
            ? (int) $existing['sort_order']
            : $this->nextSortOrder($parentId, $presentation);

        $stmt = $this->db->prepare(
            'UPDATE nav_items SET
                link_type = :link_type,
                target_page_id = :target_page_id, target_route = :target_route, external_url = :external_url,
                open_in_new_tab = :open_in_new_tab, presentation = :presentation, button_variant = :button_variant,
                sort_order = :sort_order, is_visible = :is_visible, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
            'presentation' => $presentation,
            'button_variant' => $variant,
            'sort_order' => $sortOrder,
            'is_visible' => $data['is_visible'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function setVisible(int $id, bool $isVisible): void
    {
        $stmt = $this->db->prepare('UPDATE nav_items SET is_visible = :is_visible, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_visible' => $isVisible ? 1 : 0, 'id' => $id]);
    }

    /**
     * Only succeeds when the item has no children — callers (the admin API
     * endpoint) must check countChildren() first and give a friendly error;
     * the parent_id FK is RESTRICT as a defense-in-depth backstop, not the
     * primary UX.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM nav_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Persists a new order within one group — one parent (top-level when
     * $parentId is null) and one presentation — with the same "ignore ids
     * outside this exact group, append anything missing at the end" safety
     * net as PageSectionRepository::reorder(). The drag-and-drop lists on
     * admin/navigation.php post here.
     *
     * @param list<int> $orderedIds
     */
    public function reorder(?int $parentId, array $orderedIds, string $presentation = NavigationPresentation::LINK): void
    {
        $current = $this->findForGroup($parentId, NavigationPresentation::of(['presentation' => $presentation]));
        $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $current);
        $currentIdSet = array_flip($currentIds);

        $validOrderedIds = array_values(array_filter(
            $orderedIds,
            static fn (int $id): bool => isset($currentIdSet[$id])
        ));
        $remaining = array_values(array_diff($currentIds, $validOrderedIds));

        $this->writeOrder(array_merge($validOrderedIds, $remaining));
    }

    /**
     * Moves one item a single place up or down inside its own group — the
     * up/down buttons on admin/navigation.php, which work with a keyboard, on
     * a phone and without JavaScript. Same one-step pattern as
     * FormRepository::moveField(): the group is renumbered first, so gaps left
     * by deletions, or two rows sharing a sort_order, still move exactly one
     * place. The first item up or the last item down changes nothing.
     */
    public function move(int $id, string $direction): void
    {
        $item = $this->findById($id);
        if ($item === null || !in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $parentId = $item['parent_id'] === null ? null : (int) $item['parent_id'];
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->findForGroup($parentId, NavigationPresentation::of($item))
        );

        $position = array_search($id, $ids, true);
        if ($position === false) {
            return;
        }

        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

        $this->writeOrder($ids);
    }

    /**
     * @param list<int> $orderedIds
     */
    private function writeOrder(array $orderedIds): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($orderedIds as $position => $id) {
                $stmt = $this->db->prepare('UPDATE nav_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
                $stmt->execute(['sort_order' => $position, 'id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findForGroup(?int $parentId, string $presentation): array
    {
        if ($parentId === null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM nav_items WHERE parent_id IS NULL AND presentation = :presentation ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute(['presentation' => $presentation]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM nav_items WHERE parent_id = :parent_id AND presentation = :presentation ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute(['parent_id' => $parentId, 'presentation' => $presentation]);
        }

        return $stmt->fetchAll();
    }

    private function nextSortOrder(?int $parentId, string $presentation): int
    {
        if ($parentId === null) {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM nav_items WHERE parent_id IS NULL AND presentation = :presentation'
            );
            $stmt->execute(['presentation' => $presentation]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM nav_items WHERE parent_id = :parent_id AND presentation = :presentation'
            );
            $stmt->execute(['parent_id' => $parentId, 'presentation' => $presentation]);
        }

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
