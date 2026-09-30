<?php

declare(strict_types=1);

namespace App\Service\Theme;

use App\Repository\ColorPaletteRepository;
use App\Service\Language\AdminTranslator;

/**
 * The website's colour palettes (Branding & Design 2.0): named sets of the
 * five theme colours, of which EXACTLY ONE is active. The active palette is
 * the website's colours; every other palette is a design in progress that
 * no visitor sees until it is activated. See THEMING.md, "Kleurenpaletten".
 *
 * Core, like the rest of the site theme. Not to be confused with a page
 * theme (module page_themes, App\Service\PageThemes\PageThemeService): a page
 * theme belongs to one page, keeps its own colours whichever palette is
 * active, and has no relation to these rows.
 *
 * ONE SOURCE, ONE READER. Nothing reads `color_palettes` to paint a page but
 * App\Service\Theme\ThemeSettings::all(), which takes the five colours from
 * activeColors() — so every existing consumer (ThemeCss, the theme-color
 * meta, a new page theme's defaults, the Setup Wizard) follows the active
 * palette without knowing palettes exist.
 *
 * THE SAME RULES AS EVERY THEME COLOUR: a colour goes through
 * ThemeSettings::validate() (App\Service\Theme\ThemeColor::normalise()) on
 * the way in, and again on the way out, per colour: a hand-edited row with
 * one broken colour shows the shipped default for that colour and never
 * reaches a stylesheet.
 *
 * A name is required, at most MAX_NAME_LENGTH characters and unique; it is
 * only ever printed escaped, and there is no slug: a palette never becomes a
 * selector or a URL.
 */
final class ColorPaletteService
{
    public const MAX_NAME_LENGTH = 80;

    /** What a delete can answer besides "deleted". */
    public const DELETE_DELETED = 'deleted';
    public const DELETE_ACTIVE = 'active';
    public const DELETE_LAST = 'last';
    public const DELETE_MISSING = 'missing';

    /**
     * The validated colours of the active palette, per request. false = not
     * looked up yet; null = no active palette.
     *
     * @var array<string, string>|null|false
     */
    private static array|null|false $active = false;

    /**
     * The five colours of the active palette, each validated, or null when
     * no palette is active (then the website shows the shipped default). A
     * colour that no longer validates is left out, so the caller falls back
     * to the default for that one colour.
     *
     * Throws when the table cannot be read — ThemeSettings catches that and
     * reads the pre-palette storage instead (an update between new code and
     * its migration), or the defaults.
     *
     * @return array<string, string>|null
     */
    public static function activeColors(): ?array
    {
        if (self::$active !== false) {
            return self::$active;
        }

        $row = (new ColorPaletteRepository())->findActive();

        return self::$active = $row === null ? null : self::colorsOf($row);
    }

    /**
     * Every palette, the active one first, with `active` as a bool.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_map(
            static fn (array $row): array => self::shape($row),
            (new ColorPaletteRepository())->findAll()
        );
    }

    /** @return array<string, mixed>|null one palette, with `active` as a bool */
    public static function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = (new ColorPaletteRepository())->findById($id);

