<?php

namespace App\Repository;

/**
 * All nav_items SQL. See db/migrations/20260907210000_create_nav_items_table.php
 * for the schema rationale. This repository only ever deals in flat rows —
 * App\Service\NavigationService builds the 2-level tree and resolves links.
 */
class NavigationRepository extends Repository
{
    /**
     * Every item (visible or not) as a flat list — App\Service\NavigationService
     * groups these into a 2-level tree by parent_id itself, sorting each
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
     * (link_type='page'). Used by App\Service\PageService::references() to
     * refuse deleting a page that is still linked from the menu, and to tell
     * the admin exactly what to unlink first — rather than silently blanking
     * the link out (nav_items.target_page_id's foreign key is ON DELETE
     * RESTRICT for the same reason; see
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
     * at the page and still follow it.
     *
     * @return list<array<string, mixed>>
     */
    public function findByTargetPageId(int $pageId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, label_nl, label_en, parent_id, is_visible FROM nav_items
              WHERE link_type = 'page' AND target_page_id = :page_id
              ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /**
     * Enforces the 2-level cap (see the nav_items migration): $parentId is
     * only a valid parent when it exists and is itself a top-level item —
     * an item that already has a parent can never become a parent itself,
     * which also makes a circular chain (A's parent is B, B's parent is A)
     * impossible to create in the first place, since the second half of any
     * such cycle would require a non-top-level item to be chosen as a
     * parent. Used by create-nav-item.php/update-nav-item.php before
     * writing a submenu item's parent_id.
     */
    public function canBeParent(int $parentId): bool
    {
        $parent = $this->findById($parentId);

        return $parent !== null && $parent['parent_id'] === null;
    }

    /**
     * @param array{label_nl:string,label_en:string,link_type:string,target_page_id:?int,target_route:?string,external_url:?string,open_in_new_tab:bool,parent_id:?int,is_visible:bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO nav_items
                (label_nl, label_en, link_type, target_page_id, target_route, external_url, open_in_new_tab, parent_id, sort_order, is_visible, created_at, updated_at)
             VALUES
                (:label_nl, :label_en, :link_type, :target_page_id, :target_route, :external_url, :open_in_new_tab, :parent_id, :sort_order, :is_visible, NOW(), NOW())'
        );
        $stmt->execute([
            'label_nl' => $data['label_nl'],
            'label_en' => $data['label_en'],
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
            'parent_id' => $data['parent_id'],
            'sort_order' => $this->nextSortOrder($data['parent_id']),
            'is_visible' => $data['is_visible'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{label_nl:string,label_en:string,link_type:string,target_page_id:?int,target_route:?string,external_url:?string,open_in_new_tab:bool,is_visible:bool} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE nav_items SET
                label_nl = :label_nl, label_en = :label_en, link_type = :link_type,
                target_page_id = :target_page_id, target_route = :target_route, external_url = :external_url,
                open_in_new_tab = :open_in_new_tab, is_visible = :is_visible, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'label_nl' => $data['label_nl'],
            'label_en' => $data['label_en'],
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
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
     * Persists a new order within one parent scope (top-level when
     * $parentId is null) — same "ignore ids outside this exact scope,
     * append anything missing at the end" safety net as
     * PageSectionRepository::reorder().
     *
     * @param list<int> $orderedIds
     */
    public function reorder(?int $parentId, array $orderedIds): void
    {
        $current = $this->findForParent($parentId);
        $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $current);
        $currentIdSet = array_flip($currentIds);

        $validOrderedIds = array_values(array_filter(
            $orderedIds,
            static fn (int $id): bool => isset($currentIdSet[$id])
        ));
        $remaining = array_values(array_diff($currentIds, $validOrderedIds));
        $finalOrder = array_merge($validOrderedIds, $remaining);

        $this->db->beginTransaction();
        try {
            foreach ($finalOrder as $position => $id) {
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
    private function findForParent(?int $parentId): array
    {
        if ($parentId === null) {
            $stmt = $this->db->query('SELECT * FROM nav_items WHERE parent_id IS NULL ORDER BY sort_order ASC, id ASC');
        } else {
            $stmt = $this->db->prepare('SELECT * FROM nav_items WHERE parent_id = :parent_id ORDER BY sort_order ASC, id ASC');
            $stmt->execute(['parent_id' => $parentId]);
        }

        return $stmt->fetchAll();
    }

    private function nextSortOrder(?int $parentId): int
    {
        if ($parentId === null) {
            $stmt = $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM nav_items WHERE parent_id IS NULL');
        } else {
            $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM nav_items WHERE parent_id = :parent_id');
            $stmt->execute(['parent_id' => $parentId]);
        }

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
