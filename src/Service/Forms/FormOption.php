<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * One choice of a select or radio field: what is SUBMITTED and what is SHOWN,
 * kept apart (Multilingual 2.0 phase 4, FORMS.md "Opties: waarde en label").
 *
 *   value  the option's identity, language-neutral and stable: what the
 *          browser posts, what validation compares, what a submission stores
 *          and what `form_fields.default_value` names. It is set once, when
 *          the option is created, from its label in the website's default
 *          language, and nothing changes it afterwards: not renaming the
 *          label, not translating it, not a visitor switching language.
 *   label  the words a visitor reads, in the language of the request
 *          of the public language switch.
 *
 * An option that existed before phase 4 kept its Dutch label as its value,
 * byte for byte, because that is what its submissions and its default had
 * always held (db/migrations/20260918150000).
 */
final class FormOption
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {
    }
}
