<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * Everything about ONE submission that is not one of the form's own answers:
 * where it came from and who sent it.
 *
 * Until Forms 2.0 phase 2 this also carried the one file the `contact_form`
 * block accepted beside the form. A file is an answer now — the answer of an
 * upload field (App\Service\Forms\FieldTypes\FileFieldType) — so it travels
 * with the others, through FormValidator, and nothing here knows about files.
 */
final class FormSubmissionContext
{
    public function __construct(
        /** Same-site, already validated path the form was submitted from. */
        public readonly ?string $sourcePath = null,
        /** The visitor's IP, used only as a salted hash for rate limiting. */
        public readonly string $ip = '',
    ) {
    }
}
