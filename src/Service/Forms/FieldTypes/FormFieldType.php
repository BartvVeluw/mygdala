<?php

declare(strict_types=1);

namespace App\Service\Forms\FieldTypes;

use App\Service\Forms\FormField;
use App\Service\Forms\FormText;

/**
 * ONE kind of form field, in ONE file: what the admin calls it, what it
 * accepts, how it validates and how it renders.
 *
 * The same idea as App\Service\Blocks\BlockDefinition, for the same reason.
 * Before this existed, "which fields does the contact form have" was
 * answered three times over — once as markup in
 * partials/section-contact-form.php, once as validation in api/contact.php
 * and once as e-mail rows in App\Mail\ContactRequestBuilder — and adding a
 * field meant editing all three with nothing to warn you if you missed one.
 * Here a type answers each question exactly once, and
 * App\Service\Forms\FormFieldTypes is the closed list of the types that
 * exist. Neither the renderer, the validator nor the admin contains a
 * `switch` over field types.
 *
 * The four methods that differ per type are abstract on purpose: a new type
 * that forgets one does not load. Everything else has a safe default, so a
 * plain text-like type is a very small class.
 *
 * A type is stateless and instantiated once per request by the registry. It
 * must never be constructed from request data — `field_type` out of a
 * database row can only hit or miss a registered key.
 */
abstract class FormFieldType
{
    /** The registry key, equal to what `form_fields.field_type` stores. */
    abstract public function key(): string;

    /** What the CMS calls this type in the "veldtype" dropdown. */
    abstract public function label(): string;

    /**
     * Echoes the control itself — the `<input>`, `<textarea>`, `<select>` or
     * group of radios. The surrounding `.form-field`, its `<label>`, its
     * hint and its error message are the same for every type and belong to
     * partials/form.php, which never asks what type it is rendering.
     */
    abstract public function renderControl(FormFieldControl $control): void;

    /**
     * Turns whatever arrived in the request into the single, clean string
     * this field stores. Called before validate(); it must accept anything
     * at all (an array, an int, null) and never throw.
     */
    abstract public function normalize(mixed $raw, FormField $field): string;

    /**
     * The type's own rule, applied AFTER the shared "required" and
     * "too long" rules that FormValidator applies to every field. Returns
     * the bilingual message to show beside the field, or null when the value
     * is acceptable. An empty value has already been handled by then: an
     * optional field that was left blank never reaches this method.
     */
    public function validate(string $value, FormField $field): ?FormText
    {
        return null;
    }

    /**
     * What the visitor is told when a required field of this type is empty.
     * The shared rule lives in App\Service\Forms\FormValidator; only the
     * wording is the type's, because "Naam is verplicht" and "Zet een vinkje
     * bij Voorwaarden" are the same rule said properly.
     */
    public function requiredMessage(FormField $field): FormText
    {
        return FormText::of(
            $field->label->nl . ' is verplicht.',
            $field->label->en . ' is required.'
        );
    }

    /** Whether this type is configured with a list of choices. */
    public function usesOptions(): bool
    {
        return false;
    }

    /**
     * Whether one of this type's options may be pre-selected when the form is
     * first shown.
     *
     * Only a CHOICE field, and deliberately so. A pre-filled text box holds an
     * answer the visitor never typed and will happily send it; what an empty
     * text field wants is a placeholder, which every text-like type already
     * has. A pre-ticked consent box would be worse still — consent nobody
     * gave. Picking one of a handful of visible, mutually exclusive options is
     * the one case where starting somewhere is a kindness rather than a
     * fabricated answer, and the visitor can always pick another.
     *
     * The value is always one of the field's own options; nothing may be
     * stored, rendered or accepted that the list does not contain.
     */
    public function usesDefaultValue(): bool
    {
        return $this->usesOptions();
    }

    /** Whether a placeholder means anything for this type. */
    public function usesPlaceholder(): bool
    {
        return true;
    }

    /**
     * The longest value this type accepts. Not a security boundary on its
     * own — the column is the real one — but it keeps a hostile POST from
     * turning a name field into a novel, and it is what the visitor is told.
     */
    public function maxLength(): int
    {
        return 255;
    }

    /**
     * True for a type whose "required" is not a choice: a consent checkbox
     * that is optional consents to nothing. The admin renders its checkbox
     * as fixed rather than pretending the setting does something.
     */
    public function requiredIsFixed(): bool
    {
        return false;
    }

    /**
     * Whether a valid value of this type is an e-mail address, and so
     * whether this field may be offered as the notification's Reply-To
     * (FORMS.md, "Reply-To").
     */
    public function holdsEmailAddress(): bool
    {
        return false;
    }

    /**
     * Where the `<label>` goes. 'before' for everything that has a caption
     * above it; 'wrap' for a checkbox, whose label sits beside the box and
     * is clickable as one unit.
     */
    public function labelPosition(): string
    {
        return 'before';
    }

    /**
     * The `autocomplete` token a browser should use, or null for none. Only
     * set where it is unambiguous: guessing wrong is worse than not guessing
     * (a "Voor wie is de aanvraag?" radio is not an address).
     */
    public function autocomplete(): ?string
    {
        return null;
    }

    /**
     * Strips control characters (CR/LF included, so no answer can ever add a
     * header to the outgoing e-mail), trims, and caps the length. Every
     * type's normalize() goes through this — it is the one place submitted
     * text is made safe to put in a mail header, a database row or a page.
     */
    protected function clean(mixed $raw, int $maxLength): string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }

        if (!is_string($raw)) {
            return '';
        }

        // EVERY control character, tabs and line breaks included. This is the
        // SINGLE-LINE cleaner, and a CR or an LF surviving it is exactly what
        // would let an answer add a header to the outgoing e-mail. They
        // become a space rather than nothing, so "regel\r\ntwee" reads as two
        // words instead of one.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw) ?? '';
        $value = preg_replace('/ {2,}/u', ' ', $value) ?? $value;
        $value = trim($value);

        return mb_strlen($value) > $maxLength ? trim(mb_substr($value, 0, $maxLength)) : $value;
    }

    /**
     * The same as clean(), but keeps the newlines a textarea is allowed to
     * contain while still removing every other control character (a bare CR
     * is normalised to LF so stored text does not depend on the browser).
     */
    protected function cleanMultiline(mixed $raw, int $maxLength): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $raw);
        $value = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);

        return mb_strlen($value) > $maxLength ? trim(mb_substr($value, 0, $maxLength)) : $value;
    }

    /**
     * The `placeholder` attribute plus the `data-nl-placeholder` /
     * `data-en-placeholder` pair assets/js/core.js swaps when the visitor
     * picks a language — the same convention every other bilingual attribute
     * on this site uses. Returns '' when the field has no placeholder, or
     * when the type has no use for one.
     */
    protected function placeholderAttributes(FormFieldControl $control): string
    {
        $placeholder = $control->field->placeholder;

        if (!$this->usesPlaceholder() || $placeholder->isEmpty()) {
            return '';
        }

        return ' placeholder="' . $control->escape($placeholder->nl) . '"'
            . ' data-nl-placeholder="' . $control->escape($placeholder->nl) . '"'
            . ' data-en-placeholder="' . $control->escape($placeholder->en) . '"';
    }

    protected function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
