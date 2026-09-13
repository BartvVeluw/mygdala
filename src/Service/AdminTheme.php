<?php

namespace App\Service;

use App\Repository\AdminSettingRepository;

/**
 * What the CMS ITSELF looks like: one global choice out of four first-party
 * skins or the owner's own five colours, applied to every admin screen at
 * once.
 *
 * ## What this is not
 *
 * It is not App\Service\Theme\ThemeSettings. That owns the appearance of the
 * PUBLIC website — five colours, a font pairing, a button shape — and it can
 * be reset in one click. The two never touch: choosing a dark CMS says
 * nothing about the website, and "standaardvormgeving herstellen" on the
 * theme screen must not be able to restyle the panel the owner is standing
 * in. Different concept, different class, different table (`admin_settings`,
 * db/migrations/20260910120000).
 *
 * It is also not several admin interfaces. Every theme renders the same
 * sidebar, the same cards, tables, forms, page editor, block picker, save
 * bar and modals; a theme only re-points the semantic tokens at the top of
 * admin/assets/admin.css. There is exactly one admin stylesheet and exactly
 * one set of admin templates.
 *
 * ## The closed set
 *
 * THEMES below is the whole registry, and it is the only place a theme key
 * exists in PHP. A stored value that is not in it — an older row, a
 * hand-edited database, a crafted POST — falls back to `default` rather than
 * reaching an HTML attribute. The palettes of the fixed themes are
 * deliberately NOT here: they live in admin.css, one `[data-admin-theme="..."]`
 * block per key, so nobody has to keep a palette in sync across two languages.
 *
 * ## Eigen kleuren
 *
 * `custom` is the one theme whose colours are stored instead of written in
 * admin.css. It stores exactly five (COLORS: background, sidebar, cards,
 * text, accent), one `admin_settings` row each, and bodyAttribute() prints
 * them as `--admin-custom-*` properties next to the theme key. admin.css
 * derives every other token from those five in its `custom` block, so the
 * derivation is still written once, in CSS, and the live preview on the
 * settings screen (admin/assets/admin-theme-preview.js), which sets the same
 * five properties, cannot come out differently from the saved page.
 *
 * A colour is six hex digits and nothing else — the rule
 * App\Service\Theme\ThemeSettings applies to the website's colours. The value
 * ends up in a style attribute, so a keyword, url(), var() or a semicolon
 * with a second declaration behind it must never pass. A stored colour that
 * is missing or broken is the Default theme's own value, which is what
 * COLORS holds.
 *
 * Choosing a fixed theme leaves the stored colours alone, so switching back
 * to Eigen kleuren brings back the colours that were chosen before.
 *
 * ## How it reaches the page
 *
 * Every admin page prints AdminTheme::bodyAttribute() in its <body> tag and
 * nothing else. Detection happens once, here; a page cannot get it wrong,
 * cannot get it half right, and cannot opt out.
 * Tests\Service\AdminThemeContractTest fails if an admin page's <body> is
 * missing it, so a new screen inherits the theme or the suite goes red.
 *
 * A missing row, an unreachable database and a fresh install all mean the
 * same thing: `default`, which is this CMS's appearance exactly as it was
 * before dashboard themes existed.
 */
final class AdminTheme
{
    /** The key every unknown, absent or unreachable value falls back to. */
    public const DEFAULT_KEY = 'default';

    /** The theme whose colours are the owner's own: "Eigen kleuren". */
    public const CUSTOM_KEY = 'custom';

    /** The `admin_settings` row holding the chosen theme key. */
    public const SETTING_KEY = 'admin_theme';

    /**
     * The rows of the five Eigen kleuren colours: `admin_theme_color_bg` and
     * so on. Written only when that theme is saved with its colours.
     */
    public const COLOR_SETTING_PREFIX = 'admin_theme_color_';

    /**
     * The five colours Eigen kleuren asks for, in the order the settings
     * screen shows them, each with its fallback: the Default theme's own
     * token on :root in admin.css (--admin-bg, --admin-sidebar-bg,
     * --admin-surface, --admin-text, --admin-accent).
     * Tests\Service\AdminThemeContractTest keeps the two equal.
     *
     * @var array<string, string> colour => fallback, as #rrggbb
     */
    public const COLORS = [
        'bg' => '#14120d',
        'sidebar' => '#19160f',
        'surface' => '#1e1a13',
        'text' => '#f1ead9',
        'accent' => '#cda34d',
    ];

