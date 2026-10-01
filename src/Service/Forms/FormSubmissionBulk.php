<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Database;
use App\Repository\FormSubmissionRepository;
use App\Service\ContactAttachmentStorage;

/**
 * One action on a selection of stored submissions: mark them read, mark them
 * unread, or delete them for good (FORMS.md, "Inzendingen in bulk").
 *
 * ALL OR NOTHING. The whole selection is checked before anything changes,
 * and one id that is malformed, unknown or outside the request's form is
 * enough to refuse the lot (FormSubmissionBulkRefused). Quietly acting on
 * the part that happened to be valid would make "7 inzendingen verwijderd"
 * mean something different every time. The checks and the change run in one
 * transaction, with the selected rows locked, so another tab deleting one of
 * them in between cannot turn the check into a lie.
 *
 * WHO MAY is not decided here. `forms.submissions` is one permission over
 * every stored submission (FORMS.md, "Rechten"); the endpoint checks it
 * before calling this, the same as for a single delete. What this class
 * adds is the SCOPE: a request made from the overview filtered to one form
 * names that form, and every selected submission must belong to it. A
 * forged id from elsewhere is refused, not acted on.
 *
 * FILES LAST. Deleting removes the rows (values and attachment rows
 * cascade) and commits; only then are the files removed, exactly in the
 * order api/admin/delete-form-submission.php uses for one. A file that
 * cannot be removed is logged by submission number and nothing else: no
 * answer, no file name a visitor chose (FORMS.md, "Privacy").
 *
 * No logging of what a submission says, ever, and the result is a number.
 */
final class FormSubmissionBulk
{
    public const MARK_READ = 'mark_read';
    public const MARK_UNREAD = 'mark_unread';
    public const DELETE = 'delete';

    public const ACTIONS = [self::MARK_READ, self::MARK_UNREAD, self::DELETE];

    /**
     * The most ids one request may carry. The overview selects at most one
     * page (FormSubmissionRepository::PAGE_SIZE), so a person never meets
     * this; it only stops a forged request from naming the whole table.
     */
    public const MAX_IDS = 100;

    /**
     * Both are made only once a request has passed every check that needs no
     * database, so a refused action or a malformed id never opens one.
     */
    public function __construct(
        private ?FormSubmissionRepository $submissions = null,
        private ?ContactAttachmentStorage $storage = null,
    ) {
    }

    /**
     * The submitted `ids[]` as distinct positive integers, in the order they
     * were sent. Every entry must be one: "3", 3 and "+3" pass, "3abc",
     * "-1", "0", "1.5", an array or an empty string refuse the request, as
     * does anything that is not a list in the first place. Duplicates are
     * folded, not refused: a double-click is not an attack.
     *
     * @return list<int>
     */
    public static function normalizeIds(mixed $submitted): array
    {
        if ($submitted === null || $submitted === '' || $submitted === []) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::NOTHING_SELECTED);
        }

        if (!is_array($submitted)) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::MALFORMED);
        }

        $ids = [];
        foreach ($submitted as $value) {
            $id = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

            if ($id === false || $id < 1) {
                throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::MALFORMED);
            }

            $ids[$id] = $id;
        }

        if (count($ids) > self::MAX_IDS) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::TOO_MANY);
        }

        return array_values($ids);
    }

    /**
     * Runs one action on the whole selection, or on none of it.
     *
     * @param list<int> $ids     already normalized (normalizeIds())
     * @param int|null  $formId  the form the overview was filtered to, or null for all forms
     * @return int how many submissions the action applied to
     *
     * @throws FormSubmissionBulkRefused when the request is refused; nothing changed
     */
    public function apply(string $action, array $ids, ?int $formId = null): int
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::UNKNOWN_ACTION);
        }

        if ($ids === []) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::NOTHING_SELECTED);
        }

        if (count($ids) > self::MAX_IDS) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::TOO_MANY);
        }

        if ($formId !== null && $formId < 1) {
            throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::MALFORMED);
        }

        $this->submissions ??= new FormSubmissionRepository();
        $this->storage ??= new ContactAttachmentStorage();

        $db = Database::connection();
        $attachments = [];
        $db->beginTransaction();

        try {
            $forms = $this->submissions->formIdsForUpdate($ids);

            if (count($forms) !== count($ids)) {
                throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::NOT_FOUND);
            }

            if ($formId !== null) {
                foreach ($forms as $belongsTo) {
                    if ($belongsTo !== $formId) {
                        throw new FormSubmissionBulkRefused(FormSubmissionBulkRefused::OUTSIDE_SCOPE);
                    }
                }
            }

            if ($action === self::DELETE) {
                $attachments = $this->submissions->attachmentsForMany($ids);
                $this->submissions->deleteMany($ids);
            } else {
                $this->submissions->setReadStateForMany($ids, $action === self::MARK_READ);
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        foreach ($attachments as $attachment) {
            try {
                $this->storage->delete((string) $attachment['stored_filename']);
            } catch (\Throwable $e) {
                error_log('[FormSubmissionBulk] could not remove a file of submission #' . (int) $attachment['submission_id'] . ': ' . $e->getMessage());
            }
        }

        return count($ids);
    }
}
