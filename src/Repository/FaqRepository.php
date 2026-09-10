<?php

namespace App\Repository;

/**
 * All faq_sections / faq_items SQL lives here. A "section" is one FAQ block
 * on a page (e.g. diensten.php's "Nog vragen?"); each section has one or
 * more "items" (its question/answer pairs). Same shape/conventions as
 * FeatureGridRepository (parent + child table, sort_order swap for
 * reordering) — see App\Service\FaqContent for the defaults/fallback layer
 * built on top of this.
 */
class FaqRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM faq_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM faq_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     * Used by the admin FAQ edit form's section-level fields.
     *
     * @param array<string, string|bool|null> $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO faq_sections
                (page_slug, section_key, eyebrow_nl, eyebrow_en, title_nl, title_en, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :eyebrow_nl, :eyebrow_en, :title_nl, :title_en, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                eyebrow_nl = VALUES(eyebrow_nl),
                eyebrow_en = VALUES(eyebrow_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'eyebrow_nl' => self::nullIfEmpty($values['eyebrow_nl'] ?? null),
            'eyebrow_en' => self::nullIfEmpty($values['eyebrow_en'] ?? null),
            'title_nl' => self::nullIfEmpty($values['title_nl'] ?? null),
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findItemsBySectionId(int $sectionId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM faq_items WHERE faq_section_id = :faq_section_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['faq_section_id' => $sectionId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM faq_items WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new question/answer to the end of a section.
     *
     * @param array<string, string> $values question_nl, question_en, answer_nl, answer_en
     */
    public function createItem(int $sectionId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder($sectionId);

        $stmt = $this->db->prepare(
            'INSERT INTO faq_items
                (faq_section_id, question_nl, question_en, answer_nl, answer_en, sort_order, is_active, created_at, updated_at)
             VALUES
                (:faq_section_id, :question_nl, :question_en, :answer_nl, :answer_en, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'faq_section_id' => $sectionId,
            'question_nl' => $values['question_nl'],
            'question_en' => self::nullIfEmpty($values['question_en'] ?? null),
            'answer_nl' => $values['answer_nl'],
            'answer_en' => self::nullIfEmpty($values['answer_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string|bool> $values question_nl, question_en, answer_nl, answer_en, is_active
     */
    public function updateItem(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE faq_items SET
                question_nl = :question_nl,
                question_en = :question_en,
                answer_nl = :answer_nl,
                answer_en = :answer_en,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'question_nl' => $values['question_nl'],
            'question_en' => self::nullIfEmpty($values['question_en'] ?? null),
            'answer_nl' => $values['answer_nl'],
            'answer_en' => self::nullIfEmpty($values['answer_en'] ?? null),
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes a question — distinct from hiding one via
     * is_active (see updateItem). Used by the admin "Verwijderen" action.
     */
    public function deleteItem(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM faq_items WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next item (in current display
     * order) within the same section — same approach as
     * FeatureGridRepository::moveItem().
     */
    public function moveItem(int $sectionId, int $itemId, string $direction): void
    {
        $items = $this->findItemsBySectionId($sectionId);

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
     * Permanently removes the section and (via ON DELETE CASCADE) all of its
     * questions — used by the page builder's "Delete section" action. This
     * type has no uploaded media of its own, so no filesystem cleanup is
     * needed.
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM faq_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE faq_items SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function nextSortOrder(int $sectionId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM faq_items WHERE faq_section_id = :faq_section_id'
        );
        $stmt->execute(['faq_section_id' => $sectionId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}
