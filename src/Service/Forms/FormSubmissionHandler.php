<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Mail\EmailIdentity;
use App\Mail\FormSubmissionBuilder;
use App\Repository\FormSubmissionRepository;
use App\Service\ContactAttachmentStorage;
use App\Service\Mailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * THE public form pipeline. Every submission this CMS accepts — from the
 * generic "Formulier" block and from the older `contact_form` block alike —
 * goes through this one method, so there is exactly one place where a form
 * is validated, stored and mailed.
 *
 *     spam guard  ->  validate  ->  store files  ->  persist  ->  notify  ->  record outcome
 *
 * FILES are answers of upload fields (FieldTypes\FileFieldType). They are
 * validated with every other answer and moved out of PHP's temporary
 * directory only once ALL of them passed; the submission row and its file
 * rows are one transaction; and a stored file that ends up with no row
 * pointing at it — a form that keeps nothing, a row that could not be
 * written — is deleted before the answer goes out. So no file outlives the
 * record of it, and none is kept that nobody can find.
 *
 * THE ORDER IS DELIBERATE, and it is the answer to "what happens when SMTP
 * is down". A form that stores its submissions writes the row BEFORE it
 * tries to send: a valid enquiry must not be lost because a mail server was
 * unreachable for thirty seconds. The e-mail is then a best-effort side
 * effect, and its outcome is recorded on the row
 * (`notification_sent_at`), so the admin list can show an enquiry that
 * arrived but was never mailed instead of quietly swallowing it.
 *
 * A form that does NOT store has nowhere to fall back to, so for it the
 * e-mail is the delivery: a send failure there is a real failure and the
 * visitor is told so, rather than being thanked for something nobody
 * received.
 *
 * NO QUEUE, NO RETRY, NO BACKGROUND WORKER. This runs on Vimexx shared
 * hosting, where there is no worker to run one — and a queue that only
 * exists to retry an e-mail would be a whole subsystem to keep alive.
 * FORMS.md says so plainly.
 *
 * NOTHING A VISITOR TYPED IS EVER LOGGED. Failures log the form, the
 * submission id and the technical reason; the answers stay in the database
 * and in the e-mail, which are the two places the owner already controls.
 */
final class FormSubmissionHandler
{
    /**
     * The most file data one notification carries, before base64. Past it,
     * a file is named in the e-mail but not attached (FORMS.md, "E-mail"):
     * common mail servers refuse a message of 25 MB, and base64 makes 15 MB
     * about 20.
     *
     * THE one definition of this limit. FormValidator applies it too, to a
     * form that keeps no submissions: there the e-mail is the only delivery,
     * so files that together exceed it are refused before anything is
     * stored or sent, rather than lost.
     */
    public const MAIL_ATTACHMENT_BUDGET = 15 * 1024 * 1024;

    /** @var (\Closure(): ContactAttachmentStorage) */
    private readonly \Closure $storage;

    /**
     * @param (\Closure(): ContactAttachmentStorage)|null $storage where accepted files go; a test hands in its own
     */
    public function __construct(
        private readonly FormValidator $validator = new FormValidator(),
        private readonly FormSpamGuard $spamGuard = new FormSpamGuard(),
        ?\Closure $storage = null,
    ) {
        $this->storage = $storage ?? static fn (): ContactAttachmentStorage => new ContactAttachmentStorage();
    }

