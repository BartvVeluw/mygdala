<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * What a theme colour IS, in one place: the one shape a colour may have on
 * its way into a stylesheet, and how readable two colours are together.
 *
 * Shared by every theme this project has — the site theme
 * (App\Service\Theme\ThemeSettings) and a page theme
 * (App\Service\Theme\PageAppearance, the Paginathema's module) — so a colour
 * is validated by exactly one rule, whichever screen it was typed on.
 *
 * Pure: no database, no settings, no state. See THEMING.md.
 */
final class ThemeColor
{
    /**
     * The contrast WCAG 2.x asks for ordinary text (level AA). Below this the
     * CMS warns — it never refuses: a brand colour is the owner's decision,
     * and a warning they can read is worth more than a save they cannot make.
     */
    public const MIN_TEXT_CONTRAST = 4.5;

    /**
     * Accepts #RGB and #RRGGBB, with or without the hash, in either case,
     * and returns the single canonical form #RRGGBB in uppercase.
     *
     * Rejects everything else outright. This is a security boundary, not a
     * convenience: the return value is interpolated into a stylesheet, so
     * "colour" has to mean six hex digits and nothing else — not a keyword,
     * not url(), not var(), not calc(), and not a value with a semicolon
     * behind it carrying a second declaration.
     */
    public static function normalise(string $value): ?string
    {
        $value = ltrim(trim($value), '#');

        if (preg_match('/^[0-9A-Fa-f]{3}$/', $value) === 1) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        if (preg_match('/^[0-9A-Fa-f]{6}$/', $value) !== 1) {
            return null;
        }

        return '#' . strtoupper($value);
    }

    /**
     * The WCAG 2.x relative luminance of a #RRGGBB colour, 0 (black) to 1
     * (white). admin/assets/page-theme-admin.js carries the same formula for
     * the live warning in the editor; the server-rendered warning uses this.
     */
    public static function relativeLuminance(string $hex): float
    {
        $channels = array_map(
            static function (int $channel): float {
                $c = $channel / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            ThemePalette::rgb($hex)
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * The WCAG contrast ratio of two colours, 1 (none) to 21 (black on
     * white). Symmetric: which of the two is the text does not matter.
     */
    public static function contrastRatio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
