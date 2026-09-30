<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * The icons a button style may show next to its text (Button Styles 2.0):
 * a CLOSED list of 24x24 line drawings, chosen by key. Nothing an
 * administrator enters becomes SVG, HTML or a URL — a style stores a key,
 * and a key that is not below is refused on save and ignored on render.
 *
 * An icon is drawn by CSS, not printed in the markup: ButtonStyleCss puts
 * the drawing in `--btn-icon` as a data URI and core.css paints it as a
 * mask on the button's ::before or ::after, in the button's own text colour
 * (so it follows the theme, the hover colour and a page theme like the
 * text does). A pseudo-element with empty content is not read out, which is
 * exactly right for a decorative icon: the button's text is the whole
 * accessible name, with or without the icon.
 *
 * The drawings are the ones the site already uses (the arrow of every
 * primary content button, the cart of "Toevoegen aan winkelwagen"), plus a
 * few common ones in the same stroke.
 */
final class ButtonIcons
{
    /**
     * Key => the path data. Stroked, never filled, like every icon on the
     * site; the mask only uses the shape.
     *
     * @var array<string, string>
     */
    private const PATHS = [
        'arrow_right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow_left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'chevron_right' => '<path d="M9 6l6 6-6 6"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'cart' => '<path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/>',
    ];

    /** Icons that point somewhere: they move that way on hover. */
    private const POINTING = [
        'arrow_right' => 1,
        'arrow_left' => -1,
        'chevron_right' => 1,
        'external' => 1,
    ];

    /** @return list<string> every key, 'none' first */
    public static function keys(): array
    {
        return ['none', ...array_keys(self::PATHS)];
    }

    public static function isValid(string $key): bool
    {
        return $key === 'none' || array_key_exists($key, self::PATHS);
    }

    /**
     * The direction the icon moves on hover: 1 (right), -1 (left) or 0 for
     * an icon that does not point anywhere (a plus, a download).
     */
    public static function direction(string $key): int
    {
        return self::POINTING[$key] ?? 0;
    }

    /**
     * The icon as a CSS value for `mask-image`: url("data:…"), fully
     * percent-encoded, so it contains none of the characters
     * ThemeCss::isSafeValue() refuses. Null for 'none' and unknown keys.
     */
    public static function maskUrl(string $key): ?string
    {
        if (!array_key_exists($key, self::PATHS)) {
            return null;
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#000" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
            . self::PATHS[$key] . '</svg>';

        return 'url("data:image/svg+xml,' . rawurlencode($svg) . '")';
    }

    /**
     * The same drawing as inline SVG, for the CMS: the icon choice in the
     * button editor shows what it picks. Decorative (aria-hidden).
     */
    public static function inlineSvg(string $key): string
    {
        if (!array_key_exists($key, self::PATHS)) {
            return '';
        }

        return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . self::PATHS[$key] . '</svg>';
    }
}
