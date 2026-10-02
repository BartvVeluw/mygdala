<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * Turns a button style (App\Service\Theme\ButtonStyles) into CSS: a set of
 * `--btn-*` custom properties that assets/css/core.css's .btn reads, and
 * the one <style id="site-buttons"> a page prints. See THEMING.md,
 * "Knopstijlen".
 *
 * ONE MODEL, THREE PLACES. core.css draws every .btn from the same
 * properties and declares the shipped look as their values (LEGACY_PRIMARY
 * on .btn, LEGACY_SECONDARY on .btn--ghost). This class emits:
 *
 *   1. the website's standard button (the `primary` default) as the
 *      DIFFERENCE from LEGACY_PRIMARY, on every plain .btn;
 *   2. the second button (the `secondary` default) as the difference from
 *      LEGACY_SECONDARY, on every .btn--ghost;
 *   3. every style a content button has chosen, in full, as
 *      `.btn.btn-style-<id>`.
 *
 * So a site whose two defaults still look as shipped prints nothing for 1 and
 * 2, a site where no block chose a style prints nothing for 3, and twenty
 * blocks that chose the same style share one rule. A style that nobody uses
 * costs nothing.
 *
 * THEME COLOURS FOLLOW THE THEME. A theme colour is written as
 * `var(--color-…)` and declared on the button itself, so it is resolved
 * where the button is: the active palette on the site, the page theme's
 * colours inside `main[data-page-theme]`, the site's again in the header and
 * footer. A fixed colour is a #RRGGBB literal and stays what it is. The fonts
 * are `var(--font-body)` / `var(--font-display)`, so a Font Library family
 * and a page theme's pairing apply to buttons like to text.
 *
 * NOTHING IS FREE TEXT. Every value comes from the closed maps below, a
 * colour word from COLORS or a #RRGGBB that passed ThemeColor::normalise(),
 * an icon from ButtonIcons; every value takes ThemeCss::isSafeValue() on the
 * way out as a second lock. The class name carries only the style's integer
 * id. The CMS preview (admin/assets/button-style-admin.js) composes the same
 * properties from recipe() — the same maps, printed as data — so the preview
 * and the website cannot use two different sets of values.
 */
final class ButtonStyleCss
{
    /** The theme colours a style may use, by word. */
    public const COLORS = [
        'primary' => 'var(--color-primary)',
        'primary_bright' => 'var(--color-primary-bright)',
        'primary_deep' => 'var(--color-primary-deep)',
        'primary_wash' => 'var(--color-primary-wash)',
        'on_primary' => 'var(--color-on-primary)',
        'text' => 'var(--color-text)',
        'text_muted' => 'var(--color-text-muted)',
        'background' => 'var(--color-bg)',
        'surface' => 'var(--color-surface)',
        'line_strong' => 'var(--color-line-strong)',
    ];

    /**
     * Where a colour word is the label (text and hover text) rather than a
     * fill or a border, the bright highlight is the accent as text: the
     * same colour wherever the highlight reads (the default theme, any dark
     * palette), and on a light palette the shade that does read, on the
     * ground and on the accent wash a hover lays over it
     * (--color-primary-text in assets/css/core.css).
     */
    public const TEXT_COLORS = [
        'primary_bright' => 'var(--color-primary-text)',
    ] + self::COLORS;

    /** Shape => border-radius. `pill` and `rounded` were the old Knopvorm. */
    public const SHAPES = [
        'square' => '0px',
        'soft' => 'var(--radius-sm)',
        'rounded' => 'var(--radius-md)',
        'round' => 'var(--radius-lg)',
        'pill' => '999px',
    ];

    /** Size => [padding-block, padding-inline, font-size]. `compact` = .btn--sm. */
    public const SIZES = [
        'compact' => ['0.7rem', '1.4rem', '0.85rem'],
        'normal' => ['0.95rem', '1.9rem', '0.95rem'],
        'large' => ['1.15rem', '2.4rem', '1.05rem'],
    ];

    /** A text button has no surface to pad. */
    public const TEXT_PADDING = ['0.2em', '0px'];

    /**
     * Border => width. "none" keeps the 1.5px as transparent, exactly like
     * the shipped .btn, so a filled and an outlined button side by side are
     * the same height.
     */
    public const BORDERS = [
        'none' => '1.5px',
        'thin' => '1px',
        'normal' => '1.5px',
        'thick' => '2.5px',
    ];

