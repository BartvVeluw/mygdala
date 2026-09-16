<?php

namespace App\Repository;

/**
 * All footer_columns/footer_links SQL. See
 * db/migrations/20260907220000_create_footer_tables.php for the schema
 * rationale. App\Service\FooterService composes these into the nested
 * shape the footer template renders and resolves each link via
 * App\Service\LinkResolver.
 */
class FooterRepository extends Repository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function findAllColumnsForAdmin(): array
    {
        $stmt = $this->db->query('SELECT * FROM footer_columns ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findVisibleColumnsForPublic(): array
    {
        $stmt = $this->db->query('SELECT * FROM footer_columns WHERE is_visible = 1 ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    public function findColumnById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM footer_columns WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{title_nl:string,title_en:string,is_visible:bool} $data
     */
    public function createColumn(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO footer_columns (title_nl, title_en, sort_order, is_visible, created_at, updated_at)
             VALUES (:title_nl, :title_en, :sort_order, :is_visible, NOW(), NOW())'
        );
        $stmt->execute([
            'title_nl' => $data['title_nl'],
            'title_en' => $data['title_en'],
            'sort_order' => $this->nextColumnSortOrder(),
            'is_visible' => $data['is_visible'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{title_nl:string,title_en:string,is_visible:bool} $data
     */
    public function updateColumn(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE footer_columns SET title_nl = :title_nl, title_en = :title_en, is_visible = :is_visible, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'title_nl' => $data['title_nl'],
            'title_en' => $data['title_en'],
            'is_visible' => $data['is_visible'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function setColumnVisible(int $id, bool $isVisible): void
    {
        $stmt = $this->db->prepare('UPDATE footer_columns SET is_visible = :is_visible, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_visible' => $isVisible ? 1 : 0, 'id' => $id]);
    }

    /**
     * Deletes the column and, via the column_id FK's ON DELETE CASCADE, all
     * of its links — an explicit, deliberate choice (unlike nav_items'
     * parent/child RESTRICT): a footer link never makes sense detached from
     * its column, so there is no "move links out first" step to force.
     */
    public function deleteColumn(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM footer_columns WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderColumns(array $orderedIds): void
    {
        $current = $this->findAllColumnsForAdmin();
        $this->reorderRows('footer_columns', $current, $orderedIds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLinksForColumn(int $columnId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM footer_links WHERE column_id = :column_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['column_id' => $columnId]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllVisibleLinks(): array
    {
        $stmt = $this->db->query('SELECT * FROM footer_links WHERE is_visible = 1 ORDER BY column_id ASC, sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    public function findLinkById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM footer_links WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * How many footer links currently point at one CMS page
     * (link_type='page') — the footer half of
     * App\Service\PageService::references(); see
     * NavigationRepository::countByTargetPageId() for the rationale.
     */
    public function countLinksByTargetPageId(int $pageId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c FROM footer_links WHERE link_type = 'page' AND target_page_id = :page_id"
        );
        $stmt->execute(['page_id' => $pageId]);

        return (int) $stmt->fetch()['c'];
    }

    /**
     * The footer links that point at one CMS page, each with the title and
     * visibility of the column it sits in — the footer half of
     * App\Service\PageUsage. Hidden links and links in a hidden column are
     * included: they still point at the page and still follow it.
     *
     * @return list<array<string, mixed>>
     */
    public function findLinksByTargetPageId(int $pageId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.id, l.label_nl, l.label_en, l.is_visible,
                    c.title_nl AS column_title_nl, c.is_visible AS column_is_visible
               FROM footer_links l
               JOIN footer_columns c ON c.id = l.column_id
              WHERE l.link_type = 'page' AND l.target_page_id = :page_id
              ORDER BY c.sort_order ASC, l.sort_order ASC, l.id ASC"
        );
        $stmt->execute(['page_id' => $pageId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{column_id:int,label_nl:string,label_en:string,link_type:string,target_page_id:?int,target_route:?string,external_url:?string,action_key:?string,open_in_new_tab:bool,is_visible:bool} $data
     */
    public function createLink(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO footer_links
                (column_id, label_nl, label_en, link_type, target_page_id, target_route, external_url, action_key, open_in_new_tab, sort_order, is_visible, created_at, updated_at)
             VALUES
                (:column_id, :label_nl, :label_en, :link_type, :target_page_id, :target_route, :external_url, :action_key, :open_in_new_tab, :sort_order, :is_visible, NOW(), NOW())'
        );
        $stmt->execute([
            'column_id' => $data['column_id'],
            'label_nl' => $data['label_nl'],
            'label_en' => $data['label_en'],
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'action_key' => $data['action_key'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
            'sort_order' => $this->nextLinkSortOrder($data['column_id']),
            'is_visible' => $data['is_visible'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{label_nl:string,label_en:string,link_type:string,target_page_id:?int,target_route:?string,external_url:?string,action_key:?string,open_in_new_tab:bool,is_visible:bool} $data
     */
    public function updateLink(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE footer_links SET
                label_nl = :label_nl, label_en = :label_en, link_type = :link_type,
                target_page_id = :target_page_id, target_route = :target_route, external_url = :external_url,
                action_key = :action_key, open_in_new_tab = :open_in_new_tab, is_visible = :is_visible, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'label_nl' => $data['label_nl'],
            'label_en' => $data['label_en'],
            'link_type' => $data['link_type'],
            'target_page_id' => $data['target_page_id'],
            'target_route' => $data['target_route'],
            'external_url' => $data['external_url'],
            'action_key' => $data['action_key'],
            'open_in_new_tab' => $data['open_in_new_tab'] ? 1 : 0,
            'is_visible' => $data['is_visible'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function setLinkVisible(int $id, bool $isVisible): void
    {
        $stmt = $this->db->prepare('UPDATE footer_links SET is_visible = :is_visible, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_visible' => $isVisible ? 1 : 0, 'id' => $id]);
    }

    public function deleteLink(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM footer_links WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderLinks(int $columnId, array $orderedIds): void
    {
        $current = $this->findLinksForColumn($columnId);
        $this->reorderRows('footer_links', $current, $orderedIds);
    }

    /**
     * One place up or down among all columns — ↑/↓ on the Footer screen,
     * which work with a keyboard, on a phone and without JavaScript. Same
     * swap-and-rewrite as NavigationRepository::move(): the whole order is
     * written again, so gaps or duplicates left by an older write can never
     * make a move do nothing. An unknown id or direction changes nothing.
     */
    public function moveColumn(int $id, string $direction): void
    {
        $this->moveWithin('footer_columns', $this->findAllColumnsForAdmin(), $id, $direction);
    }

    /**
     * One place up or down within the link's own column. Which column that
     * is comes from the stored row, never from the request, so a link can
     * never be moved into another column this way.
     */
    public function moveLink(int $id, string $direction): void
    {
        $link = $this->findLinkById($id);
        if ($link === null) {
            return;
        }

        $this->moveWithin('footer_links', $this->findLinksForColumn((int) $link['column_id']), $id, $direction);
    }

    /**
     * @param list<array<string, mixed>> $rows the scope, in its current order
     */
    private function moveWithin(string $table, array $rows, int $id, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        $position = array_search($id, $ids, true);
        if ($position === false) {
            return;
        }

        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

        $this->reorderRows($table, $rows, $ids);
    }

    /**
     * Shared "ignore ids outside this scope, append anything missing at the
     * end" reorder implementation — same safety net as
     * PageSectionRepository::reorder()/NavigationRepository::reorder().
     *
     * @param list<array<string, mixed>> $currentRows
     * @param list<int> $orderedIds
     */
    private function reorderRows(string $table, array $currentRows, array $orderedIds): void
    {
        $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $currentRows);
        $currentIdSet = array_flip($currentIds);

        $validOrderedIds = array_values(array_filter($orderedIds, static fn (int $id): bool => isset($currentIdSet[$id])));
        $remaining = array_values(array_diff($currentIds, $validOrderedIds));
        $finalOrder = array_merge($validOrderedIds, $remaining);

        $this->db->beginTransaction();
        try {
            foreach ($finalOrder as $position => $id) {
                $stmt = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
                $stmt->execute(['sort_order' => $position, 'id' => $id]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function nextColumnSortOrder(): int
    {
        $stmt = $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM footer_columns');

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private function nextLinkSortOrder(int $columnId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM footer_links WHERE column_id = :column_id');
        $stmt->execute(['column_id' => $columnId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
