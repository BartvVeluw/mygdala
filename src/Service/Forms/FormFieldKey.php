<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * The name a field posts under, and the key its answer is filed away as.
 *
 * Deliberately narrow: lowercase a-z, 0-9 and single hyphens, 1-64
 * characters, never starting or ending with a hyphen. That is enough to be a
 * readable HTML `name`, a readable column value and a readable line in a
 * notification e-mail, while ruling out everything that would make any of
 * those ambiguous — `[`/`]` (which PHP would turn into a nested array),
 * whitespace, dots and every non-ASCII character.
 *
 * It is generated FROM the Dutch label rather than typed, so an editor never
 * meets it; the collision suffix keeps it unique inside one form. A key is
 * only ever created here, and every posted field name is matched against the
 * keys the form definition already holds — a request can hit or miss a known
 * key, and a miss is ignored (FORMS.md, "Nooit blind $_POST opslaan").
 */
final class FormFieldKey
{
    public const MAX_LENGTH = 64;

    /**
     * Field names this CMS uses for the form's own machinery. A generated
     * key may never collide with one, or a bot-detection control would
     * arrive as an ordinary answer.
     *
     * @var list<string>
     */
    public const RESERVED = ['form-key', 'form-ts', 'form-instance', 'hp-note', 'csrf_token', 'bestand'];

    public static function isValid(string $key): bool
    {
        if ($key === '' || strlen($key) > self::MAX_LENGTH) {
            return false;
        }

        if (in_array($key, self::RESERVED, true)) {
            return false;
        }

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) === 1;
    }

    /**
     * Turns a label into a key, and makes it unique against the keys the
     * form already has.
     *
     * @param list<string> $taken keys already in use in this form
     */
    public static function fromLabel(string $label, array $taken = []): string
    {
        $base = self::slugify($label);

        if ($base === '' || in_array($base, self::RESERVED, true)) {
            $base = 'veld';
        }

        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $taken, true) || in_array($candidate, self::RESERVED, true)) {
            $tail = '-' . $suffix;
            $candidate = substr($base, 0, self::MAX_LENGTH - strlen($tail)) . $tail;
            $suffix++;
        }

        return $candidate;
    }

    private static function slugify(string $label): string
    {
        $value = mb_strtolower(trim($label), 'UTF-8');

        // Fold the accented characters Dutch, German and French labels
        // actually contain; everything else that is not a-z0-9 becomes a
        // separator rather than being transliterated by guesswork.
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ß' => 'ss',
        ]);

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return substr($value, 0, self::MAX_LENGTH);
    }
}