    public const SHADOWS = [
        'none' => 'none',
        'subtle' => '0 2px 8px -3px rgba(0,0,0,0.35)',
        'normal' => 'var(--shadow-soft)',
        'strong' => 'var(--shadow-lift)',
    ];

    public const WEIGHTS = [
        'normal' => '400',
        'semibold' => '600',
        'bold' => '700',
    ];

    public const FONTS = [
        'body' => 'var(--font-body)',
        'heading' => 'var(--font-display)',
    ];

    public const GAPS = [
        'small' => '0.4rem',
        'normal' => '0.6rem',
        'large' => '0.9rem',
    ];

    /**
     * Hover => [lift, shadow, filter]. A null shadow is the style's own
     * shadow (no change); `glow` is the shipped primary button's hover.
     */
    public const HOVERS = [
        'none' => ['0px', null, 'none'],
        'lift' => ['-2px', null, 'none'],
        'glow' => ['-2px', 'var(--color-glow)', 'brightness(1.06)'],
        'shadow' => ['-2px', 'var(--shadow-lift)', 'none'],
        'brighten' => ['0px', null, 'brightness(1.08)'],
    ];

    /** How far a pointing icon moves on hover. */
    public const ICON_SHIFT = '3px';

    /** The shipped primary button's surface: a sheen over the primary colour. */
    public const PRIMARY_GRADIENT = 'linear-gradient(155deg, var(--color-primary-bright), var(--color-primary) 55%, var(--color-primary-deep))';

    /** The same sheen for any other colour, laid over it. */
    public const SHEEN = 'linear-gradient(155deg, rgba(255,255,255,0.2), rgba(255,255,255,0) 55%, rgba(0,0,0,0.16))';

    /**
     * What core.css declares on .btn: the shipped button, word for word. The
     * radius is the website's shape token, which ThemeCss sets from the
     * primary default (see declarationsFor()).
     */
    public const LEGACY_PRIMARY = [
        '--btn-bg' => self::PRIMARY_GRADIENT,
        '--btn-fg' => 'var(--color-on-primary)',
        '--btn-border-width' => '1.5px',
        '--btn-border-color' => 'transparent',
        '--btn-radius' => 'var(--button-radius)',
        '--btn-pad-y' => '0.95rem',
        '--btn-pad-x' => '1.9rem',
        '--btn-font-size' => '0.95rem',
        '--btn-font' => 'var(--font-body)',
        '--btn-weight' => '700',
        '--btn-case' => 'none',
        '--btn-tracking' => '0.01em',
        '--btn-decoration' => 'none',
        '--btn-shadow' => 'none',
        '--btn-gap' => '0.6rem',
        '--btn-hover-lift' => '-2px',
        '--btn-hover-shadow' => 'var(--color-glow)',
        '--btn-hover-filter' => 'brightness(1.06)',
        '--btn-hover-bg' => 'var(--btn-bg)',
        '--btn-hover-fg' => 'var(--btn-fg)',
        '--btn-hover-border-color' => 'var(--btn-border-color)',
        '--btn-icon' => 'none',
        '--btn-icon-before' => 'none',
        '--btn-icon-after' => 'none',
        '--btn-icon-shift' => '0px',
    ];

    /** What core.css adds on .btn--ghost: the shipped second button. */
    public const LEGACY_SECONDARY_OVERRIDES = [
        '--btn-bg' => 'transparent',
        '--btn-fg' => 'var(--color-text)',
        '--btn-border-color' => 'var(--color-line-strong)',
        '--btn-hover-shadow' => 'none',
        '--btn-hover-filter' => 'none',
        '--btn-hover-bg' => 'var(--color-primary-wash)',
        '--btn-hover-fg' => 'var(--color-primary-text)',
        '--btn-hover-border-color' => 'var(--color-primary)',
    ];

