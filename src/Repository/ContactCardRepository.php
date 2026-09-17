<?php

namespace App\Repository;

/**
 * All contact_cards SQL — the "Contactkaart" block
 * (db/migrations/20260908270100_turn_the_contact_block_into_reusable_blocks.php,
 * App\Service\ContactCardContent).
 *
 * Same shape and method names as the other repeatable block types, so
 * App\Service\SectionRegistry dispatches to it exactly like the rest. No
 * child rows and no uploaded media, so deleting an instance is a single
 * DELETE.
 */
class ContactCardRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM contact_cards WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM contact_cards WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. The card's words are stored per
     * website language through App\Service\Blocks\BlockLocalization
     * (db/migrations/20260917170000).
     *
     * @param array{button_url?: string, is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO contact_cards
                (page_slug, section_key, button_url, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :button_url, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                button_url = VALUES(button_url),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'button_url' => self::nullIfEmpty($values['button_url'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * Permanently removes ONE instance — used by the page builder's
     * "Delete section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM contact_cards WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private static function nullIfEmpty(string|bool|null $value): ?string
    {
        $value = is_string($value) ? $value : null;

        return ($value !== null && $value !== '') ? $value : null;
    }
}
