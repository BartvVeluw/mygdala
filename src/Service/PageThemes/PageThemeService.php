<?php

declare(strict_types=1);

namespace App\Service\PageThemes;

use App\Repository\PageRepository;
use App\Repository\PageThemeRepository;
use App\Service\Language\AdminTranslator;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\Theme\PageAppearance;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeSettings;

/**
 * Page Themes 1.0: what a named page theme is, and every rule around it —
 * validation, the slug, the defaults of a new theme, duplicating, the
 * delete protection, which pages use it, and the appearance a page gets
 * from it. The module's own logic; Core only ever sees the resulting
 * App\Service\Theme\PageAppearance (THEMING.md, "Paginathema's").
 *
 * NOTHING IS VALIDATED TWICE IN TWO WAYS. A colour and a font pairing go
 * through App\Service\Theme\ThemeSettings::validate(), the site theme's own
 * validation (ThemeColor::normalise(), ThemeFonts::isValidKey()), so a page
 * theme accepts exactly what Vormgeving accepts. Reading a stored theme goes
 * through PageAppearance::fromTheme(), which validates again: a hand-edited
 * row falls back to the site theme rather than reaching a stylesheet.
 *
 * NO INHERITANCE. A page's look is its own page_theme_id and nothing else:
 * a page under a themed page gets the site theme unless it chose one itself.
 * Every language of a page shares the one `pages` row, so NL and EN look the
 * same.
 */
final class PageThemeService
{
    /** The theme's choices besides its name, in the order the editor shows them. */
    public const VALUE_FIELDS = [
        'primary_color',
        'on_primary_color',
        'background_color',
        'surface_color',
        'text_color',
        'font_pairing',
    ];

    public const MAX_NAME_LENGTH = 80;

    /**
     * The pairs of colours that must stay readable together: text on its
     * ground, text on a card, the label on a filled button, and a link on
     * the ground. First the foreground, then the background.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const CONTRAST_PAIRS = [
        ['text_color', 'background_color'],
        ['text_color', 'surface_color'],
        ['on_primary_color', 'primary_color'],
        ['primary_color', 'background_color'],
    ];

    /** @var array<int, array<string, mixed>|null> per-request cache, by id */
    private static array $themes = [];

    /**
     * What a NEW theme starts with: the site theme as it is now. Never a
     * hardcoded palette, so a new theme is readable from its first save and
     * an editor changes what they want to be different.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $site = ThemeSettings::all();

        return array_intersect_key($site, array_flip(self::VALUE_FIELDS));
    }

    /**
     * Validates a submitted theme. Every value field is required; the name is
     * required, at most MAX_NAME_LENGTH characters and unique. The slug is
     * never typed: it is made from the name and made unique.
     *
     * @param array<string, mixed> $input
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $input, ?int $id = null, ?PageThemeRepository $repository = null): array
    {
        $repository ??= new PageThemeRepository();
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = AdminTranslator::trans('pagethemes.error_name_required');
        } elseif (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors['name'] = AdminTranslator::trans('pagethemes.error_name_length', ['max' => self::MAX_NAME_LENGTH]);
        } elseif ($repository->nameTaken($name, $id)) {
            $errors['name'] = AdminTranslator::trans('pagethemes.error_name_taken');
        }

        // Absent means empty here, unlike the partial site-theme form: a
        // theme is always complete.
        $submitted = [];
        foreach (self::VALUE_FIELDS as $field) {
            $submitted[$field] = is_scalar($input[$field] ?? null) ? (string) $input[$field] : '';
        }

        $checked = ThemeSettings::validate($submitted);
        foreach ($checked['errors'] as $field => $message) {
            $errors[$field] = $message;
        }

        $values = $checked['values'];
        $values['name'] = $name;

        if (!isset($errors['name'])) {
            $values['slug'] = self::uniqueSlug($name, $id, $repository);
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * The values for the editor's live preview (admin/page-theme-preview.php):
     * each field validated exactly as a save would, and every field that
     * would be refused replaced by the site theme's value — the preview never
     * shows something a save could not store.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function previewValues(array $input): array
    {
        $values = self::defaults();

        foreach (self::VALUE_FIELDS as $field) {
            if (!is_scalar($input[$field] ?? null)) {
                continue;
            }

            $checked = ThemeSettings::validate([$field => (string) $input[$field]]);
            if (isset($checked['values'][$field])) {
                $values[$field] = $checked['values'][$field];
            }
        }

        return $values;
    }

    /** @param array<string, string> $values the output of validate() */
    public static function create(array $values): int
    {
        $id = (new PageThemeRepository())->create($values);
        self::clearCache();

        return $id;
    }

    /** @param array<string, string> $values the output of validate() */
    public static function update(int $id, array $values): void
    {
        (new PageThemeRepository())->update($id, $values);
        self::clearCache();
    }

    /**
     * A copy of a theme, named "<name> (kopie)" — or "(kopie 2)", "(kopie 3)"
     * while that is taken — with its own slug. Null when the theme is gone.
     */
    public static function duplicate(int $id): ?int
    {
        $repository = new PageThemeRepository();
        $theme = $repository->findById($id);

        if ($theme === null) {
            return null;
        }

        $name = self::copyName((string) $theme['name'], $repository);
        $values = array_intersect_key($theme, array_flip(PageThemeRepository::COLUMNS));
        $values['name'] = $name;
        $values['slug'] = self::uniqueSlug($name, null, $repository);

        $newId = $repository->create(array_map('strval', $values));
        self::clearCache();

        return $newId;
    }