    /**
     * The complete property set of one style, in a stable order.
     *
     * @param array<string, mixed> $style a validated style (ButtonStyles::shape())
     * @return array<string, string>
     */
    public static function declarations(array $style): array
    {
        $appearance = (string) $style['appearance'];
        $isText = $appearance === 'text';
        [$padY, $padX, $fontSize] = self::SIZES[$style['size']] ?? self::SIZES['normal'];
        if ($isText) {
            [$padY, $padX] = self::TEXT_PADDING;
        }

        $out = [];
        $out['--btn-bg'] = $appearance === 'filled' ? self::fill((string) $style['fill_color'], (bool) $style['fill_gradient']) : 'transparent';
        $out['--btn-fg'] = self::textColor((string) $style['text_color']);

        if ($isText) {
            $out['--btn-border-width'] = '0px';
            $out['--btn-border-color'] = 'transparent';
        } else {
            $border = (string) $style['border_width'];
            $out['--btn-border-width'] = self::BORDERS[$border] ?? self::BORDERS['none'];
            $out['--btn-border-color'] = $border === 'none' ? 'transparent' : self::color((string) $style['border_color']);
        }

        $out['--btn-radius'] = self::SHAPES[$style['shape']] ?? self::SHAPES['pill'];
        $out['--btn-pad-y'] = $padY;
        $out['--btn-pad-x'] = $padX;
        $out['--btn-font-size'] = $fontSize;
        $out['--btn-font'] = self::FONTS[$style['font_role']] ?? self::FONTS['body'];
        $out['--btn-weight'] = self::WEIGHTS[$style['font_weight']] ?? self::WEIGHTS['bold'];
        $out['--btn-case'] = $style['uppercase'] ? 'uppercase' : 'none';
        $out['--btn-tracking'] = $style['uppercase'] ? '0.08em' : '0.01em';
        $out['--btn-decoration'] = $isText && $style['underline'] ? 'underline' : 'none';

        $shadow = $isText ? 'none' : (self::SHADOWS[$style['shadow']] ?? 'none');
        $out['--btn-shadow'] = $shadow;
        $out['--btn-gap'] = self::GAPS[$style['icon_gap']] ?? self::GAPS['normal'];

        [$lift, $hoverShadow, $filter] = self::HOVERS[$style['hover_effect']] ?? self::HOVERS['none'];
        $out['--btn-hover-lift'] = $lift;
        $out['--btn-hover-shadow'] = $hoverShadow ?? $shadow;
        $out['--btn-hover-filter'] = $filter;
        $out['--btn-hover-bg'] = self::optionalColor($style['hover_fill_color'] ?? null, 'var(--btn-bg)');
        $out['--btn-hover-fg'] = self::optionalColor($style['hover_text_color'] ?? null, 'var(--btn-fg)', true);
        $out['--btn-hover-border-color'] = $isText ? 'var(--btn-border-color)' : self::optionalColor($style['hover_border_color'] ?? null, 'var(--btn-border-color)');

        $icon = (string) $style['icon'];
        $mask = ButtonIcons::maskUrl($icon);
        $before = $mask !== null && $style['icon_position'] === 'before';
        $out['--btn-icon'] = $mask ?? 'none';
        $out['--btn-icon-before'] = $mask !== null && $before ? '""' : 'none';
        $out['--btn-icon-after'] = $mask !== null && !$before ? '""' : 'none';
        $direction = $mask !== null && $style['icon_motion'] ? ButtonIcons::direction($icon) : 0;
        $out['--btn-icon-shift'] = $direction === 0 ? '0px' : ($direction < 0 ? '-' : '') . self::ICON_SHIFT;

        return array_filter($out, static fn (string $value): bool => ThemeCss::isSafeValue($value));
    }

    /** @return array<string, string> the shipped second button, complete */
    public static function legacySecondary(): array
    {
        return array_replace(self::LEGACY_PRIMARY, self::LEGACY_SECONDARY_OVERRIDES);
    }