    /**
     * The shape a typed colour may have, as the HTML pattern of its hex field:
     * the pattern admin/theme.php puts on the website's colour fields.
     * normaliseColor() applies the same shape on the server, plus the
     * three-digit short form admin/assets/theme-admin.js expands on blur.
     */
    public const COLOR_PATTERN = '#?[0-9A-Fa-f]{6}';

    /**
     * The closed set. Order is the order the settings screen shows them in.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const THEMES = [
        'default' => [
            'label' => 'Default',
            'description' => 'De huidige vormgeving van dit CMS: warm antraciet met goud.',
        ],
        'classic' => [
            'label' => 'Classic',
            'description' => 'Licht en rustig, met blauw accent. Een vertrouwd, zakelijk CMS.',
        ],
        'ocean' => [
            'label' => 'Ocean',
            'description' => 'Diep blauw met turquoise accent. Koel en modern.',
        ],
        'black' => [
            'label' => 'Black',
            'description' => 'Bijna zwart met wit. Monochroom en hoog contrast.',
        ],
        'custom' => [
            'label' => 'Eigen kleuren',
            'description' => 'Kies zelf de achtergrond, zijbalk, kaarten, tekst en accentkleur.',
        ],
    ];

    private static ?string $cache = null;

    /** @var array<string, string>|null */
    private static ?array $colorCache = null;

