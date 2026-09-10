<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Database;
use App\Repository\FormBlockRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\SectionRegistry;

/**
 * Where a form is used, and whether it is safe to delete.
 *
 * The same idea, and the same promise to the editor, as the Media Library's
 * safe deletion (MEDIA.md): nothing that is still in use disappears, and the
 * refusal comes with the list of places to go and look. A form that is still
 * on a page must not vanish from under it, and a form that still holds
 * people's enquiries must not take them with it.
 *
 * TWO REASONS TO REFUSE, and they are different:
 *
 *   PLACED     a block on some page points at this form. Deleting it would
 *              leave that block rendering nothing, with no way for the
 *              editor to see why.
 *   SUBMISSIONS   it has stored enquiries. Deleting personal data as a side
 *              effect of tidying up a form is exactly the kind of silent
 *              loss FORMS.md's privacy section rules out — the owner deletes
 *              the submissions on purpose first, or keeps the form.
 *
 * NO GENERIC REFERENCE ENGINE. Two blocks can place a form and both live in
 * this codebase, so this class asks their two tables directly. If a module
 * ever places forms as well, this is where its query joins the other two —
 * one method to widen, not an event bus to subscribe to.
 */
final class FormUsage
{
    /**
     * Every placement of a form: the page it is on and the editor URL that
     * opens that block.
     *
     * @return list<array{page_title: string, page_id: int, edit_url: string, block: string}>
     */
    public static function placements(int $formId): array
    {
        if ($formId < 1) {
            return [];
        }

        $placements = [];

        try {
            foreach ((new FormBlockRepository())->placementsOf($formId) as $row) {
                $placements[] = [
                    'page_title' => (string) $row['page_title'],
                    'page_id' => (int) $row['page_id'],
                    'edit_url' => '/admin/form-block.php?section='
                        . urlencode((string) $row['page_slug'] . ':' . (string) $row['section_key']),
                    'block' => SectionRegistry::label('form'),
                ];
            }

            foreach (self::contactFormPlacements($formId) as $row) {
                $placements[] = [
                    'page_title' => (string) $row['page_title'],
                    'page_id' => (int) $row['page_id'],
                    'edit_url' => '/admin/contact-form.php?section='
                        . urlencode((string) $row['page_slug'] . ':' . (string) $row['section_key']),
                    'block' => SectionRegistry::label('contact_form'),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[FormUsage] could not determine usage of form #' . $formId . ': ' . $e->getMessage());

            // An unknown answer must not read as "unused": that would let a
            // deletion through precisely when the check failed.
            return [[
                'page_title' => 'Onbekend — gebruik kon niet worden bepaald',
                'page_id' => 0,
                'edit_url' => '',
                'block' => '',
            ]];
        }

        return $placements;
    }

    /**
     * The `contact_form` blocks pointing at this form. Their table is
     * queried here rather than through a repository method of its own
     * because this is the only question anybody asks it about forms, and
     * the join is identical to FormBlockRepository::placementsOf().
     *
     * @return array<int, array<string, mixed>>
     */
    private static function contactFormPlacements(int $formId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT c.page_slug, c.section_key, p.title AS page_title, p.id AS page_id
               FROM contact_form_sections c
               JOIN page_sections ps
                 ON ps.section_type = 'contact_form' AND ps.section_id = c.id
               JOIN pages p ON p.id = ps.page_id
              WHERE c.form_id = :form_id
              ORDER BY p.title ASC, c.section_key ASC"
        );
        $stmt->execute(['form_id' => $formId]);

        return $stmt->fetchAll();
    }

    public static function isPlaced(int $formId): bool
    {
        return self::placements($formId) !== [];
    }

    public static function submissionCount(int $formId): int
    {
        try {
            return (new FormSubmissionRepository())->countForForm($formId);
        } catch (\Throwable $e) {
            error_log('[FormUsage] could not count submissions of form #' . $formId . ': ' . $e->getMessage());

            // Same direction as above: unknown blocks the deletion.
            return 1;
        }
    }

    /**
     * Why this form may not be deleted, as sentences for the editor. An
     * empty list means it is safe to remove.
     *
     * @return list<string>
     */
    public static function deletionBlockers(int $formId): array
    {
        $blockers = [];

        $placements = self::placements($formId);
        if ($placements !== []) {
            $blockers[] = count($placements) === 1
                ? 'Dit formulier staat nog op een pagina. Haal het daar eerst weg.'
                : 'Dit formulier staat nog op ' . count($placements) . ' plekken. Haal het daar eerst weg.';
        }

        $submissions = self::submissionCount($formId);
        if ($submissions > 0) {
            $blockers[] = $submissions === 1
                ? 'Er is nog 1 bewaarde inzending. Verwijder die eerst als je het formulier echt wilt weggooien.'
                : 'Er zijn nog ' . $submissions . ' bewaarde inzendingen. Verwijder die eerst als je het formulier echt wilt weggooien.';
        }

        return $blockers;
    }

    public static function canBeDeleted(int $formId): bool
    {
        return self::deletionBlockers($formId) === [];
    }
}
