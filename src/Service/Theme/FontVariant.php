<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * The closed list of variants a font family in the Font Library can hold:
 * nine weights, each upright or italic. An administrator picks a KEY
 * ("700", "400-italic"); this file owns what that key means in CSS
 * (`font-weight: 700; font-style: italic`). Nobody types a weight code.
 *
 * The labels an administrator reads ("Vet", "Cursief", "Halfvet cursief")
 * are CMS texts: `fonts.weight_<weight>`, `fonts.italic` and
 * `fonts.italic_word` in
 * App\Service\Language\messages, put together by label(). The CSS values
 * never come from a label or from a request, only from this list.
 *
 * See THEMING.md, "Font Library".
 */
final class FontVariant
{
    public const WEIGHTS = [100, 200, 300, 400, 500, 600, 700, 800, 900];

    public const STYLES = ['normal', 'italic'];

    /** The variant a family is shown in on a card: the normal text weight. */
    public const REGULAR = '400';

    /** "700" or "700-italic": the one form a variant travels in. */
    public static function key(int $weight, string $style): string
    {
        return (string) $weight . ($style === 'italic' ? '-italic' : '');
    }

    /**
     * The weight and style behind a key, or null for anything that is not
     * exactly one of the eighteen keys.
     *
     * @return array{weight: int, style: string}|null
     */
    public static function parse(string $key): ?array
    {
        if (preg_match('/^([1-9]00)(-italic)?$/', $key, $match) !== 1) {
            return null;
        }

        return [
            'weight' => (int) $match[1],
            'style' => isset($match[2]) && $match[2] !== '' ? 'italic' : 'normal',
        ];
    }

    public static function isValid(int $weight, string $style): bool
    {
        return in_array($weight, self::WEIGHTS, true) && in_array($style, self::STYLES, true);
    }

    /**
     * Every key, upright weights first and then the italics, both light to
     * heavy — the order of the variant choice in the CMS.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];
        foreach (self::STYLES as $style) {
            foreach (self::WEIGHTS as $weight) {
                $keys[] = self::key($weight, $style);
            }
        }

        return $keys;
    }

    /**
     * What an administrator reads for a variant: "Normaal", "Vet",
     * "Cursief" (400 italic), "Vet cursief". The translator is passed in so
     * this class stays free of the CMS.
     *
     * @param callable(string, array<string, string|int>): string $translate
     */
    public static function label(int $weight, string $style, callable $translate): string
    {
        if ($style === 'italic' && $weight === 400) {
            return $translate('fonts.italic', []);
        }

        $name = $translate('fonts.weight_' . $weight, []);

        return $style === 'italic' ? $name . ' ' . $translate('fonts.italic_word', []) : $name;
    }
}
