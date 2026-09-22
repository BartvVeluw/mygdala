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
 * NO WORDS HERE. The heading, a card's title, body, alt text and link label
 * and a tag's label are stored per website language in block_translations,
 * against the id of the row they belong to, through
 * App\Service\Blocks\BlockLocalization (db/migrations/20260917200000). This
 * repository writes only what is the same in every language: visibility,
 * order, the link URL and the image reference. Every create* returns the new
 * row's id, so its words can be saved against it in the same transaction.
 *
 * This repository only stores the image reference for a card; choosing the
 * Media Library item is the API layer's job.
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
     * What a carousel has that is the same in every language: whether it is
     * shown. Its heading is saved through BlockLocalization against the
     * row's id, which this upsert never changes.
     *
     * @param array<string, bool|null> $values is_active
     */
    public function upsertCarousel(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO card_carousels
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
     * The carousel's own settings, the same in every language: whether it is
     * shown and how it is laid out on larger screens
     * (CardCarouselContent::LAYOUTS, checked by the caller).
     */
    public function updateSettings(int $id, bool $isActive, string $desktopLayout): void
    {
        $stmt = $this->db->prepare(
            'UPDATE card_carousels SET is_active = :is_active, desktop_layout = :desktop_layout, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'is_active' => $isActive ? 1 : 0,
            'desktop_layout' => $desktopLayout,
            'id' => $id,
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
     * Appends a new card without an image or a link to the end of a carousel
     * and returns its id. A new card is a DRAFT unless $isActive says
     * otherwise: it shows on the website only once an editor switches it on,
     * so a half-filled card never appears on a live page. Its words are
     * stored per website language against that id
     * (App\Service\Blocks\BlockLocalization), in the same transaction as
     * this insert.
     */
    public function createCard(int $carouselId, bool $isActive = false): int
    {
        $nextSortOrder = $this->nextSortOrder('carousel_cards', 'carousel_id', $carouselId);

        $stmt = $this->db->prepare(
            'INSERT INTO carousel_cards
                (carousel_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:carousel_id, :sort_order, :is_active, NOW(), NOW())'
        );
        $stmt->execute([
            'carousel_id' => $carouselId,
            'sort_order' => $nextSortOrder,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a card has that is the same in every language: where its button
     * points and whether it is shown. `link_type` is NULL (no button), 'url'
     * (link_url as typed) or an App\Service\Routing\LinkTargets type with
     * `link_target_id`; the caller has checked it. link_url is kept whatever
     * the type, so switching back to "Eigen adres" shows what was typed.
     * Its words are saved through BlockLocalization and the image through
     * updateCardImage()/clearCardImage(). The id never changes, so the words
     * of every language stay attached to it.
     *
     * @param array<string, mixed> $values link_type, link_target_id, link_url, is_active
     */
    public function updateCard(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_cards SET
                link_type = :link_type,
                link_target_id = :link_target_id,
                link_url = :link_url,
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'link_type' => self::nullIfEmpty($values['link_type'] ?? null),
            'link_target_id' => self::positiveOrNull($values['link_target_id'] ?? null),
            'link_url' => self::nullIfEmpty($values['link_url'] ?? null),
            'is_active' => ($values['is_active'] ?? false) ? 1 : 0,
            'id' => $id,
        ]);
    }

    /** Only whether one card is shown: the switch on the carousel's card overview. */
    public function setCardActive(int $id, bool $isActive): void
    {
        $stmt = $this->db->prepare('UPDATE carousel_cards SET is_active = :is_active, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'id' => $id]);
    }

    /**
     * Stores the cards of one carousel in the given order. Ids that are not
     * cards of this carousel are ignored, so a request can never reorder
     * another block's rows.
     *
     * @param list<int> $orderedIds
     */
    public function reorderCards(int $carouselId, array $orderedIds): void
    {
        $this->reorder('carousel_cards', 'carousel_id', $carouselId, $orderedIds);
    }

    /**
     * `media_id` names a Media Library item; `image_path` is written with
     * that item's own path so the legacy column stays true rather than stale
     * while it still exists. The two always move together. The alt text is a
     * word, saved through BlockLocalization.
     *
     * @param array<string, mixed> $values media_id, image_path
     */
    public function updateCardImage(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carousel_cards SET
                media_id = :media_id,
                image_path = :image_path,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'image_path' => (string) ($values['image_path'] ?? ''),
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
            'UPDATE carousel_cards SET media_id = NULL, image_path = NULL, updated_at = NOW()
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
     * Every tag row of every card in one carousel, grouped by card id — one
     * query instead of one per card, so rendering a carousel costs the same
     * whether it holds three cards or thirty. The rows carry ids and order
     * only; their labels come from BlockLocalization.
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
     * Appends a new tag to the end of a card and returns its id. Its label is
     * stored per website language against that id
     * (App\Service\Blocks\BlockLocalization), in the same transaction as this
     * insert.
     */
    public function createTag(int $cardId): int
    {
        $nextSortOrder = $this->nextSortOrder('carousel_card_tags', 'card_id', $cardId);

        $stmt = $this->db->prepare(
            'INSERT INTO carousel_card_tags (card_id, sort_order, created_at, updated_at)
             VALUES (:card_id, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'card_id' => $cardId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A tag has nothing that is the same in every language except its place
     * in the list, so saving one only marks the row as changed; its label is
     * saved through BlockLocalization in the same transaction.
     */
    public function updateTag(int $id): void
    {
        $stmt = $this->db->prepare('UPDATE carousel_card_tags SET updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function deleteTag(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM carousel_card_tags WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Stores the tags of one card in the given order; see reorderCards().
     *
     * @param list<int> $orderedIds
     */
    public function reorderTags(int $cardId, array $orderedIds): void
    {
        $this->reorder('carousel_card_tags', 'card_id', $cardId, $orderedIds);
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

    /**
     * sort_order 0, 1, 2 … in the order given, for the rows of one parent
     * only. $table and $fkColumn are this class's own literals, never input.
     *
     * @param list<int> $orderedIds
     */
    private function reorder(string $table, string $fkColumn, int $parentId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND {$fkColumn} = :parent_id"
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $parentId]);
        }
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
