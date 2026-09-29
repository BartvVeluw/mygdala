<?php

declare(strict_types=1);

/**
 * "Paginathema" on the Pagina tab of the page editor (admin/page.php): the
 * site theme, or one of the page themes, with a small swatch of the choice.
 * Rendered by App\Service\PageThemes\PageThemeSettingsSection::render(), which
 * sets $themes (every page_themes row) and $selectedThemeId (0 = the site
 * theme) — and only while the Paginathema's module is on, because Core reads
 * the section from enabled modules only.
 *
 * No guard here: it renders inside admin/page.php's settings form, after that
 * screen's own pages.manage check, and posts with it to
 * api/admin/update-page.php. Choosing a theme is part of editing the page;
 * making themes is page_themes.manage, on its own screen.
 *
 * The swatch follows the select through admin/assets/page-theme-field.js;
 * without the script it shows the stored choice.
 *
 * @var list<array<string, mixed>> $themes
 * @var int $selectedThemeId
 */

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

$pageThemeH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$pageThemeSwatchKeys = ['background_color', 'surface_color', 'text_color', 'primary_color'];

/** @return list<string> the valid swatch colours of one theme row */
$pageThemeSwatches = static function (?array $theme) use ($pageThemeSwatchKeys): array {
    if ($theme === null) {
        return [];
    }

    $colors = [];
    foreach ($pageThemeSwatchKeys as $key) {
        $color = \App\Service\Theme\ThemeColor::normalise((string) ($theme[$key] ?? ''));
        if ($color !== null) {
            $colors[] = $color;
        }
    }

    return $colors;
};

$pageThemeSelected = null;
foreach ($themes as $pageThemeRow) {
    if ((int) $pageThemeRow['id'] === $selectedThemeId) {
        $pageThemeSelected = $pageThemeRow;
    }
}
?>
      <div class="admin-field" data-page-theme-field>
        <?= admin_field_label('page-theme', admin_t('pagethemes.page_field'), admin_t('help.pagethemes.page_field')) ?>
        <div class="admin-page-theme-choice">
          <select id="page-theme" name="page_theme_id" class="admin-select">
            <option value="0"<?= $pageThemeSelected === null ? ' selected' : '' ?>><?= admin_te('pagethemes.page_field_site') ?></option>
            <?php foreach ($themes as $pageThemeRow): ?>
              <option value="<?= (int) $pageThemeRow['id'] ?>" data-swatches="<?= $pageThemeH(implode(' ', $pageThemeSwatches($pageThemeRow))) ?>"<?= (int) $pageThemeRow['id'] === $selectedThemeId ? ' selected' : '' ?>><?= $pageThemeH((string) $pageThemeRow['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="admin-page-theme-swatches" data-page-theme-swatches aria-hidden="true"><?php foreach ($pageThemeSwatches($pageThemeSelected) as $pageThemeColor): ?><span class="admin-page-theme-swatch" style="background:<?= $pageThemeH($pageThemeColor) ?>;"></span><?php endforeach; ?></span>
        </div>
        <?php if ($themes === []): ?>
          <p class="admin-text-muted"><?= admin_te('pagethemes.page_field_none_yet') ?></p>
        <?php endif; ?>
      </div>
      <script src="<?= \App\Service\AssetVersion::url('/admin/assets/page-theme-field.js') ?>" defer></script>
