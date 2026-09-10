<?php

namespace App\Service\Theme;

use App\Repository\ThemeSettingRepository;

/**
 * What the site LOOKS like: five colours, a font pairing and a button shape.
 *
 * The counterpart of App\Service\SiteSettings, which owns who the site IS —
 * name, logo, favicon, social image, address, KVK, invoice and e-mail copy.
 * The split is deliberate and worth keeping honest:
 *
 *   SiteSettings   identity. Never reset by a theme action.
 *   ThemeSettings  appearance. Resettable in one click, and nothing here is
 *                  business data, so that reset is always safe.
 *
 * Nothing is duplicated across the two. The admin screen shows the site name
 * next to the colours because that is where an owner looks for it, but it
 * still writes to SiteSettings.
 *
 * DEFAULTS below are the exact current Van Veluw Laserdesign design values,
 * and they are also what assets/css/core.css declares. That is the whole
 * mechanism: a site that has changed nothing stores no rows, emits no
 * override block, and renders from core.css alone. It also means the public
 * site keeps its correct appearance when the database is unreachable, and
 * that a fresh install is already coherent before anybody has opened the
 * theme screen.
 *
 * Every value is validated on the way in against a closed set — a hex colour
 * in one fixed shape, a pairing key, a shape key. Nothing an administrator
 * types can become a CSS declaration: no url(), no var(), no calc(), no
 * second property smuggled in behind a semicolon.
 */
final class ThemeSettings
{
    public const COLOR_KEYS = [
        'primary_color',
        'on_primary_color',
        'background_color',
        'surface_color',
        'text_color',
    ];

    /**
     * The two button shapes. A closed enum rather than a pixel field: a
     * number invites values that break the button, and nobody outside this
     * file should be inventing radii.
     *
     * @var array<string, array{label: string, radius: string}>
     */
    private const BUTTON_SHAPES = [
        'pill' => ['label' => 'Pill (volledig rond)', 'radius' => '999px'],
        'rounded' => ['label' => 'Afgerond', 'radius' => 'var(--radius-md)'],
    ];

    /**
     * The current design, exactly as assets/css/core.css declares it.
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'primary_color' => '#C9A063',
        'on_primary_color' => '#1B140D',
        'background_color' => '#120D09',
        'surface_color' => '#1C150E',
        'text_color' => '#F5EFE4',
        'font_pairing' => ThemeFonts::DEFAULT_KEY,
        'button_shape' => 'pill',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /**
     * Every known key: the stored value where there is a valid one, the code
     * default otherwise. A stored value that no longer validates (an older
     * install, a hand-edited row, a font pairing that was removed) falls back
     * to the default rather than reaching a stylesheet.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];
        try {
            $stored = (new ThemeSettingRepository())->findAll();
        } catch (\Throwable $e) {
            error_log('[ThemeSettings] falling back to defaults: ' . $e->getMessage());
        }

        $settings = self::DEFAULTS;
        foreach ($stored as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS) || $value === '') {
                continue;
            }

            $normalised = self::normalise($key, $value);
            if ($normalised !== null) {
                $settings[$key] = $normalised;
            }
        }

        return self::$cache = $settings;
    }

    public static function get(string $key): string
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** Whether the effective theme is still the shipped default, top to bottom. */
    public static function isDefault(): bool
    {
        return self::all() === self::DEFAULTS;
    }

    /** The keys whose effective value differs from the shipped default. */
    public static function changedKeys(): array
    {
        $effective = self::all();

        return array_values(array_filter(
            array_keys(self::DEFAULTS),
            static fn (string $key): bool => $effective[$key] !== self::DEFAULTS[$key]
        ));
    }

    /**
     * @return array<string, array{label: string, radius: string}>
     */
    public static function buttonShapes(): array
    {
        return self::BUTTON_SHAPES;
    }

    /** The CSS length behind the stored shape key. */
    public static function buttonRadius(): string
    {
        $shape = self::get('button_shape');

        return (self::BUTTON_SHAPES[$shape] ?? self::BUTTON_SHAPES['pill'])['radius'];
    }

    /**
     * Validates a whole submitted form.
     *
     * Returns the values to store alongside a Dutch, user-facing message per
     * rejected field. A key that is not in the submission is simply absent
     * from the result, so a partial form cannot blank out what it did not
     * show — the same rule api/admin/update-site-settings.php follows.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $values = [];
        $errors = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $raw = trim((string) $input[$key]);
            $normalised = self::normalise($key, $raw);

            if ($normalised === null) {
                $errors[$key] = self::errorFor($key);
                continue;
            }

            $values[$key] = $normalised;
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Stores validated values. Callers pass the output of validate(); this
     * method validates again anyway, because "the only writer is careful" is
     * not a property a storage layer should have to assume.
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        $clean = self::validate($values)['values'];

        if ($clean === []) {
            return;
        }

        (new ThemeSettingRepository())->upsertMany($clean);
        self::clearCache();
    }

    /**
     * Restores the shipped appearance by DELETING the stored rows, not by
     * writing the defaults back — an absent row and a deliberately chosen
     * default should not look the same to the next reader.
     *
     * Only the theme's own keys are touched. Nothing in site_settings is
     * reachable from here at all, which is exactly why the two live in
     * separate tables: "restore theme defaults" can never take a company
     * address, a logo or an invoice footer with it.
     */
    public static function reset(): void
    {
        (new ThemeSettingRepository())->deleteKeys(array_keys(self::DEFAULTS));
        self::clearCache();
    }

    /** Clears the per-request cache; used by the save handlers and by tests. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Test seam: pretend these are the stored values, without a database.
     * Pass null to go back to reading storage. Same shape and the same
     * always-reset-in-tearDown discipline as
     * App\Module\ModuleRegistry::overrideForTests().
     *
     * Values still go through validation, so a test cannot set a colour the
     * application itself would refuse.
     *
     * @param array<string, string>|null $values
     */
    public static function overrideForTests(?array $values): void
    {
        if ($values === null) {
            self::$cache = null;

            return;
        }

        self::$cache = array_merge(self::DEFAULTS, self::validate($values)['values']);
    }

    /**
     * The one place a submitted value becomes a storable value. Returns null
     * for anything that is not exactly what this key allows.
     */
    private static function normalise(string $key, string $value): ?string
    {
        if (in_array($key, self::COLOR_KEYS, true)) {
            return self::normaliseColor($value);
        }

        if ($key === 'font_pairing') {
            return ThemeFonts::isValidKey($value) ? $value : null;
        }

        if ($key === 'button_shape') {
            return array_key_exists($value, self::BUTTON_SHAPES) ? $value : null;
        }

        return null;
    }

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
    private static function normaliseColor(string $value): ?string
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

    private static function errorFor(string $key): string
    {
        if (in_array($key, self::COLOR_KEYS, true)) {
            return 'Gebruik een kleurcode in de vorm #RRGGBB, bijvoorbeeld #C9A063.';
        }

        if ($key === 'font_pairing') {
            return 'Kies een lettertypecombinatie uit de lijst.';
        }

        return 'Kies een knopvorm uit de lijst.';
    }
}
