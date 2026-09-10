<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Mail\EmailIdentity;
use App\Mail\FormSubmissionBuilder;
use App\Repository\FormSubmissionRepository;
use App\Service\Mailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * THE public form pipeline. Every submission this CMS accepts — from the
 * generic "Formulier" block and from the older `contact_form` block alike —
 * goes through this one method, so there is exactly one place where a form
 * is validated, stored and mailed.
 *
 *     spam guard  ->  validate  ->  persist  ->  notify  ->  record outcome
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
    public function __construct(
        private readonly FormValidator $validator = new FormValidator(),
        private readonly FormSpamGuard $spamGuard = new FormSpamGuard(),
    ) {
    }

    /**
     * @param array<string, mixed> $request usually $_POST
     */
    public function handle(FormDefinition $form, array $request, FormSubmissionContext $context): FormSubmissionOutcome
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

        $validation = $this->validator->validate($form, $request);
        if (!$validation->isValid()) {
            return FormSubmissionOutcome::invalid($validation);
        }

        $snapshot = $this->validator->snapshot($form, $validation->values);

        $submissionId = null;
        if ($form->storesSubmissions) {
            $submissionId = $this->persist($form, $snapshot, $context);

            if ($submissionId === null) {
                return FormSubmissionOutcome::failed('storage');
            }
        }

        $sent = $this->notify($form, $snapshot, $context, $submissionId);

        if (!$sent && $submissionId === null) {
            // Nothing was stored and nothing was sent: the enquiry does not
            // exist anywhere, so the visitor must not be told it arrived.
            return FormSubmissionOutcome::failed('delivery');
        }

        return FormSubmissionOutcome::accepted($submissionId, $sent);
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     * @return int|null the submission id, or null when it could not be stored
     */
    private function persist(FormDefinition $form, array $snapshot, FormSubmissionContext $context): ?int
    {
        try {
            $repository = new FormSubmissionRepository();
            $submissionId = $repository->create($form->id, $form->name, $context->sourcePath, $snapshot);

            if ($context->attachment !== null) {
                $repository->attachFile(
                    $submissionId,
                    $context->attachment['stored_filename'],
                    $context->attachment['original_filename'],
                    $context->attachment['mime'],
                    $context->attachment['size']
                );
            }

            return $submissionId;
        } catch (\Throwable $e) {
            error_log('[FormSubmissionHandler] could not store a submission for form #' . $form->id . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $snapshot
     */
    private function notify(
        FormDefinition $form,
        array $snapshot,
        FormSubmissionContext $context,
        ?int $submissionId
    ): bool {
        $recipient = FormRecipient::forForm($form);

        if ($recipient === null) {
            error_log(
                '[FormSubmissionHandler] form #' . $form->id . ' has no valid notification address and'
                . ' Site-instellingen has no usable contact address either; no notification was sent.'
            );

            return false;
        }

        $content = FormSubmissionBuilder::build($form, $snapshot, [
            'source_path' => $context->sourcePath,
            'submitted_at' => date('d-m-Y H:i'),
            'attachment_name' => $context->attachmentName(),
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
                $context->mailAttachments()
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
