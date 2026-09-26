<?php

namespace App\Repository;

/**
 * All rich_text_sections SQL — the page builder's plain long-form body
 * section (see db/migrations/20260908100100_create_rich_text_sections_table.php
 * and App\Service\RichTextContent).
 *
 * Same shape and method names as the other repeater section types
 * (FeatureGridRepository::upsertGrid()/findBySlugAndKey()/deleteGrid()), so
 * App\Service\SectionRegistry dispatches to it exactly like the rest. This
 * type has no child rows and no uploaded media of its own, so deleting a
 * section is a single DELETE with nothing else to clean up.
 *
 * The body itself is not here: it is stored per website language in
 * block_translations, through App\Service\Blocks\BlockLocalization, which
 * sanitizes it (db/migrations/20260917170000). A row in this table is what
 * is the same in every language: which page and key it belongs to, and
 * whether it is shown.
 */
class RichTextRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this section
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rich_text_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM rich_text_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     *
     * @param array{is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rich_text_sections
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
     * What the editor sets besides the body and "Actief", the same in every
     * language: the alignment, the width and the optional button's destination
     * (App\Service\Routing\LinkChoice). The button's label is a word, saved
     * through BlockLocalization.
     *
     * @param array{text_align: string, content_width: string, button_link_type: string|null, button_link_target_id: int|null, button_url: string} $values
     */
    public function updateSettings(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE rich_text_sections SET
                text_align = :text_align,
                content_width = :content_width,
                button_link_type = :button_link_type,
                button_link_target_id = :button_link_target_id,
                button_url = :button_url,
                updated_at = NOW()
             WHERE id = :id'
        );

        $stmt->execute([
            'text_align' => $values['text_align'],
            'content_width' => $values['content_width'],
            'button_link_type' => $values['button_link_type'],
            'button_link_target_id' => $values['button_link_target_id'],
            'button_url' => $values['button_url'] === '' ? null : $values['button_url'],
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes one rich-text section — used by the page builder's
     * "Delete section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM rich_text_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
