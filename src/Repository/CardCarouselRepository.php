<?php

namespace App\Repository;

/**
 * All card_carousels / carousel_cards / carousel_card_tags SQL lives here. A
 * "card carousel" is one instance of the reusable "Kaarten-carrousel" block
 * (App\Service\SectionRegistry's `card_carousel`): a section heading plus an
 * editor-curated, ordered list of cards, each with an optional image, a
 * title, a body text, a tag list and an optional link button.
 *
 * Same parent/child repeater shape and conventions as
 * DetailSectionRepository, addressed by (page_slug, section_key), with one
 * extra level: a card owns its own tags. Nothing here assumes a card count
 * — the carousel this replaced was hardcoded to exactly the four material
 * services, which is precisely what phase 3 removed (see
 * docs/content-blocks/DECISIONS.md).
 *
 * This repository only stores the resulting image_path for a card; the
 * actual upload/validation/delete is App\Service\SectionImageUploader's job,
 * called from the API layer.
 */
class CardCarouselRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM card_carousels WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM card_carousels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string|bool|null> $values
     */
    public function upsertCarousel(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO card_carousels
                (page_slug, section_key, eyebrow_nl, eyebrow_en, title_nl, title_en, lead_nl, lead_en,
                 is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :eyebrow_nl, :eyebrow_en, :title_nl, :title_en, :lead_nl, :lead_en,
                 :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                eyebrow_nl = VALUES(eyebrow_nl),
                eyebrow_en = VALUES(eyebrow_en),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                lead_nl = VALUES(lead_nl),
                lead_en = VALUES(lead_en),
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
            'lead_nl' => self::nullIfEmpty($values['lead_nl'] ?? null),
            'lead_en' => self::nullIfEmpty($values['lead_en'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    public function deleteCarousel(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM card_carousels WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    // -- Cards --------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findCardsByCarouselId(int $carouselId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM carousel_cards WHERE carousel_id = :carousel_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['carousel_id' => $carouselId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCardById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM carousel_cards WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string|bool|null> $values
     */
    public function createCard(int $carouselId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('carousel_cards', 'carousel_id', $carouselId);

        $stmt = $this->db->prepare(
            'INSERT INTO carousel_cards
                (carousel_id, title_nl, title_en, body_nl, body_en, link_url, link_label_nl, link_label_en,
                 sort_order, is_active, created_at, updated_at)
             VALUES
                (:carousel_id, :title_nl, :title_en, :body_nl, :body_en, :link_url, :link_label_nl, :link_label_en,
                 :sort_order, :is_active, NOW(), NOW())'
        );
        $stmt->execute([
            'carousel_id' => $carouselId,
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'body_nl' => self::nullIfEmpty($values['body_nl'] ?? null),
            'body_en' => self::nullIfEmpty($values['body_en'] ?? null),
            'link_url' => self::nullIfEmpty($values['link_url'] ?? null),
            'link_label_nl' => self::nullIfEmpty($values['link_label_nl'] ?? null),
            'link_label_en' => self::nullIfEmpty($values['link_label_en'] ?? null),
            'sort_order' => $nextSortOrder,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Saves a card's text fields. The image is saved separately
     * (updateCardImage()/clearCardImage()), so a text save can never drop a
     * photo the editor did not touch.
     *
     * @param array<string, string|bool|null> $values
     */
    public function updateCard(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_cards SET
                title_nl = :title_nl,
                title_en = :title_en,
                body_nl = :body_nl,
                body_en = :body_en,
                link_url = :link_url,
                link_label_nl = :link_label_nl,
                link_label_en = :link_label_en,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'title_nl' => $values['title_nl'],
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'body_nl' => self::nullIfEmpty($values['body_nl'] ?? null),
            'body_en' => self::nullIfEmpty($values['body_en'] ?? null),
            'link_url' => self::nullIfEmpty($values['link_url'] ?? null),
            'link_label_nl' => self::nullIfEmpty($values['link_label_nl'] ?? null),
            'link_label_en' => self::nullIfEmpty($values['link_label_en'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * `media_id` names a Media Library item; `image_path` is written with
     * that item's own path so the legacy column stays true rather than stale
     * while it still exists. The two always move together.
     *
     * @param array<string, mixed> $values media_id, image_path, image_alt_nl, image_alt_en
     */
    public function updateCardImage(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_cards SET
                media_id = :media_id,
                image_path = :image_path,
                image_alt_nl = :image_alt_nl,
                image_alt_en = :image_alt_en,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
            'image_alt_nl' => self::nullIfEmpty($values['image_alt_nl'] ?? null),
            'image_alt_en' => self::nullIfEmpty($values['image_alt_en'] ?? null),
            'id' => $id,
        ]);
    }

    /**
     * Clears a card's image back to NULL — the card then renders the theme's
     * fixed icon instead of a photo.
     */
    public function clearCardImage(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_cards SET media_id = NULL, image_path = NULL, image_alt_nl = NULL, image_alt_en = NULL, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function deleteCard(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM carousel_cards WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function moveCard(int $carouselId, int $cardId, string $direction): void
    {
        $this->moveWithinList(
            $this->findCardsByCarouselId($carouselId),
            $cardId,
            $direction,
            'carousel_cards'
        );
    }

    // -- Card tags ----------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findTagsByCardId(int $cardId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM carousel_card_tags WHERE card_id = :card_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['card_id' => $cardId]);

        return $stmt->fetchAll();
    }

    /**
     * Every tag of every card in one carousel, grouped by card id — one
     * query instead of one per card, so rendering a carousel costs the same
     * whether it holds three cards or thirty.
     *
     * @param list<int> $cardIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function findTagsByCardIds(array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($cardIds) as $index => $cardId) {
            $placeholders[] = ':card_' . $index;
            $params['card_' . $index] = (int) $cardId;
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM carousel_card_tags WHERE card_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY card_id ASC, sort_order ASC, id ASC'
        );
        $stmt->execute($params);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['card_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTagById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM carousel_card_tags WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, string> $values label_nl, label_en
     */
    public function createTag(int $cardId, array $values): int
    {
        $nextSortOrder = $this->nextSortOrder('carousel_card_tags', 'card_id', $cardId);

        $stmt = $this->db->prepare(
            'INSERT INTO carousel_card_tags (card_id, label_nl, label_en, sort_order, created_at, updated_at)
             VALUES (:card_id, :label_nl, :label_en, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'card_id' => $cardId,
            'label_nl' => $values['label_nl'],
            'label_en' => self::nullIfEmpty($values['label_en'] ?? null),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, string> $values label_nl, label_en
     */
    public function updateTag(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_card_tags SET label_nl = :label_nl, label_en = :label_en, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'label_nl' => $values['label_nl'],
            'label_en' => self::nullIfEmpty($values['label_en'] ?? null),
            'id' => $id,
        ]);
    }

    public function deleteTag(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM carousel_card_tags WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function moveTag(int $cardId, int $tagId, string $direction): void
    {
        $this->moveWithinList(
            $this->findTagsByCardId($cardId),
            $tagId,
            $direction,
            'carousel_card_tags'
        );
    }

    // -- Shared helpers -----------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $items already ordered by sort_order ASC, id ASC
     */
    private function moveWithinList(array $items, int $itemId, string $direction, string $table): void
    {
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

        $this->updateSortOrder($table, (int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder($table, (int) $b['id'], (int) $a['sort_order']);
    }

    private function updateSortOrder(string $table, int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function nextSortOrder(string $table, string $fkColumn, int $parentId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM {$table} WHERE {$fkColumn} = :parent_id"
        );
        $stmt->execute(['parent_id' => $parentId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }

    /** A media id, or NULL for "no media item" — never 0. */
    private static function positiveOrNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
