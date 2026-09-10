<?php

namespace App\Service;

use App\Repository\AdminSettingRepository;

/**
 * What the CMS ITSELF looks like: one global choice out of four first-party
 * skins, applied to every admin screen at once.
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
 * It is also not four admin interfaces. Every theme renders the same
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
 * reaching an HTML attribute. Colours are deliberately NOT here: they live
 * in admin.css, one `[data-admin-theme="..."]` block per key, so nobody has
 * to keep a palette in sync across two languages.
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

    /** The `admin_settings` row this class owns. The only one, on purpose. */
    public const SETTING_KEY = 'admin_theme';

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
    ];

    private static ?string $cache = null;

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
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = null;
        try {
            $stored = (new AdminSettingRepository())->findAll()[self::SETTING_KEY] ?? null;
        } catch (\Throwable $e) {
            error_log('[AdminTheme] falling back to the default theme: ' . $e->getMessage());
        }

        return self::$cache = self::normalise($stored) ?? self::DEFAULT_KEY;
    }

    /** Whether this installation is still on the shipped Default theme. */
    public static function isDefault(): bool
    {
        return self::current() === self::DEFAULT_KEY;
    }

    /**
     * The attribute every admin <body> carries. Always printed, including
     * for `default`: a page that says which theme it is showing can be
     * checked, and admin.css declares the Default values on :root, so the
     * `default` key deliberately has no override block of its own.
     */
    public static function bodyAttribute(): string
    {
        return ' data-admin-theme="' . htmlspecialchars(self::current(), ENT_QUOTES, 'UTF-8') . '"';
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
     * Stores a chosen theme. Validates again rather than trusting its
     * caller, and returns false for a value outside the closed set so the
     * endpoint can say so instead of silently storing nothing.
     */
    public static function save(string $key): bool
    {
        $normalised = self::normalise($key);

        if ($normalised === null) {
            return false;
        }

        (new AdminSettingRepository())->upsertMany([self::SETTING_KEY => $normalised]);
        self::clearCache();

        return true;
    }

    /**
     * Back to the shipped appearance by DELETING the row, not by writing
     * `default` back — an absent row and a deliberately chosen default
     * should not look the same to the next reader. Same rule as
     * App\Service\Theme\ThemeSettings::reset().
     */
    public static function reset(): void
    {
        (new AdminSettingRepository())->deleteKeys([self::SETTING_KEY]);
        self::clearCache();
    }

    /** Clears the per-request cache; used by the save handler and by tests. */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Test seam: pretend this is the stored value, without a database. Pass
     * null to go back to reading storage. The value still goes through
     * validation, so a test cannot pin a theme the application would refuse.
     */
    public static function overrideForTests(?string $key): void
    {
        self::$cache = $key === null ? null : (self::normalise($key) ?? self::DEFAULT_KEY);
    }
}
