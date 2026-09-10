<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * What happened to one submission, in the four shapes a caller has to tell
 * apart (FORMS.md, "Foutafhandeling"):
 *
 *   ACCEPTED     validated, stored if the form stores, and the notification
 *                either went out or was logged as failed — the visitor is
 *                thanked either way, because their enquiry WAS received.
 *   INVALID      field errors to show, with what they typed still in the
 *                boxes.
 *   REJECTED     spam or rate limit. Deliberately vague to the outside: it
 *                never says which layer caught it.
 *   FAILED       the submission could not be safely received at all — the
 *                database is down, or the form does not store and the e-mail
 *                could not be sent. Never reported as a success.
 */
final class FormSubmissionOutcome
{
    public const ACCEPTED = 'accepted';
    public const INVALID = 'invalid';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?FormValidationResult $validation = null,
        public readonly ?int $submissionId = null,
        public readonly bool $notificationSent = false,
        /** A short, non-technical reason code — never an exception message. */
        public readonly string $reason = '',
    ) {
    }

    public static function accepted(?int $submissionId, bool $notificationSent): self
    {
        return new self(self::ACCEPTED, null, $submissionId, $notificationSent);
    }

    public static function invalid(FormValidationResult $validation): self
    {
        return new self(self::INVALID, $validation, null, false, 'validation');
    }

    /**
     * A spam or rate-limit rejection. $silent means "answer exactly like a
     * success": the honeypot and the minimum-submit-time never tell a bot
     * which of them fired.
     */
    public static function rejected(bool $silent): self
    {
        return new self(self::REJECTED, null, null, false, $silent ? 'silent' : 'rate_limit');
    }

    public static function failed(string $reason = 'server'): self
    {
        return new self(self::FAILED, null, null, false, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->status === self::ACCEPTED;
    }

    /**
     * Whether the visitor should be shown the success message — true for a
     * real success AND for a silent spam rejection, which is the entire
     * point of a honeypot.
     */
    public function looksSuccessfulToTheVisitor(): bool
    {
        return $this->isAccepted() || ($this->status === self::REJECTED && $this->reason === 'silent');
    }
}