    /**
     * The whole registry: key => label + description.
     *
     * @return array<string, array{label: string, description: string}>
     */
    public static function all(): array
    {
        return self::THEMES;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::THEMES);
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::THEMES);
    }

    public static function label(string $key): string
    {
        return (self::THEMES[$key] ?? self::THEMES[self::DEFAULT_KEY])['label'];
    }

    /**
     * The theme this installation is on. Anything unusable — no row, an
     * unknown key, an unreachable database — is the Default theme, because
     * an admin panel that cannot read a preference should still look like
     * the admin panel.
     */
    public static function current(): string
    {
        if (self::$cache === null) {
            self::load();
        }

        return self::$cache ?? self::DEFAULT_KEY;
    }

    /** Whether this installation is still on the shipped Default theme. */
    public static function isDefault(): bool
    {
        return self::current() === self::DEFAULT_KEY;
    }

    /**
     * The five Eigen kleuren colours as stored, with every one that is
     * missing or unusable replaced by its Default value. Always complete, and
     * available whichever theme is current: the settings screen shows them
     * before anybody has chosen Eigen kleuren.
     *
     * @return array<string, string> colour => #rrggbb
     */
    public static function customColors(): array
    {
        if (self::$colorCache === null) {
            self::load();
        }

        return self::$colorCache ?? self::COLORS;
    }

    /**
     * The attribute every admin <body> carries. Always printed, including
     * for `default`: a page that says which theme it is showing can be
     * checked, and admin.css declares the Default values on :root, so the
     * `default` key deliberately has no override block of its own.
     *
     * Eigen kleuren adds a style attribute with its five colours, and only
     * that theme does: the fixed themes have nothing to add.
     */
    public static function bodyAttribute(): string
    {
        $current = self::current();
        $attribute = ' data-admin-theme="' . htmlspecialchars($current, ENT_QUOTES, 'UTF-8') . '"';

        if ($current === self::CUSTOM_KEY) {
            $attribute .= ' style="' . htmlspecialchars(self::customProperties(self::customColors()), ENT_QUOTES, 'UTF-8') . '"';
        }

        return $attribute;
    }

    /**
     * The colours as the custom properties admin.css derives the Eigen
     * kleuren palette from, for a style attribute. A value that is not a
     * colour is left out rather than printed, and admin.css falls back to the
     * Default colour for a property that is not there.
     *
     * @param array<string, mixed> $colors colour => value
     */
    public static function customProperties(array $colors): string
    {
        $declarations = [];

        foreach (array_keys(self::COLORS) as $name) {
            $color = self::normaliseColor($colors[$name] ?? null);

            if ($color !== null) {
                $declarations[] = self::colorProperty($name) . ': ' . $color;
            }
        }

        return implode('; ', $declarations);
    }

    /** The custom property a colour is printed as: `--admin-custom-bg`. */
    public static function colorProperty(string $name): string
    {
        return '--admin-custom-' . $name;
    }

    /**
     * The one place a submitted value becomes a storable value. Returns null
     * for anything outside the closed set, so nothing a request carries can
     * become an attribute value.
     */
    public static function normalise(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return self::isValid($value) ? $value : null;
    }

    /**
     * The one place a colour becomes a storable, printable value: `#` and six
     * lowercase hex digits. Accepts what the website's colour fields accept
     * (App\Service\Theme\ThemeSettings) — with or without `#`, and three
     * digits for six — and returns null for anything else.
     */
    public static function normaliseColor(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = ltrim(trim($value), '#');

        if (preg_match('/^[0-9A-Fa-f]{3}$/', $value) === 1) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        return preg_match('/^[0-9A-Fa-f]{6}$/', $value) === 1 ? '#' . strtolower($value) : null;
    }

    /**
     * All five colours out of a request, or null when one of them is missing
     * or is not a colour. All or nothing: half a stored palette would be a
     * theme nobody chose.
     *
     * @return array<string, string>|null colour => #rrggbb
     */
    public static function normaliseColors(mixed $submitted): ?array
    {
        if (!is_array($submitted)) {
            return null;
        }

        $colors = [];

        foreach (array_keys(self::COLORS) as $name) {
            $color = self::normaliseColor($submitted[$name] ?? null);

            if ($color === null) {
                return null;
            }

            $colors[$name] = $color;
        }

        return $colors;
    }

    /**
     * Stores a chosen theme. Validates again rather than trusting its
     * caller, and returns false for a value outside the closed set so the
     * endpoint can say so instead of silently storing nothing.
     *
     * $colors only means something for Eigen kleuren. Given, it has to be a
     * complete, valid set (normaliseColors()), or nothing at all is stored;
     * left out, the colours stored before stay. A fixed theme never writes
     * or deletes a colour, so they are still there on the way back.
     *
     * @param array<string, mixed>|null $colors
     */
    public static function save(string $key, ?array $colors = null): bool
    {
        $normalised = self::normalise($key);

        if ($normalised === null) {
            return false;
        }

        $values = [self::SETTING_KEY => $normalised];

        if ($normalised === self::CUSTOM_KEY && $colors !== null) {
            $validColors = self::normaliseColors($colors);

            if ($validColors === null) {
                return false;
            }

            foreach ($validColors as $name => $color) {
                $values[self::COLOR_SETTING_PREFIX . $name] = $color;
            }
        }

        (new AdminSettingRepository())->upsertMany($values);
        self::clearCache();

        return true;
    }

    /**
     * Every `admin_settings` row this class owns: the theme key and the five
     * colours.
     *
     * @return list<string>
     */
    public static function settingKeys(): array
    {
        $keys = [self::SETTING_KEY];

        foreach (array_keys(self::COLORS) as $name) {
            $keys[] = self::COLOR_SETTING_PREFIX . $name;
        }

        return $keys;
    }

    /**
     * Back to the shipped appearance by DELETING the rows, not by writing
     * `default` back — an absent row and a deliberately chosen default
     * should not look the same to the next reader. Same rule as
     * App\Service\Theme\ThemeSettings::reset(). The colours go too: a reset
     * is a clean start.
     */
    public static function reset(): void
    {
        (new AdminSettingRepository())->deleteKeys(self::settingKeys());
        self::clearCache();
    }

    /** Clears the per-request cache; used by the save handler and by tests. */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$colorCache = null;
    }

    /**
     * Test seam: pretend these are the stored theme and colours, without a
     * database. Pass null to go back to reading storage. Both still go
     * through validation, so a test cannot pin a theme or a colour the
     * application would refuse.
     *
     * @param array<string, mixed> $colors colour => stored value
     */
    public static function overrideForTests(?string $key, array $colors = []): void
    {
        if ($key === null) {
            self::clearCache();

            return;
        }

        $stored = [];
        foreach ($colors as $name => $value) {
            $stored[self::COLOR_SETTING_PREFIX . $name] = $value;
        }

        self::$cache = self::normalise($key) ?? self::DEFAULT_KEY;
        self::$colorCache = self::colorsFrom($stored);
    }

    /**
     * The theme and its colours in one read. Whatever the other cache
     * already holds — a test override, say — is kept.
     */
    private static function load(): void
    {
        $stored = [];

        try {
            $stored = (new AdminSettingRepository())->findAll();
        } catch (\Throwable $e) {
            error_log('[AdminTheme] falling back to the default theme: ' . $e->getMessage());
        }

        self::$cache ??= self::normalise($stored[self::SETTING_KEY] ?? null) ?? self::DEFAULT_KEY;
        self::$colorCache ??= self::colorsFrom($stored);
    }

    /**
     * @param array<string, mixed> $stored admin_settings rows
     * @return array<string, string> colour => #rrggbb
     */
    private static function colorsFrom(array $stored): array
    {
        $colors = [];

        foreach (self::COLORS as $name => $fallback) {
            $colors[$name] = self::normaliseColor($stored[self::COLOR_SETTING_PREFIX . $name] ?? null) ?? $fallback;
        }

        return $colors;
    }
}