    /**
     * @param array<string, mixed> $request usually $_POST
     * @param array<string, mixed> $files   usually $_FILES
     */
    public function handle(FormDefinition $form, array $request, FormSubmissionContext $context, array $files = []): FormSubmissionOutcome
    {
        if (!$form->isRenderable()) {
            // Somebody posted to a form that is switched off or has no
            // fields. Not an error worth explaining to them.
            return FormSubmissionOutcome::rejected(true);
        }

        $verdict = $this->spamGuard->inspectRequest($request);
        if ($verdict === FormSpamGuard::VERDICT_SILENT_DISCARD) {
            return FormSubmissionOutcome::rejected(true);
        }

        if (!$this->spamGuard->allowsAnotherAttempt($context->ip)) {
            return FormSubmissionOutcome::rejected(false);
        }

        $validation = $this->validator->validate($form, $request, $files);
        if (!$validation->isValid()) {
            // Nothing was written: an accepted file is still in PHP's
            // temporary directory, which PHP empties after the request.
            return FormSubmissionOutcome::invalid($validation);
        }

        $snapshot = $this->validator->snapshot($form, $validation->values);

        // Only now, with every field passed, does a file leave PHP's
        // temporary directory.
        $storage = $validation->uploads === [] ? null : ($this->storage)();
        $stored = $storage === null ? [] : $this->storeUploads($form, $storage, $validation->uploads);
        if ($stored === null) {
            return FormSubmissionOutcome::failed('storage');
        }

        $submissionId = null;

        try {
            if ($form->storesSubmissions) {
                $submissionId = $this->persist($form, $snapshot, $context, $stored);

                if ($submissionId === null) {
                    return FormSubmissionOutcome::failed('storage');
                }
            }

            $sent = $this->notify($form, $snapshot, $context, $submissionId, $stored);
        } finally {
            // A stored file that no submission row points at would be an
            // orphan nothing can find or delete: a form that does not keep
            // its submissions (the e-mail was the delivery), or a submission
            // that could not be written. Removed before the answer goes out.
            if ($submissionId === null && $storage !== null) {
                foreach ($stored as $file) {
                    $storage->delete($file['stored_filename']);
                }
            }
        }

        if (!$sent && $submissionId === null) {
            // Nothing was stored and nothing was sent: the enquiry does not
            // exist anywhere, so the visitor must not be told it arrived.
            return FormSubmissionOutcome::failed('delivery');
        }

        return FormSubmissionOutcome::accepted($submissionId, $sent);
    }

