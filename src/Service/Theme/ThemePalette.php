<?php

namespace App\Service\Theme;

/**
 * Colour arithmetic: given the five colours an administrator picks, work out
 * every tint the stylesheets need.
 *
 * WHY THIS IS PHP AND NOT CSS. assets/css/core.css already derives
 * everything it can in plain CSS — the borders, washes, muted text and the
 * glow are rgba() on top of a channel triplet, so they follow a new colour
 * with no server involvement. The rest are tints (a lighter accent, a hover
 * surface, a deeper ground). Writing those in CSS needs color-mix() or
 * relative colours, and this project has no build step, no autoprefixer and
 * a shared-hosting deployment, so it does not want a browser floor it cannot
 * test against. Doing the arithmetic here costs one small class and emits
 * plain hex that every browser understands.
 *
 * The formulas express the intent behind the original palette rather than
 * reproducing it exactly: the precise Van Veluw tints stay in core.css as
 * the defaults, and a site that changed nothing emits no override at all
 * (see ThemeCss), so these formulas only ever run for a theme that already
 * differs.
 *
 * Everything here is pure: no database, no settings lookup, no state.
 */
final class ThemePalette
{
    /** How much lighter the emphasis/hover accent is than the accent. */
    private const BRIGHT_LIFT = 0.14;

    /** How much darker the recessed accent (numerals, faint marks) is. */
    private const DEEP_DROP = 0.19;

    /** How much darker the footer/capability ground is than the page ground. */
    private const GROUND_DROP = 0.018;

    /** How much darker the foot of the fixed body gradient is. */
    private const GRADIENT_DROP = 0.008;

    /** How far the hover surface leans towards the accent. */
    private const SURFACE_HOVER_TOWARDS_PRIMARY = 0.045;

    /** How far the alternate surface (inputs) leans back towards the ground. */
    private const SURFACE_2_TOWARDS_BG = 0.5;

    /** How close to black a modal/lightbox backdrop sits, whatever the ground. */
    private const SCRIM_TOWARDS_BLACK = 0.85;

    /**
     * Every derived value, keyed by the CSS custom property that carries it.
     * The keys match the derived defaults in assets/css/core.css one for one,
     * so a test can prove the two lists never drift apart.
     *
     * @param array{primary: string, background: string, surface: string, text: string} $colors
     * @return array<string, string>
     */
    public static function derive(array $colors): array
    {
        $primary = $colors['primary'];
        $background = $colors['background'];
        $surface = $colors['surface'];
        $text = $colors['text'];

        $bright = self::shiftLightness($primary, self::BRIGHT_LIFT);
        $deep = self::shiftLightness($primary, -self::DEEP_DROP);

        return [
            '--color-primary-rgb' => self::channels($primary),
            '--color-primary-bright' => $bright,
            '--color-primary-bright-rgb' => self::channels($bright),
            '--color-primary-deep' => $deep,
            '--color-primary-deep-rgb' => self::channels($deep),
            '--color-text-rgb' => self::channels($text),
            '--color-bg-deep' => self::shiftLightness($background, -self::GROUND_DROP),
            '--color-bg-gradient-end' => self::shiftLightness($background, -self::GRADIENT_DROP),
            '--color-scrim-rgb' => self::channels(self::mix($background, '#000000', self::SCRIM_TOWARDS_BLACK)),
            // The translucent veils are the ground and a raised panel seen
            // through blur, and the media scrim is the ground bleeding over a
            // photograph. All three therefore follow their own role rather
            // than getting darker: on a light theme they become light, which
            // is what keeps the text on top of them readable.
            '--color-bg-veil-rgb' => self::channels($background),
            '--color-media-scrim-rgb' => self::channels($background),
            '--color-surface-veil-rgb' => self::channels($surface),
            '--color-surface-hover' => self::mix($surface, $primary, self::SURFACE_HOVER_TOWARDS_PRIMARY),
            '--color-surface-2' => self::mix($surface, $background, self::SURFACE_2_TOWARDS_BG),
        ];
    }

    /**
     * Which derived properties depend on which chosen colour. ThemeCss uses
     * this to emit only what actually changed, so choosing one colour cannot
     * silently recompute a tint whose own source is still the default.
     *
     * @return array<string, list<string>> chosen colour => derived properties
     */
    public static function dependencies(): array
    {
        return [
            'primary' => [
                '--color-primary-rgb',
                '--color-primary-bright',
                '--color-primary-bright-rgb',
                '--color-primary-deep',
                '--color-primary-deep-rgb',
                '--color-surface-hover',
            ],
            'background' => [
                '--color-bg-deep',
                '--color-bg-gradient-end',
                '--color-scrim-rgb',
                '--color-bg-veil-rgb',
                '--color-media-scrim-rgb',
                '--color-surface-2',
            ],
            'surface' => [
                '--color-surface-hover',
                '--color-surface-veil-rgb',
                '--color-surface-2',
            ],
            'text' => [
                '--color-text-rgb',
            ],
        ];
    }

    /** '#C9A063' becomes '201, 160, 99' — the form rgba() needs. */
    public static function channels(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return $r . ', ' . $g . ', ' . $b;
    }

    /**
     * @return array{int, int, int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Straight sRGB interpolation; a weight of 1.0 returns $b unchanged. */
    public static function mix(string $a, string $b, float $weight): string
    {
        $weight = max(0.0, min(1.0, $weight));
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);

        return self::hex(
            $ar + ($br - $ar) * $weight,
            $ag + ($bg - $ag) * $weight,
            $ab + ($bb - $ab) * $weight
        );
    }

    /**
     * Moves a colour along the HSL lightness axis, keeping its hue and
     * saturation. Positive lightens, negative darkens; the result is clamped
     * so a near-black ground cannot wrap around into white.
     */
    public static function shiftLightness(string $hex, float $delta): string
    {
        [$h, $s, $l] = self::toHsl($hex);

        return self::fromHsl($h, $s, max(0.0, min(1.0, $l + $delta)));
    }

    /**
     * @return array{float, float, float} hue in degrees, saturation and
     *                                    lightness in 0..1
     */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(static fn (int $c): float => $c / 255, self::rgb($hex));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        if ($max === $r) {
            $h = fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6);
        } elseif ($max === $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            return self::hex($l * 255, $l * 255, $l * 255);
        }

        $c = (1 - abs(2 * $l - 1)) * $s;
        $hp = fmod($h, 360) / 60;
        $x = $c * (1 - abs(fmod($hp, 2) - 1));
        $m = $l - $c / 2;

        $wedges = [
            [$c, $x, 0.0],
            [$x, $c, 0.0],
            [0.0, $c, $x],
            [0.0, $x, $c],
            [$x, 0.0, $c],
            [$c, 0.0, $x],
        ];
        [$r, $g, $b] = $wedges[((int) floor($hp)) % 6];

        return self::hex(($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
    }

    private static function hex(float $r, float $g, float $b): string
    {
        $clamp = static fn (float $c): int => (int) max(0, min(255, (int) round($c)));

        return sprintf('#%02X%02X%02X', $clamp($r), $clamp($g), $clamp($b));
    }
}
