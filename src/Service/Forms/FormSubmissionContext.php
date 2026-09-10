<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * Everything about ONE submission that is not one of the form's own answers:
 * where it came from, who sent it, and the optional file the legacy contact
 * block still allows.
 *
 * The attachment is the one deliberate seam in this pipeline. Forms V1 has
 * no upload FIELD and the form builder cannot create one (FORMS.md,
 * "Bewust niet ondersteund"); the `contact_form` block, which predates all
 * of this, has always accepted a photo or a PDF and must not lose it. So the
 * block validates and stores the file with the machinery it already had
 * (App\Service\ContactAttachmentValidator / ContactAttachmentStorage) and
 * hands the result over here — the handler records it and mails it, and
 * knows nothing else about files.
 */
final class FormSubmissionContext
{
    /**
     * @param array{stored_filename: string, original_filename: string, mail_name: string, mime: string, size: int, path: string}|null $attachment
     */
    public function __construct(
        /** Same-site, already validated path the form was submitted from. */
        public readonly ?string $sourcePath = null,
        /** The visitor's IP, used only as a salted hash for rate limiting. */
        public readonly string $ip = '',
        public readonly ?array $attachment = null,
    ) {
    }

    public function attachmentName(): ?string
    {
        return $this->attachment['mail_name'] ?? null;
    }

    /**
     * The attachment in the shape App\Service\Mailer::send() takes.
     *
     * @return list<array{path: string, name: string, mime: string}>
     */
    public function mailAttachments(): array
    {
        if ($this->attachment === null) {
            return [];
        }

        return [[
            'path' => $this->attachment['path'],
            'name' => $this->attachment['mail_name'],
            'mime' => $this->attachment['mime'],
        ]];
    }
}
