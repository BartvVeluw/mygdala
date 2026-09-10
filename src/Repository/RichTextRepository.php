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
 * `content_html` is always stored already-sanitized (RichTextSanitizer, at
 * the api/admin/update-rich-text-section.php write boundary) — this class
 * never sanitizes, exactly like every other repository in this project
 * never validates.
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
     * @param array{content_html?: ?string, content_html_en?: ?string, is_active?: bool} $values
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO rich_text_sections
                (page_slug, section_key, content_html, content_html_en, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :content_html, :content_html_en, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                content_html = VALUES(content_html),
                content_html_en = VALUES(content_html_en),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $contentHtml = $values['content_html'] ?? null;
        $contentHtmlEn = $values['content_html_en'] ?? null;

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'content_html' => ($contentHtml !== null && $contentHtml !== '') ? $contentHtml : null,
            'content_html_en' => ($contentHtmlEn !== null && $contentHtmlEn !== '') ? $contentHtmlEn : null,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
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
