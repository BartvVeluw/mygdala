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

    /** The contrast a status MARK needs on its ground: a border, an outline (WCAG 1.4.11). */
    private const STATUS_MARK_CONTRAST = 3;

    /** The contrast a status MESSAGE needs on its ground or its wash (WCAG 1.4.3). */
    private const STATUS_TEXT_CONTRAST = 4.5;

    /** The alpha of a status wash over the ground, as core.css paints it. */
    private const STATUS_WASH = 0.12;

    /**
     * THE FORMULAS, as data: every derived property and how it is made, in
     * the order derive() returns them. One definition with two readers —
     * derive() below, and the live preview of the palette editor
     * (admin/assets/theme-admin.js, MygdalaTheme.derive()), which gets this
     * list from the page (recipe()) instead of carrying formulas of its own.
     * So the preview cannot compute a tint the website would not.
     *
     * An expression is one of:
     *   'primary' | 'background' | 'surface' | 'text'   a chosen colour
     *   '--color-…'                                     a property above it
     *   '#RRGGBB'                                       a literal colour
     *   ['lighten', expr, delta]                        shiftLightness()
     *   ['mix', expr, expr, weight]                     mix()
     *   ['channels', expr]                              channels(): 'r, g, b'
     *   ['readable', expr, ground, ratio]               readable()
     *
     * The keys match the derived defaults in assets/css/core.css one for one,
     * so a test can prove the two lists never drift apart.
     */
    private const RECIPE = [
        '--color-primary-rgb' => ['channels', 'primary'],
        '--color-primary-bright' => ['lighten', 'primary', self::BRIGHT_LIFT],
        '--color-primary-bright-rgb' => ['channels', '--color-primary-bright'],
        '--color-primary-deep' => ['lighten', 'primary', -self::DEEP_DROP],
        '--color-primary-deep-rgb' => ['channels', '--color-primary-deep'],
        '--color-text-rgb' => ['channels', 'text'],
        '--color-bg-deep' => ['lighten', 'background', -self::GROUND_DROP],
        '--color-bg-gradient-end' => ['lighten', 'background', -self::GRADIENT_DROP],
        '--color-scrim-rgb' => ['channels', ['mix', 'background', '#000000', self::SCRIM_TOWARDS_BLACK]],
        // The translucent veils are the ground and a raised panel seen
        // through blur, and the media scrim is the ground bleeding over a
        // photograph. All three therefore follow their own role rather
        // than getting darker: on a light theme they become light, which
        // is what keeps the text on top of them readable.
        '--color-bg-veil-rgb' => ['channels', 'background'],
        '--color-media-scrim-rgb' => ['channels', 'background'],
        '--color-surface-veil-rgb' => ['channels', 'surface'],
        '--color-surface-hover' => ['mix', 'surface', 'primary', self::SURFACE_HOVER_TOWARDS_PRIMARY],
        '--color-surface-2' => ['mix', 'surface', 'background', self::SURFACE_2_TOWARDS_BG],
        // The light a surface catches (a section's sheen, a hover inside a
        // menu panel) is the text colour at a low alpha: light on a dark
        // theme, dark on a light one, where pure white would vanish. The
        // default theme keeps the white core.css declares.
        '--color-sheen-rgb' => ['channels', 'text'],
        // Status keeps its meaning in every palette (an error red, a success
        // green) and keeps the shipped shade wherever that shade is readable
        // — the default theme and any dark ground. Only where it is not, on
        // a light ground, does it move along lightness, hue kept, until it
        // is: on the ground and on a card, since a message sits on either.
        // A mark (border, outline) needs 3:1, a message 4.5:1; the text on a
        // wash is measured against that wash.
        '--color-danger' => ['readable', ['readable', '#E2685C', 'background', self::STATUS_MARK_CONTRAST], 'surface', self::STATUS_MARK_CONTRAST],
        '--color-danger-rgb' => ['channels', '--color-danger'],
        // An error message also sits on a faint error wash (the
        // personalizer, a remove button's hover), so it is measured there.
        '--color-danger-text' => [
            'readable',
            ['readable', '#F0897E', ['mix', 'background', '--color-danger', self::STATUS_WASH], self::STATUS_TEXT_CONTRAST],
            ['mix', 'surface', '--color-danger', self::STATUS_WASH],
            self::STATUS_TEXT_CONTRAST,
        ],
        '--color-danger-text-rgb' => ['channels', '--color-danger-text'],
        '--color-danger-on-wash' => [
            'readable',
            ['readable', '#F5B4AC', ['mix', 'background', '--color-danger', self::STATUS_WASH], self::STATUS_TEXT_CONTRAST],
            ['mix', 'surface', '--color-danger', self::STATUS_WASH],
            self::STATUS_TEXT_CONTRAST,
        ],
        '--color-success' => ['readable', ['readable', '#78B482', 'background', self::STATUS_MARK_CONTRAST], 'surface', self::STATUS_MARK_CONTRAST],
        '--color-success-rgb' => ['channels', '--color-success'],
        '--color-success-on-wash' => [
            'readable',
            ['readable', '#B7E0C0', ['mix', 'background', '--color-success', self::STATUS_WASH], self::STATUS_TEXT_CONTRAST],
            ['mix', 'surface', '--color-success', self::STATUS_WASH],
            self::STATUS_TEXT_CONTRAST,
        ],
    ];

    /** The status colours: they follow the ground and the card they sit on. */
    private const STATUS = [
        '--color-danger',
        '--color-danger-rgb',
        '--color-danger-text',
        '--color-danger-text-rgb',
        '--color-danger-on-wash',
        '--color-success',
        '--color-success-rgb',
        '--color-success-on-wash',
    ];

    /** The four chosen colours an expression may name. */
    public const ROLES = ['primary', 'background', 'surface', 'text'];

    /**
     * Every derived value, keyed by the CSS custom property that carries it:
     * RECIPE, evaluated for these four colours.
     *
     * @param array{primary: string, background: string, surface: string, text: string} $colors
     * @return array<string, string>
     */
    public static function derive(array $colors): array
    {
        $out = [];

        foreach (self::RECIPE as $property => $expression) {
            $out[$property] = self::evaluate($expression, $colors, $out);
        }

        return $out;
    }

    /**
     * The formulas for a reader outside PHP (the live preview), as plain
     * data that json_encode() prints: [[property, expression], …] in order.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public static function recipe(): array
    {
        $list = [];
        foreach (self::RECIPE as $property => $expression) {
            $list[] = [$property, $expression];
        }

        return $list;
    }

    /**
     * @param array<string, string> $colors
     * @param array<string, string> $done the properties computed so far
     */
    private static function evaluate(mixed $expression, array $colors, array $done): string
    {
        if (is_string($expression)) {
            if (in_array($expression, self::ROLES, true)) {
                return $colors[$expression];
            }

            return $done[$expression] ?? $expression;
        }

        [$op] = $expression;

        return match ($op) {
            'lighten' => self::shiftLightness(self::evaluate($expression[1], $colors, $done), (float) $expression[2]),
            'mix' => self::mix(
                self::evaluate($expression[1], $colors, $done),
                self::evaluate($expression[2], $colors, $done),
                (float) $expression[3]
            ),
            'channels' => self::channels(self::evaluate($expression[1], $colors, $done)),
            'readable' => self::readable(
                self::evaluate($expression[1], $colors, $done),
                self::evaluate($expression[2], $colors, $done),
                (float) $expression[3]
            ),
        };
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
                ...self::STATUS,
            ],
            'surface' => [
                '--color-surface-hover',
                '--color-surface-veil-rgb',
                '--color-surface-2',
                ...self::STATUS,
            ],
            'text' => [
                '--color-text-rgb',
                '--color-sheen-rgb',
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
     * $hex itself when it already reaches $ratio against $ground; otherwise
     * the first colour of the same hue and saturation, a hundredth of
     * lightness at a time away from the ground (darker on a ground that
     * black text reads better on, lighter on one white reads better on),
     * that does — or the end of that axis. Never a light/dark switch of
     * shades: a colour that is readable is left exactly as it is.
     */
    public static function readable(string $hex, string $ground, float $ratio): string
    {
        if (ThemeColor::contrastRatio($hex, $ground) >= $ratio) {
            return $hex;
        }

        $step = ThemeColor::contrastRatio($ground, '#000000') >= ThemeColor::contrastRatio($ground, '#FFFFFF') ? -0.01 : 0.01;
        [$h, $s, $l] = self::toHsl($hex);
        $candidate = $hex;

        for ($i = 1; $i <= 100; $i++) {
            $lightness = max(0.0, min(1.0, $l + $step * $i));
            $candidate = self::fromHsl($h, $s, $lightness);

            if (ThemeColor::contrastRatio($candidate, $ground) >= $ratio || $lightness === 0.0 || $lightness === 1.0) {
                break;
            }
        }

        return $candidate;
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
