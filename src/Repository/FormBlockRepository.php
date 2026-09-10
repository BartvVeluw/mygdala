<?php

namespace App\Repository;

/**
 * All `form_blocks` SQL — the content rows of the reusable "Formulier" block
 * (db/migrations/20260909300000_create_the_core_forms_tables.php,
 * App\Service\FormBlockContent).
 *
 * Same shape and method names as every other repeatable block type
 * (RichTextRepository::upsertSection()/findBySlugAndKey()/deleteSection()),
 * so App\Service\SectionRegistry treats it exactly like the rest.
 *
 * It also answers "which pages show form #3" (placementsOf()), because this
 * table IS the answer — a form is placed by a block, and the block rows are
 * here. App\Service\Forms\FormUsage is what turns that into the list an
 * editor reads before deleting a form.
 */
class FormBlockRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM form_blocks WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM form_blocks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key.
     *
     * @param array<string, mixed> $values form_id, title_nl/en, intro_nl/en, is_active
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO form_blocks
                (page_slug, section_key, form_id, title_nl, title_en, intro_nl, intro_en, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :form_id, :title_nl, :title_en, :intro_nl, :intro_en, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                form_id = VALUES(form_id),
                title_nl = VALUES(title_nl),
                title_en = VALUES(title_en),
                intro_nl = VALUES(intro_nl),
                intro_en = VALUES(intro_en),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'form_id' => self::positiveIntOrNull($values['form_id'] ?? null),
            'title_nl' => self::nullIfEmpty($values['title_nl'] ?? null),
            'title_en' => self::nullIfEmpty($values['title_en'] ?? null),
            'intro_nl' => self::nullIfEmpty($values['intro_nl'] ?? null),
            'intro_en' => self::nullIfEmpty($values['intro_en'] ?? null),
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * Permanently removes ONE instance — used by the page builder's
     * "Delete section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM form_blocks WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Every placement of one form, with the page it sits on — the raw
     * material for "waar wordt dit formulier gebruikt".
     *
     * Joined to `page_sections` and `pages` so a content row that is no
     * longer attached to a page (an orphan a failed delete could leave) does
     * NOT count as a usage: it would block a deletion the editor cannot
     * undo from any screen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function placementsOf(int $formId): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.page_slug, b.section_key, p.title AS page_title, p.id AS page_id
               FROM form_blocks b
               JOIN page_sections ps
                 ON ps.section_type = 'form' AND ps.section_id = b.id
               JOIN pages p ON p.id = ps.page_id
              WHERE b.form_id = :form_id
              ORDER BY p.title ASC, b.section_key ASC"
        );
        $stmt->execute(['form_id' => $formId]);

        return $stmt->fetchAll();
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value !== null && $value !== '') ? $value : null;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }
}
