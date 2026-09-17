<?php

namespace App\Repository;

/**
 * All stat_strips / stat_strip_items SQL lives here. A "strip" is one page
 * section (currently only index.php's Capability band); each strip has one
 * or more "items" (its stats). Same shape/conventions as
 * FeatureGridRepository (parent + child table, sort_order swap for
 * reordering) — see App\Service\StatStripContent for the defaults/fallback
 * layer built on top of this.
 */
class StatStripRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM stat_strips WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stat_strips WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     * Used by the admin Stat strip edit form's visibility checkbox.
     *
     * @param array<string, bool> $values
     */
    public function upsertStrip(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO stat_strips
                (page_slug, section_key, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsByStripId(int $stripId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM stat_strip_items WHERE stat_strip_id = :stat_strip_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['stat_strip_id' => $stripId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stat_strip_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new, visible stat to the end of a strip and returns its id.
     * Its number and caption are words, stored per website language against
     * that id (App\Service\Blocks\BlockLocalization, db/migrations/
     * 20260917190000), in the same transaction as this insert.
     */
    public function createItem(int $stripId): int
    {
        $nextSortOrder = $this->nextSortOrder($stripId);

        $stmt = $this->db->prepare(
            'INSERT INTO stat_strip_items
                (stat_strip_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:stat_strip_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'stat_strip_id' => $stripId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a stat has that is the same in every language: whether it is
     * shown. Its words are saved through BlockLocalization. The id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array{is_active: bool} $values
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE stat_strip_items SET
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes a stat — distinct from hiding one via is_active
     * (see updateItem). Used by the admin "Verwijderen" action, which removes
     * the stat's words first, in the same transaction
     * (BlockLocalization::deleteOwner()).
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM stat_strip_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next stat (in current display
     * order) within the same strip — same approach as
     * FeatureGridRepository::moveItem().
     */
    public function moveItem(int $stripId, int $itemId, string $direction): void
    {
        $items = $this->findItemsByStripId($stripId);

        $index = null;
        foreach ($items as $i => $item) {
            if ((int) $item['id'] === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($items)) {
            return;
        }

        $a = $items[$index];
        $b = $items[$swapWith];

        $this->updateSortOrder((int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder((int) $b['id'], (int) $a['sort_order']);
    }

    /**
     * Permanently removes the strip and (via ON DELETE CASCADE) all of its
     * stats — used by the page builder's "Delete section" action, whose
     * SectionRegistry::delete() removes the words of every stat first. This
     * type has no uploaded media of its own, so no filesystem cleanup is
     * needed.
     */
    public function deleteStrip(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM stat_strips WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE stat_strip_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function nextSortOrder(int $stripId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM stat_strip_items WHERE stat_strip_id = :stat_strip_id'
        );
        $stmt->execute(['stat_strip_id' => $stripId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }
}
