<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Database;

/**
 * Whether a form is allowed to receive a file at all.
 *
 * Forms V1 has no upload FIELD and the form builder cannot create one
 * (FORMS.md). The only reason a file may arrive is the older `contact_form`
 * block, which has accepted a photo or a PDF since long before Core Forms
 * existed and must not lose it. So the answer is not "did the request say
 * so" — a visitor could say anything — but "does a `contact_form` block on
 * this site point at this form with its attachment option switched on".
 *
 * That makes it a CONFIGURATION question with a server-side answer. Posting
 * a file to a form nobody placed in such a block is simply ignored: the
 * submission is processed without it, no file is written and no error is
 * reported, because there was never a field to attach it to.
 */
final class FormAttachmentPolicy
{
    /** @var array<int, bool> per-request cache; one query per form at most */
    private static array $cache = [];

    public static function allowsAttachment(FormDefinition $form): bool
    {
        if (isset(self::$cache[$form->id])) {
            return self::$cache[$form->id];
        }

        try {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM contact_form_sections
                  WHERE form_id = :form_id AND allow_attachment = 1 AND is_active = 1
                  LIMIT 1'
            );
            $stmt->execute(['form_id' => $form->id]);

            return self::$cache[$form->id] = $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            error_log('[FormAttachmentPolicy] could not check form #' . $form->id . ': ' . $e->getMessage());

            // Refusing is the safe direction: a submission without its
            // attachment still reaches the owner, an unchecked upload does
            // not have a safe failure mode.
            return self::$cache[$form->id] = false;
        }
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