        return $row === null ? null : self::shape($row);
    }

    /**
     * What a NEW palette starts with: the colours the website shows now (the
     * active palette), never a palette written into code — the editor
     * changes what should be different.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return array_intersect_key(ThemeSettings::all(), array_flip(ThemeSettings::COLOR_KEYS));
    }

    /**
     * The colours for the preview document (admin/color-palette-preview.php):
     * each validated exactly as a save would, and every one that would be
     * refused replaced by the active palette's — the preview never shows a
     * colour a save could not store.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function previewColors(array $input): array
    {
        $colors = self::defaults();

        foreach (ThemeSettings::COLOR_KEYS as $key) {
            if (!is_scalar($input[$key] ?? null)) {
                continue;
            }

            $normalised = ThemeColor::normalise((string) $input[$key]);
            if ($normalised !== null) {
                $colors[$key] = $normalised;
            }
        }

        return $colors;
    }

    /**
     * Validates a submitted palette. Every colour is required (a palette is
     * always complete), the name is required, at most MAX_NAME_LENGTH
     * characters and unique.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input, ?int $id = null): array
    {
        $errors = [];

        $name = is_scalar($input['name'] ?? null) ? trim((string) $input['name']) : '';
        if ($name === '') {
            $errors['name'] = AdminTranslator::trans('palettes.error_name_required');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = AdminTranslator::trans('palettes.error_name_length', ['max' => self::MAX_NAME_LENGTH]);
        } elseif ((new ColorPaletteRepository())->nameTaken($name, $id)) {
            $errors['name'] = AdminTranslator::trans('palettes.error_name_taken');
        }

        $submitted = [];
        foreach (ThemeSettings::COLOR_KEYS as $key) {
            $submitted[$key] = is_scalar($input[$key] ?? null) ? (string) $input[$key] : '';
        }

        $checked = ThemeSettings::validate($submitted);
        foreach ($checked['errors'] as $key => $message) {
            $errors[$key] = $message;
        }

        return ['values' => $checked['values'] + ['name' => $name], 'errors' => $errors];
    }

    /**
     * A new palette, never active: creating one never changes the website.
     *
     * @param array<string, string> $values the output of validate()
     */
    public static function create(array $values): int
    {
        $id = (new ColorPaletteRepository())->create(self::storable($values));
        self::clearCache();

        return $id;
    }

    /**
     * Saves a palette's name and colours. Saving an INACTIVE palette changes
     * nothing a visitor sees; saving the active one changes the website.
     *
     * @param array<string, string> $values the output of validate()
     */
    public static function update(int $id, array $values): void
    {
        (new ColorPaletteRepository())->update($id, self::storable($values));
        self::clearCache();
    }

    /**
     * A copy with the same colours, named "<name> (kopie)" — or "(kopie 2)",
     * "(kopie 3)" while that is taken — and NOT active. Null when the palette
     * is gone.
     */
    public static function duplicate(int $id): ?int
    {
        $repository = new ColorPaletteRepository();
        $palette = $repository->findById($id);

        if ($palette === null) {
            return null;
        }

        $values = array_intersect_key($palette, array_flip(ColorPaletteRepository::COLUMNS));
        $values['name'] = self::copyName((string) $palette['name'], $repository);

        $newId = $repository->create(array_map('strval', $values));
        self::clearCache();

        return $newId;
    }

    /**
     * Makes this palette the website's palette. Atomic: at no moment are two
     * palettes active, or (for an existing palette) none. False when the
     * palette is gone; nothing changed then.
     */
    public static function activate(int $id): bool
    {
        $done = (new ColorPaletteRepository())->activate($id);
        self::clearCache();

        return $done;
    }

    /**
     * Deletes a palette — never the active one (activate another first) and
     * never the last one. The repository refuses the active one in SQL as
     * well, for a palette activated between the check and the delete.
     *
     * @return string one of the DELETE_* constants
     */
    public static function delete(int $id): string
    {
        $repository = new ColorPaletteRepository();
        $palette = $repository->findById($id);

        if ($palette === null) {
            return self::DELETE_MISSING;
        }

        if ((int) ($palette['is_active'] ?? 0) === 1) {
            return self::DELETE_ACTIVE;
        }

        if ($repository->count() <= 1) {
            return self::DELETE_LAST;
        }

        $deleted = $repository->deleteInactive($id);
        self::clearCache();

        return $deleted ? self::DELETE_DELETED : self::DELETE_ACTIVE;
    }

    /**
     * Writes colours into the ACTIVE palette — what ThemeSettings::save()
     * does with a colour, so the Setup Wizard and every older caller keep
     * working. A partial set changes only those colours. With no active
     * palette the oldest one is activated first, or "Standaard" is created
     * from the shipped default when there is none at all.
     *
     * @param array<string, string> $colors validated, keyed by ThemeSettings::COLOR_KEYS
     */
    public static function saveActiveColors(array $colors): void
    {
        $colors = array_intersect_key($colors, array_flip(ThemeSettings::COLOR_KEYS));
        if ($colors === []) {
            return;
        }

        $repository = new ColorPaletteRepository();
        $active = self::ensureActive($repository);
        $repository->update((int) $active['id'], $colors);
        self::clearCache();
    }

    /**
     * Sets the active palette's colours back to the shipped default — the
     * colour half of "Standaardvormgeving herstellen". Other palettes stay as
     * they are.
     */
    public static function resetActiveColors(): void
    {
        $defaults = array_intersect_key(ThemeSettings::defaults(), array_flip(ThemeSettings::COLOR_KEYS));
        $repository = new ColorPaletteRepository();
        $active = $repository->findActive();

        if ($active !== null) {
            $repository->update((int) $active['id'], $defaults);
        }

        self::clearCache();
    }

    /**
     * The values for the editor, validated per colour: a stored colour that
     * no longer validates shows the shipped default, like the website does.
     *
     * @param array<string, mixed> $row a `color_palettes` row
     * @return array<string, string>
     */
    public static function colorsOf(array $row): array
    {
        $colors = [];
        foreach (ThemeSettings::COLOR_KEYS as $key) {
            $normalised = ThemeColor::normalise((string) ($row[$key] ?? ''));
            if ($normalised !== null) {
                $colors[$key] = $normalised;
            }
        }

        return $colors;
    }

    /** Forgets the active palette read in this request (ThemeSettings::clearCache()). */
    public static function forgetActive(): void
    {
        self::$active = false;
    }

    /** Clears the per-request caches, this one and the theme's. */
    public static function clearCache(): void
    {
        self::$active = false;
        ThemeSettings::clearCache();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $colors = self::colorsOf($row);
        $defaults = ThemeSettings::defaults();
        foreach (ThemeSettings::COLOR_KEYS as $key) {
            $colors[$key] ??= $defaults[$key];
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'active' => (int) ($row['is_active'] ?? 0) === 1,
            'updated_at' => $row['updated_at'] ?? null,
        ] + $colors;
    }

    /**
     * @param array<string, string> $values
     * @return array<string, string>
     */
    private static function storable(array $values): array
    {
        return array_intersect_key($values, array_flip(ColorPaletteRepository::COLUMNS));
    }

    /** @return array<string, mixed> the active palette row, made so when needed */
    private static function ensureActive(ColorPaletteRepository $repository): array
    {
        $active = $repository->findActive();
        if ($active !== null) {
            return $active;
        }

        $first = $repository->findFirst();
        if ($first !== null) {
            $repository->activate((int) $first['id']);

            return $first;
        }

        $name = 'Standaard';
        for ($n = 2; $repository->nameTaken($name); $n++) {
            $name = 'Standaard ' . $n;
        }

        $defaults = array_intersect_key(ThemeSettings::defaults(), array_flip(ThemeSettings::COLOR_KEYS));
        $id = $repository->create($defaults + ['name' => $name], true);

        return $repository->findById($id) ?? ['id' => $id];
    }

    private static function copyName(string $name, ColorPaletteRepository $repository): string
    {
        $room = self::MAX_NAME_LENGTH - 12;
        $base = mb_strlen($name) > $room ? rtrim(mb_substr($name, 0, $room)) : $name;

        $candidate = $base . ' (kopie)';
        for ($n = 2; $repository->nameTaken($candidate); $n++) {
            $candidate = $base . ' (kopie ' . $n . ')';
        }

        return $candidate;
    }
}
