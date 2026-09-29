<?php

declare(strict_types=1);

namespace App\Service\PageThemes;

use App\Repository\PageRepository;
use App\Repository\PageThemeRepository;
use App\Service\Language\AdminTranslator;
use App\Service\PageSettingsSection;

/**
 * "Paginathema" on the Pagina tab of the page editor: which page theme this
 * page uses, or the site theme. Contributed by App\Module\PageThemesModule,
 * so the field is simply not there while the module is off — and whatever
 * the page stored stays stored (MODULES.md).
 *
 * Posted as `page_theme_id`: 0 (or empty) is the site theme, anything else
 * must be the id of an existing theme. Written only when that field was on
 * the submitted form (App\Service\PageSettingsSection), through
 * PageRepository::updatePageTheme(). The markup is admin/_page_theme_field.php.
 */
final class PageThemeSettingsSection implements PageSettingsSection
{
    public const FIELD = 'page_theme_id';

    public function fields(): array
    {
        return [self::FIELD];
    }

    public function render(array $page, ?array $old): void
    {
        $themes = (new PageThemeRepository())->findAll();
        $selectedThemeId = $old !== null && array_key_exists(self::FIELD, $old)
            ? (int) $old[self::FIELD]
            : (int) ($page['page_theme_id'] ?? 0);

        require dirname(__DIR__, 3) . '/admin/_page_theme_field.php';
    }

    public function errors(array $page, array $input): array
    {
        if (!array_key_exists(self::FIELD, $input)) {
            return [];
        }

        $themeId = self::submittedId($input[self::FIELD]);

        if ($themeId === false || ($themeId !== null && (new PageThemeRepository())->findById($themeId) === null)) {
            return [AdminTranslator::trans('pagethemes.error_page_theme_unknown')];
        }

        return [];
    }

    public function save(array $page, array $input): void
    {
        if (!array_key_exists(self::FIELD, $input)) {
            return;
        }

        $themeId = self::submittedId($input[self::FIELD]);
        if ($themeId === false) {
            return;
        }

        (new PageRepository())->updatePageTheme((int) $page['id'], $themeId);
    }

    /**
     * null for "the site theme" (0 or empty), the id for a positive whole
     * number, false for anything else.
     */
    private static function submittedId(mixed $value): int|null|false
    {
        if (!is_scalar($value)) {
            return false;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '0') {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? false : $id;
    }
}
