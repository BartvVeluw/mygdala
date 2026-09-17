<?php

namespace App\Repository;

/**
 * All contact_form_sections SQL — the "Offerte-/contactformulier" block
 * (db/migrations/20260908270100_turn_the_contact_block_into_reusable_blocks.php
 * and 20260909300000_create_the_core_forms_tables.php,
 * App\Service\ContactFormContent).
 *
 * Since Core Forms this block no longer carries a form of its own: it points
 * at a row in `forms` (`form_id`) and adds two things around it — the "Direct
 * contact" card beside it, and the optional file attachment this site's
 * quote form has always accepted (`allow_attachment`). See FORMS.md.
 *
 * Same shape and method names as the other repeatable block types
 * (RichTextRepository::upsertSection()/findBySlugAndKey()/deleteSection()),
 * so App\Service\SectionRegistry dispatches to it exactly like the rest.
 * This type has no child rows and no uploaded media of its own, so deleting
 * an instance is a single DELETE with nothing else to clean up.
 */
class ContactFormRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this instance
     */
    public function findBySlugAndKey(string $pageSlug, string $sectionKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM contact_form_sections WHERE page_slug = :page_slug AND section_key = :section_key LIMIT 1'
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
        $stmt = $this->db->prepare('SELECT * FROM contact_form_sections WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page_slug + section_key:
     * what is the same in every language. The block's heading is stored per
     * website language through App\Service\Blocks\BlockLocalization
     * (db/migrations/20260917180000).
     *
     * @param array<string, mixed> $values form_id, allow_attachment, is_active
     */
    public function upsertSection(string $pageSlug, string $sectionKey, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO contact_form_sections
                (page_slug, section_key, form_id, allow_attachment, is_active, created_at, updated_at)
             VALUES
                (:page_slug, :section_key, :form_id, :allow_attachment, :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                form_id = VALUES(form_id),
                allow_attachment = VALUES(allow_attachment),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            'section_key' => $sectionKey,
            'form_id' => self::positiveIntOrNull($values['form_id'] ?? null),
            'allow_attachment' => ($values['allow_attachment'] ?? true) ? 1 : 0,
            'is_active' => ($values['is_active'] ?? true) ? 1 : 0,
        ]);
    }

    /**
     * The first ACTIVE block instance showing this form, joined to the page
     * it sits on. Used by the legacy endpoint api/contact.php to work out
     * which placement an old POST belongs to, so it can send a no-JS
     * visitor back to the right form on the right page.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveSectionForForm(int $formId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.page_slug, c.section_key
               FROM contact_form_sections c
               JOIN page_sections ps
                 ON ps.section_type = 'contact_form' AND ps.section_id = c.id
              WHERE c.form_id = :form_id AND c.is_active = 1
              ORDER BY c.id ASC LIMIT 1"
        );
        $stmt->execute(['form_id' => $formId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Permanently removes ONE instance — used by the page builder's
     * "Delete section" action via App\Service\SectionRegistry::delete().
     */
    public function deleteSection(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM contact_form_sections WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private static function positiveIntOrNull(mixed $value): ?int
    {
        $id = is_numeric($value) ? (int) $value : 0;

        return $id > 0 ? $id : null;
    }
}
