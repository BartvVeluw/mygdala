<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * Everything one RENDERED form instance needs beyond the definition itself:
 * which instance it is, what the visitor last typed, which fields are
 * showing an error, and whether we are showing a success message instead of
 * a form.
 *
 * The instance token is what makes the same form usable twice on one page
 * (FORMS.md, "Eén definitie, meerdere plaatsingen"). It is derived from the
 * BLOCK instance — `(page_slug, section_key)`, the identity every block in
 * this CMS is addressed by (CONTENT-BLOCKS.md) — and every DOM id the form
 * prints is prefixed with it. Two blocks showing form #3 therefore have no
 * id, no `<label for>` and no error anchor in common, and the endpoint knows
 * which of the two to send the visitor back to.
 *
 * It is a HASH rather than the slug and key themselves, so a page slug never
 * ends up in a DOM id or a query string, and so the token is always a short,
 * safe `[a-z0-9-]` string whatever the section key contains.
 */
final class FormRenderState
{
    /** @param array<string, string> $errors one message per field, in the language the form was filled in */
    private function __construct(
        public readonly string $token,
        public readonly array $values,
        public readonly array $errors,
        public readonly bool $showSuccess,
    ) {
    }

    /** A fresh, empty form. */
    public static function fresh(string $token): self
    {
        return new self($token, [], [], false);
    }

    /** The success message instead of the form. */
    public static function success(string $token): self
    {
        return new self($token, [], [], true);
    }

    /**
     * A form that came back with errors, rebuilt from what
     * App\Service\Forms\PublicFormSession remembered.
     *
     * @param array<string, string> $values
     * @param array<string, string> $messages one per field, in the language the form was filled in
     */
    public static function withErrors(string $token, array $values, array $messages): self
    {
        $errors = [];
        foreach ($messages as $key => $message) {
            if (is_string($key) && is_string($message)) {
                $errors[$key] = $message;
            }
        }

        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }

        return new self($token, $clean, $errors, false);
    }

    /**
     * The state one block instance should render in, decided from the query
     * string the visitor was redirected back with:
     *
     *   ?form-status=success&form=<token>   they just submitted successfully
     *   ?form-status=error&form=<token>     it failed; the answers and the
     *                                       messages are waiting in the
     *                                       public session
     *   anything else                       an ordinary, empty form
     *
     * The token in the query must match THIS instance, so a page carrying
     * two forms only ever shows the outcome on the one that was submitted.
     *
     * @param array<string, mixed>|null $query defaults to $_GET
     */
    public static function forInstance(string $pageSlug, string $sectionKey, ?array $query = null): self
    {
        $token = self::tokenFor($pageSlug, $sectionKey);
        $query ??= $_GET;

        $status = $query['form-status'] ?? null;
        $target = $query['form'] ?? null;

        if (!is_string($status) || !is_string($target) || $target !== $token) {
            return self::fresh($token);
        }

        if ($status === 'success') {
            return self::success($token);
        }

        if ($status !== 'error') {
            return self::fresh($token);
        }

        $flash = PublicFormSession::takeFailure($token);
        if ($flash === null) {
            // The session expired, or somebody typed the URL. An empty form
            // is the honest answer; inventing an error would be worse.
            return self::fresh($token);
        }

        return self::withErrors($token, $flash['values'], $flash['errors']);
    }

    /**
     * The token for one block instance: short, stable and free of anything
     * that would need escaping in an id or a URL.
     */
    public static function tokenFor(string $pageSlug, string $sectionKey): string
    {
        return 'form-' . substr(hash('sha256', $pageSlug . ':' . $sectionKey), 0, 10);
    }

    /**
     * What a control starts out holding:
     *
     *     wat de bezoeker instuurde  →  de ingestelde standaardwaarde  →  leeg
     *
     * THE VISITOR ALWAYS WINS, and "submitted" is decided by whether the key
     * is PRESENT, not by whether it is non-empty. That distinction is the
     * whole point: after a failed submission the validator has recorded a
     * value for every field the form has, including the ones left blank — so
     * a visitor who deliberately moved away from a default, or cleared a
     * dropdown, gets their own answer back rather than the default silently
     * reasserting itself.
     *
     * A fresh form has recorded nothing at all, so there the default applies.
     */
    public function valueFor(FormField $field): string
    {
        if (array_key_exists($field->key, $this->values)) {
            return $this->values[$field->key];
        }

        return $field->defaultValue;
    }

    /** The raw remembered value, with no default behind it. */
    public function submittedValueFor(string $fieldKey): ?string
    {
        return $this->values[$fieldKey] ?? null;
    }

    public function errorFor(string $fieldKey): ?string
    {
        return $this->errors[$fieldKey] ?? null;
    }

    /**
     * The key of an error that belongs to the whole form rather than to one
     * field: a request PHP refused before any field could be read (larger
     * than post_max_size, api/form-submit.php). It starts with a hyphen, so
     * it can never be a field key (FormFieldKey::isValid()).
     */
    public const FORM_ERROR = '-form';

    /** The error that belongs to the whole form, if there is one. */
    public function formError(): ?string
    {
        return $this->errors[self::FORM_ERROR] ?? null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** A DOM id inside this instance — always unique on the page. */
    public function id(string $suffix): string
    {
        return $this->token . '-' . $suffix;
    }
}