    /**
     * Moves every accepted file into the storage outside the webroot, under
     * a random name with the extension of what its bytes are. All or
     * nothing: when one cannot be moved, the ones already moved are removed
     * again and null is returned.
     *
     * @param array<string, FormUpload> $uploads by field key
     * @return list<array{field_key: string, stored_filename: string, original_filename: string, mime: string, size: int, sha256: string, path: string, mail_name: string}>|null
     */
    private function storeUploads(FormDefinition $form, ContactAttachmentStorage $storage, array $uploads): ?array
    {
        $stored = [];

        try {
            // In the form's own field order, so the e-mail and the admin list
            // the files the way the form asked for them.
            foreach ($form->fields as $field) {
                $upload = $uploads[$field->key] ?? null;
                if ($upload === null) {
                    continue;
                }

                $filename = $storage->store($upload->tmpPath, $upload->storedExtension());
                $stored[] = [
                    'field_key' => $field->key,
                    'stored_filename' => $filename,
                    'original_filename' => $upload->originalName,
                    'mime' => $upload->mime(),
                    'size' => $upload->size,
                    'sha256' => $upload->sha256,
                    'path' => $storage->path($filename),
                    // A fixed, generic name for the mail: the field's own key,
                    // never what the visitor called the file.
                    'mail_name' => $field->key . '.' . $upload->storedExtension(),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[FormSubmissionHandler] could not store a file for form #' . $form->id . ': ' . $e->getMessage());

            foreach ($stored as $file) {
                $storage->delete($file['stored_filename']);
            }

            return null;
        }

        return $stored;
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     * @param list<array{field_key: string, stored_filename: string, original_filename: string, mime: string, size: int, sha256: string}> $stored
     * @return int|null the submission id, or null when it could not be stored
     */
    private function persist(FormDefinition $form, array $snapshot, FormSubmissionContext $context, array $stored): ?int
    {
        try {
            return (new FormSubmissionRepository())->create($form->id, $form->name, $context->sourcePath, $snapshot, $stored);
        } catch (\Throwable $e) {
            error_log('[FormSubmissionHandler] could not store a submission for form #' . $form->id . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Which stored files go into the notification as real attachments: in
     * field order, as long as the total stays within
     * MAIL_ATTACHMENT_BUDGET. The rest are named in the e-mail instead.
     *
     * @param list<array{field_key: string, size: int, path: string, mail_name: string, mime: string}> $stored
     * @return array{0: list<array{path: string, name: string, mime: string}>, 1: list<string>} the attachments, and the field keys left out
     */
    public static function mailAttachments(array $stored): array
    {
        $attachments = [];
        $leftOut = [];
        $total = 0;

        foreach ($stored as $file) {
            if ($total + $file['size'] > self::MAIL_ATTACHMENT_BUDGET) {
                $leftOut[] = $file['field_key'];
                continue;
            }

            $total += $file['size'];
            $attachments[] = ['path' => $file['path'], 'name' => $file['mail_name'], 'mime' => $file['mime']];
        }

        return [$attachments, $leftOut];
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     * @param list<array{field_key: string, size: int, path: string, mail_name: string, mime: string}> $stored
     */
    private function notify(
        FormDefinition $form,
        array $snapshot,
        FormSubmissionContext $context,
        ?int $submissionId,
        array $stored
    ): bool {
        $recipient = FormRecipient::forForm($form);

        if ($recipient === null) {
            error_log(
                '[FormSubmissionHandler] form #' . $form->id . ' has no valid notification address and'
                . ' Instellingen has no usable contact address either; no notification was sent.'
            );

            return false;
        }

        [$attachments, $leftOut] = self::mailAttachments($stored);

        $content = FormSubmissionBuilder::build($form, $snapshot, [
            'source_path' => $context->sourcePath,
            'submitted_at' => date('d-m-Y H:i'),
            'attachment_names' => array_map(static fn (array $file): string => $file['name'], $attachments),
            'not_attached' => $leftOut,
            'kept_in_cms' => $submissionId !== null,
        ]);

        [$replyToEmail, $replyToName] = $this->replyTo($form, $snapshot);

        try {
            (new Mailer())->send(
                $recipient,
                EmailIdentity::name(),
                $content['subject'],
                $content['html'],
                $content['text'],
                $replyToEmail,
                $replyToName,
                $attachments
            );
        } catch (PHPMailerException $e) {
            error_log(
                '[FormSubmissionHandler] notification failed for form #' . $form->id
                . ($submissionId !== null ? ' (submission #' . $submissionId . ')' : '')
                . ': ' . Mailer::redactCredentials($e->getMessage())
            );

            return false;
        } catch (\Throwable $e) {
            error_log('[FormSubmissionHandler] notification failed for form #' . $form->id . ': ' . $e->getMessage());

            return false;
        }

        if ($submissionId !== null) {
            try {
                (new FormSubmissionRepository())->setNotificationSentAt($submissionId);
            } catch (\Throwable $e) {
                // The e-mail went out; only the bookkeeping failed. Log it
                // and carry on — this must not turn a success into an error.
                error_log('[FormSubmissionHandler] could not record notification for submission #' . $submissionId . ': ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * The visitor's own address as Reply-To, when the form names an e-mail
     * field for it and that field holds a valid address.
     *
     * THE VISITOR NEVER CONTROLS FROM. The sender stays the site's own
     * identity (App\Mail\EmailIdentity), because that is the address the
     * mail provider is allowed to send as and the one SPF/DKIM cover. A
     * Reply-To only decides where the owner's reply goes, and a malformed
     * or missing address simply means no Reply-To at all rather than a
     * broken header.
     *
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     * @return array{0: ?string, 1: ?string}
     */
    private function replyTo(FormDefinition $form, array $snapshot): array
    {
        $field = $form->replyToField();
        if ($field === null) {
            return [null, null];
        }

        $address = null;
        foreach ($snapshot as $value) {
            if ($value['field_key'] === $field->key) {
                $address = $value['value'];
                break;
            }
        }

        $address = FormRecipient::validAddress($address);
        if ($address === null) {
            return [null, null];
        }

        return [$address, $this->replyToName($form, $snapshot)];
    }

    /**
     * A display name for the Reply-To: the first single-line text answer the
     * form has, which on an ordinary contact form is the visitor's name. It
     * is only ever cosmetic — an empty one is perfectly fine — so there is
     * no setting for it.
     *
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     */
    private function replyToName(FormDefinition $form, array $snapshot): ?string
    {
        foreach ($form->fields as $field) {
            if ($field->type->key() !== 'text') {
                continue;
            }

            foreach ($snapshot as $value) {
                if ($value['field_key'] === $field->key && $value['value'] !== '') {
                    return mb_substr($value['value'], 0, 100);
                }
            }
        }

        return null;
    }
}
