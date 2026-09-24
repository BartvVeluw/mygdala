<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * How wide a field sits in its form: one of six fixed shares of a row, and
 * nothing else (FORMS.md, "Breedte van een veld").
 *
 * A CLOSED LIST, for the same reason as the field types: the value comes
 * from an admin form and from a database row, and all it may ever do is hit
 * or miss a key here. It is never a pixel count, a percentage, a class name
 * or a style: partials/form.php turns a key into one of six fixed classes,
 * and assets/css/blocks/form.css says how many of twelve columns each one
 * spans. Missing is `full`.
 *
 * The key names a SHARE, not a column count, so the grid underneath could
 * change without a single stored row changing with it.
 *
 * STRUCTURE, NOT WORDS. A width is the same in every website language, so it
 * lives on `form_fields` itself (column `layout_width`), next to the type and
 * the required switch, and never in a translation table.
 *
 * On a narrow screen every width is a full row: see form.css for where, and
 * why that is the breakpoint the rest of the form already used.
 */
final class FormFieldWidth
{
    public const FULL = 'full';

    /**
     * Key => columns out of twelve, widest first: the order the field editor
     * offers them in.
     */
    private const SPANS = [
        'full' => 12,
        'three_quarters' => 9,
        'two_thirds' => 8,
        'half' => 6,
        'third' => 4,
        'quarter' => 3,
    ];

    /** Every key, widest first. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SPANS);
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::SPANS);
    }

    /**
     * What a stored value means when it is read: itself when it is a key,
     * `full` for anything else — an empty column, a value from a later
     * version, a hand-edited row. The editor refuses an unknown key instead
     * (api/admin/update-form-field.php); a reader never can.
     */
    public static function fromStored(mixed $value): string
    {
        return self::isValid($value) ? $value : self::FULL;
    }

    /** Columns out of twelve. */
    public static function span(string $key): int
    {
        return self::SPANS[self::fromStored($key)];
    }

    /**
     * The one class partials/form.php prints for a width. `full` keeps the
     * class full-width fields have always had (.form-field--full, shared with
     * the checkout in assets/css/core.css).
     */
    public static function cssClass(string $key): string
    {
        return 'form-field--' . str_replace('_', '-', self::fromStored($key));
    }

    /**
     * The width a field showed before widths could be chosen, when
     * partials/form.php decided it from the type: a one-line text, e-mail or
     * telephone field sat half a row wide, everything else took the whole
     * row. Used once, by the migration that gave every existing field its
     * width, so no stored form looks different afterwards — and by nothing
     * that renders.
     */
    public static function formerDefaultFor(string $typeKey): string
    {
        return in_array($typeKey, ['text', 'email', 'tel'], true) ? 'half' : self::FULL;
    }
}
