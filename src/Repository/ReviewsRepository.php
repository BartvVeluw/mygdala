<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the Reviews block (App\Service\Blocks\ReviewsBlock):
 * `review_blocks`, one row per instance addressed by (page_slug, section_key)
 * like every block, and its reviews in `review_block_items`. The same
 * parent-plus-children shape as HoverCardGridRepository, with the order as
 * the one-form editor posts it.
 *
 * Only what is the same in every language is stored here: the block's
 * choices and button destination, and per review its picture, stars, date and
 * source address. Every word — the heading, the button label, a review's
 * text, name, description and source label — is stored per website language
 * through App\Service\Blocks\BlockLocalization against the row's id, in the
 * same transaction as these writes.
 */
final class ReviewsRepository extends Repository
{
    /** The columns updateSettings() writes, and nothing else: never a name from a request. */
    private const SETTINGS = ['layout', 'featured_item_id', 'header_align', 'link_type', 'link_target_id', 'link_url', 'is_active'];

    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM review_blocks WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM review_blocks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Creates the instance's row: no reviews, every choice at the table's own
     * default, shown. It renders nothing until it has a review.
     */
    public function createSection(string $pageSlug, string $sectionKey): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO review_blocks (page_slug, section_key, is_active, created_at, updated_at)
             VALUES (:page_slug, :section_key, 1, NOW(), NOW())'
        );
        $stmt->execute(['page_slug' => $pageSlug, 'section_key' => $sectionKey]);
    }

    /**
     * Writes the choices of one instance. Every value has been checked by the
     * caller (App\Service\ReviewsContent's closed lists, LinkChoice); keys
     * that are not settings are ignored.
     *
     * @param array<string, string|int|bool|null> $settings
     */
    public function updateSettings(int $id, array $settings): void
    {
        $sets = [];
        $params = ['id' => $id];

        foreach (self::SETTINGS as $column) {
            if (!array_key_exists($column, $settings)) {
                continue;
            }

            $value = $settings[$column];
            $sets[] = $column . ' = :' . $column;
            $params[$column] = is_bool($value) ? (int) $value : $value;
        }

        if ($sets === []) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE review_blocks SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * Permanently removes one instance and, through ON DELETE CASCADE, its
     * reviews — the page builder's "Delete section" and a discarded draft,
     * whose SectionRegistry removes the words of the block and of every
     * review first. The pictures are Media Library items and stay there.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM review_blocks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Reviews ---------------------------------------------------------

    /**
     * @return list<array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsByBlockId(int $blockId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM review_block_items
             WHERE review_block_id = :block_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['block_id' => $blockId]);

        return $stmt->fetchAll();
    }

    /**
     * Appends a review to the end of a block and returns its id.
     *
     * @param array<string, mixed> $values media_id, rating, review_date, source_url
     */
    public function createItem(int $blockId, array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO review_block_items
                (review_block_id, media_id, rating, review_date, source_url, sort_order, created_at, updated_at)
             VALUES
                (:block_id, :media_id, :rating, :review_date, :source_url, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'block_id' => $blockId,
            'sort_order' => $this->nextSortOrder($blockId),
        ] + self::itemValues($values));

        return (int) $this->db->lastInsertId();
    }

    /**
     * What is the same in every language of one review. Its id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array<string, mixed> $values as createItem()
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE review_block_items SET
                media_id = :media_id,
                rating = :rating,
                review_date = :review_date,
                source_url = :source_url,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id] + self::itemValues($values));
    }

    /**
     * Permanently removes one review. Its words go first, in the same
     * transaction (BlockLocalization::deleteOwner()). Never the library's file.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM review_block_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the order of a block's reviews as the one-form editor posted it
     * (App\Service\Blocks\EditorChildList::save()): the first id gets
     * sort_order 0. An id that is not a review of $blockId is left alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderItems(int $blockId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE review_block_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND review_block_id = :block_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'block_id' => $blockId]);
        }
    }

    private function nextSortOrder(int $blockId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM review_block_items WHERE review_block_id = :block_id'
        );
        $stmt->execute(['block_id' => $blockId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function itemValues(array $values): array
    {
        $mediaId = (int) ($values['media_id'] ?? 0);
        $rating = (int) ($values['rating'] ?? 0);
        $date = trim((string) ($values['review_date'] ?? ''));
        $sourceUrl = trim((string) ($values['source_url'] ?? ''));

        return [
            'media_id' => $mediaId > 0 ? $mediaId : null,
            'rating' => $rating >= 1 && $rating <= 5 ? $rating : null,
            'review_date' => $date !== '' ? $date : null,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
        ];
    }
}