    /**
     * Deletes a theme nobody uses. A theme that a page still uses is NOT
     * deleted: the answer lists those pages, so the editor can move them to
     * another theme first. The foreign key (RESTRICT) refuses as well, for a
     * page that chose the theme between the check and the delete.
     *
     * @return array{deleted: bool, pages: list<array{id: int, title: string}>}
     */
    public static function delete(int $id): array
    {
        $pages = self::pagesUsing($id);

        if ($pages !== []) {
            return ['deleted' => false, 'pages' => $pages];
        }

        try {
            (new PageThemeRepository())->delete($id);
        } catch (\PDOException $e) {
            error_log('[PageThemeService] delete refused: ' . $e->getMessage());

            return ['deleted' => false, 'pages' => self::pagesUsing($id)];
        }

        self::clearCache();

        return ['deleted' => true, 'pages' => []];
    }

    /**
     * The pages that use a theme, with the name the CMS knows them by.
     *
     * @return list<array{id: int, title: string}>
     */
    public static function pagesUsing(int $themeId): array
    {
        $pages = [];

        foreach ((new PageRepository())->findByPageTheme($themeId) as $page) {
            $pageId = (int) $page['id'];
            $title = PageLocalization::name($pageId);
            $pages[] = ['id' => $pageId, 'title' => $title !== '' ? $title : (string) $page['slug']];
        }

        return $pages;
    }

    /**
     * Every theme with how many pages use it, for the overview.
     *
     * @return list<array<string, mixed>>
     */
    public static function allWithUsage(): array
    {
        $counts = (new PageRepository())->countByPageTheme();
        $themes = [];

        foreach ((new PageThemeRepository())->findAll() as $theme) {
            $theme['usage'] = $counts[(int) $theme['id']] ?? 0;
            $themes[] = $theme;
        }

        return $themes;
    }

    /**
     * The look a page gets from its own theme, or null for the site theme:
     * no theme chosen, a theme that no longer reads as valid, or a content
     * page of a product or project (which never has one).
     *
     * @param array<string, mixed> $page a `pages` row
     */
    public static function appearanceFor(array $page): ?PageAppearance
    {
        if (($page['owner_type'] ?? null) !== null) {
            return null;
        }

        $themeId = (int) ($page['page_theme_id'] ?? 0);
        if ($themeId < 1) {
            return null;
        }

        $theme = self::theme($themeId);
        if ($theme === null) {
            return null;
        }

        return self::appearanceOf($theme);
    }

    /**
     * A theme row as an appearance, or null when any stored value is not
     * what a theme allows.
     *
     * @param array<string, mixed> $theme
     */
    public static function appearanceOf(array $theme): ?PageAppearance
    {
        return PageAppearance::fromTheme(
            (string) ($theme['slug'] ?? ''),
            $theme,
            (string) ($theme['font_pairing'] ?? '')
        );
    }

    /**
     * The colour pairs below ThemeColor::MIN_TEXT_CONTRAST. A warning, never
     * a refusal: the editor shows it and still saves.
     *
     * @param array<string, string> $colors
     * @return list<array{foreground: string, background: string, ratio: float}>
     */
    public static function contrastWarnings(array $colors): array
    {
        $warnings = [];

        foreach (self::CONTRAST_PAIRS as [$foreground, $background]) {
            $fg = ThemeColor::normalise((string) ($colors[$foreground] ?? ''));
            $bg = ThemeColor::normalise((string) ($colors[$background] ?? ''));

            if ($fg === null || $bg === null) {
                continue;
            }

            $ratio = ThemeColor::contrastRatio($fg, $bg);
            if ($ratio < ThemeColor::MIN_TEXT_CONTRAST) {
                $warnings[] = ['foreground' => $foreground, 'background' => $background, 'ratio' => round($ratio, 2)];
            }
        }

        return $warnings;
    }

    /** @return array<string, mixed>|null */
    public static function theme(int $id): ?array
    {
        if (!array_key_exists($id, self::$themes)) {
            self::$themes[$id] = (new PageThemeRepository())->findById($id);
        }

        return self::$themes[$id];
    }

    public static function clearCache(): void
    {
        self::$themes = [];
    }

    /**
     * A slug from the name, lowercase ASCII with dashes, made unique with
     * -2, -3, … The attribute value and the CSS selector are built from it,
     * so it must match PageAppearance::SLUG_PATTERN.
     */
    public static function uniqueSlug(string $name, ?int $exceptId, PageThemeRepository $repository): string
    {
        $base = trim(substr(PageService::sanitizeSlug($name), 0, PageAppearance::SLUG_MAX_LENGTH - 6), '-');
        if ($base === '') {
            $base = 'thema';
        }

        $slug = $base;
        for ($suffix = 2; $repository->slugTaken($slug, $exceptId); $suffix++) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }

    private static function copyName(string $name, PageThemeRepository $repository): string
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