    /**
     * The block for a page: the two defaults as differences, then every
     * style in $usedIds in full. Empty when there is nothing to say.
     *
     * @param array<string, mixed>|null $primary the primary default style
     * @param array<string, mixed>|null $secondary the secondary default style
     * @param array<int, array<string, mixed>> $used id => style, every style a content button chose
     */
    public static function styleBlock(?array $primary, ?array $secondary, array $used): string
    {
        $rules = '';

        $primaryShape = $primary !== null ? (string) $primary['shape'] : 'pill';

        // A default with an icon of its own replaces the old inline arrow of
        // the buttons that fall back to it (ButtonStyles::classes()).
        if ($primary !== null) {
            $rules .= self::rule('.btn:where(:not(.btn--ghost, .btn--on-dark))', self::difference($primary, self::LEGACY_PRIMARY, $primaryShape));
            if ($primary['icon'] !== 'none') {
                $rules .= self::rule('.btn:where(:not(.btn--ghost, .btn--on-dark)) > .btn__arrow', ['display' => 'none']);
            }
        }

        if ($secondary !== null) {
            $rules .= self::rule('.btn--ghost', self::difference($secondary, self::legacySecondary(), $primaryShape));
            if ($secondary['icon'] !== 'none') {
                $rules .= self::rule('.btn--ghost > .btn__arrow', ['display' => 'none']);
            }
        }

        ksort($used);
        foreach ($used as $id => $style) {
            $rules .= self::rule('.btn.' . self::className((int) $id), self::declarations($style));
        }

        return $rules === '' ? '' : '<style id="site-buttons">' . "\n" . $rules . "</style>\n";
    }

    /** The class a chosen style adds to .btn. Only ever an integer id. */
    public static function className(int $id): string
    {
        return 'btn-style-' . $id;
    }

    /**
     * The maps the CMS preview composes from, as data (the editor prints it
     * for admin/assets/button-style-admin.js).
     *
     * @return array<string, mixed>
     */
    public static function recipe(): array
    {
        $icons = [];
        foreach (ButtonIcons::keys() as $key) {
            $icons[$key] = ['mask' => ButtonIcons::maskUrl($key), 'direction' => ButtonIcons::direction($key)];
        }

        return [
            'colors' => self::COLORS,
            'textColors' => self::TEXT_COLORS,
            'shapes' => self::SHAPES,
            'sizes' => self::SIZES,
            'textPadding' => self::TEXT_PADDING,
            'borders' => self::BORDERS,
            'shadows' => self::SHADOWS,
            'weights' => self::WEIGHTS,
            'fonts' => self::FONTS,
            'gaps' => self::GAPS,
            'hovers' => self::HOVERS,
            'iconShift' => self::ICON_SHIFT,
            'primaryGradient' => self::PRIMARY_GRADIENT,
            'sheen' => self::SHEEN,
            'icons' => $icons,
        ];
    }

    /**
     * A colour word or a fixed #RRGGBB as a CSS value. An unknown word
     * (never stored: ButtonStyles validates) falls back to the primary.
     */
    public static function color(string $value): string
    {
        if (isset(self::COLORS[$value])) {
            return self::COLORS[$value];
        }

        return ThemeColor::normalise($value) ?? self::COLORS['primary'];
    }

    /** A colour word or a fixed #RRGGBB as the colour of the label. */
    public static function textColor(string $value): string
    {
        return self::TEXT_COLORS[$value] ?? self::color($value);
    }

    private static function optionalColor(mixed $value, string $unchanged, bool $isText = false): string
    {
        if (!is_string($value) || $value === '') {
            return $unchanged;
        }

        return $isText ? self::textColor($value) : self::color($value);
    }

    /**
     * A filled surface. The sheen on the primary colour is the shipped
     * button's gradient, token for token; on any other colour a translucent
     * sheen is laid over the flat colour.
     */
    private static function fill(string $color, bool $gradient): string
    {
        if (!$gradient) {
            return self::color($color);
        }

        return $color === 'primary' ? self::PRIMARY_GRADIENT : self::SHEEN . ', ' . self::color($color);
    }

    /**
     * The properties of a default style that differ from what core.css
     * already declares. The radius of the primary default is the website's
     * shape token (--button-radius, printed by ThemeCss through
     * ThemeSettings' button_shape), so it is never repeated here; the second
     * button follows that token too unless its own shape differs.
     *
     * @param array<string, mixed> $style
     * @param array<string, string> $shipped
     * @return array<string, string>
     */
    private static function difference(array $style, array $shipped, string $primaryShape): array
    {
        $declarations = self::declarations($style);

        if ((string) $style['shape'] === $primaryShape) {
            unset($declarations['--btn-radius']);
        }

        return array_diff_assoc($declarations, $shipped);
    }

    /** @param array<string, string> $declarations property => value */
    private static function rule(string $selector, array $declarations): string
    {
        if ($declarations === []) {
            return '';
        }

        $body = '';
        foreach ($declarations as $property => $value) {
            $body .= '  ' . $property . ': ' . $value . ";\n";
        }

        return $selector . "{\n" . $body . "}\n";
    }
}
