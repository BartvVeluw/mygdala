<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_tabs.php';
require_once __DIR__ . '/_font_library.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SiteSettings;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\FontLibrary;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeRegistry;
use App\Service\Theme\ThemeSettings;

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$errors = $_SESSION['admin_theme_errors'] ?? [];
$old = $_SESSION['admin_theme_old'] ?? null;
unset($_SESSION['admin_theme_errors'], $_SESSION['admin_theme_old']);

$saved = isset($_GET['saved']);
$wasReset = isset($_GET['reset']);
// ?updated=1 is the switch below; ?saved=1 the rest of this screen.
$moduleSaved = isset($_GET['updated']);

/**
 * The modules that are part of how the site looks and may be switched on and
 * off here after installation (ModuleDefinition::switchableFromAppearance(),
 * today Paginathema's). Asked of every registered module, on or off: the
 * switch has to be here to turn one back on. Core names none of them.
 *
 * @var array<string, \App\Module\ModuleDefinition> $appearanceModules
 */
$appearanceModules = array_filter(
    \App\Module\ModuleRegistry::all(),
    static fn (\App\Module\ModuleDefinition $module): bool => $module->switchableFromAppearance()
);

/**
 * The colour palettes (Branding & Design 2.0, THEMING.md "Kleurenpaletten"):
 * every palette with its state, and what can be done to it. The colours
 * themselves are edited on admin/color-palette.php; this screen activates,
 * duplicates and deletes. ?palette=<done> is a palette action's outcome,
 * a refusal comes back as a session flash.
 */
$palettes = ColorPaletteService::all();
$activePalette = null;
foreach ($palettes as $palette) {
    if ($palette['active']) {
        $activePalette = $palette;
    }
}
$paletteNotice = is_string($_GET['palette'] ?? null)
    && in_array($_GET['palette'], ['deleted', 'duplicated', 'activated'], true) ? $_GET['palette'] : '';
$paletteError = $_SESSION['admin_palettes_error'] ?? null;
unset($_SESSION['admin_palettes_error']);

/** The five colours, in the page themes' swatch order. */
$swatchKeys = ['background_color', 'surface_color', 'text_color', 'primary_color', 'on_primary_color'];

$values = is_array($old) ? array_merge(ThemeSettings::all(), $old) : ThemeSettings::all();
$isDefault = ThemeSettings::isDefault();

/**
 * The Font Library (Font Library 1.0, THEMING.md "Font Library"): every
 * family with its variants and who uses it, on the tab Lettertypen. The
 * families that have a file can be chosen for the headings and the body
 * text in Typografie; with an empty library that choice is not shown and
 * the built-in pairings are all there is. ?fonts=deleted is a delete's
 * outcome; a refusal comes back as a session flash.
 */
$fontFamilies = FontLibrary::families();
$usableFonts = FontLibrary::usableFamilies();
$fontUsage = [];
foreach ($fontFamilies as $fontFamily) {
    $fontUsage[(int) $fontFamily['id']] = FontLibrary::usage((int) $fontFamily['id']);
}
$fontsNotice = ($_GET['fonts'] ?? '') === 'deleted';
$fontsError = $_SESSION['admin_fonts_error'] ?? null;
unset($_SESSION['admin_fonts_error']);
$fontsBytes = FontLibrary::totalBytes();

/**
 * The button style library (Button Styles 2.0, THEMING.md "Knopstijlen"), on
 * the tab Knoppen: every style with the defaults it is and how many content
 * buttons chose it. A style is edited on admin/button-style.php; this screen
 * duplicates it, makes it a default and deletes it. ?buttons=<done> is an
 * action's outcome, a refusal comes back as a session flash.
 */
$buttonStyles = ButtonStyles::all();
$buttonsNotice = is_string($_GET['buttons'] ?? null)
    && in_array($_GET['buttons'], ['deleted', 'default'], true) ? $_GET['buttons'] : '';
$buttonsError = $_SESSION['admin_buttons_error'] ?? null;
unset($_SESSION['admin_buttons_error']);

/**
 * The Global Themes (Themes 2.0, THEMING.md "Theme kiezen"), on the tab Thema:
 * every theme of the closed list in code, which one the website uses, and
 * a preview of each (admin/theme-preview.php). Three readings, each from its
 * owner, so this screen repeats no fallback rule:
 *
 *   $storedThemeKey  the row as it is (ThemeSettings), '' when there is none
 *   $activeTheme     what the website really uses (ThemeRegistry::active())
 *   $unknownTheme    a stored key the list does not know: the site runs on
 *                    legacy meanwhile, and the editor is told, but the row is
 *                    left as it is until they choose a theme themselves.
 *
 * ?themes=activated is a switch's outcome, a failure comes back as a session
 * flash.
 */
$themes = ThemeRegistry::all();
$activeTheme = ThemeRegistry::active();
$storedThemeKey = ThemeSettings::activeThemeKey();
$unknownTheme = $storedThemeKey !== '' && ThemeRegistry::find($storedThemeKey) === null;
$themesNotice = ($_GET['themes'] ?? '') === 'activated';
$themesError = $_SESSION['admin_themes_error'] ?? null;
unset($_SESSION['admin_themes_error']);
$themeLabel = static fn (\App\Service\Theme\ThemeDefinition $theme): string
    => admin_registry_label('globaltheme.' . $theme->key . '.label', $theme->label);

/** The typography preview: the site as it is, with the choice in the form. */
$typographyPreviewQuery = http_build_query(array_intersect_key($values, array_flip(['font_pairing', 'heading_font_family_id', 'body_font_family_id'])));

$csrfToken = Csrf::token();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('design.vormgeving_branding_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<?= admin_font_faces(array_keys($usableFonts)) ?>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_t('design.vormgeving_branding') ?></h1>
  <p class="admin-text-muted"><?= admin_t('design.hier_bepaal_hoe_website') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('design.vormgeving_opgeslagen') ?></p>
  <?php endif; ?>

  <?php if ($moduleSaved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('design.module_saved') ?></p>
  <?php endif; ?>

  <?php if ($wasReset): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('design.standaardvormgeving_hersteld_bedrijfsgegeven') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($themesNotice): ?>
    <p class="admin-alert admin-alert--success" data-themes-notice><?= admin_te('themes.done_activated', ['name' => $themeLabel($activeTheme)]) ?></p>
  <?php endif; ?>

  <?php if (is_string($themesError)): ?>
    <p class="admin-alert admin-alert--error" data-themes-error><?= $h($themesError) ?></p>
  <?php endif; ?>

  <?php if ($paletteNotice !== ''): ?>
    <p class="admin-alert admin-alert--success" data-palette-notice="<?= $h($paletteNotice) ?>"><?= $paletteNotice === 'activated'
        ? admin_te('palettes.done_activated', ['name' => (string) ($activePalette['name'] ?? '')])
        : admin_te('palettes.done_' . $paletteNotice) ?></p>
  <?php endif; ?>

  <?php if (is_string($paletteError)): ?>
    <p class="admin-alert admin-alert--error" data-palette-error><?= $h($paletteError) ?></p>
  <?php endif; ?>

  <?php if ($buttonsNotice !== ''): ?>
    <p class="admin-alert admin-alert--success" data-buttons-notice="<?= $h($buttonsNotice) ?>"><?= admin_te('buttons.done_' . $buttonsNotice) ?></p>
  <?php endif; ?>

  <?php if (is_string($buttonsError)): ?>
    <p class="admin-alert admin-alert--error" data-buttons-error><?= $h($buttonsError) ?></p>
  <?php endif; ?>

  <?php if ($fontsNotice): ?>
    <p class="admin-alert admin-alert--success" data-fonts-notice><?= admin_te('fonts.done_deleted') ?></p>
  <?php endif; ?>

  <?php if (is_string($fontsError)): ?>
    <p class="admin-alert admin-alert--error" data-fonts-error><?= $h($fontsError) ?></p>
  <?php endif; ?>

  <?php /* Four tabs: the Global Theme (the highest layer, so first), the
           look of the site, the Font Library it can choose from, and the
           button styles (admin/_admin_tabs.php). Every form keeps its own
           endpoint. */ ?>
  <?php admin_tabs_start('theme', [
      'thema' => admin_t('themes.tab'),
      'stijl' => admin_t('fonts.tab_style'),
      'lettertypen' => admin_t('fonts.tab_fonts'),
      'knoppen' => admin_t('buttons.tab'),
  ], [
      'label' => admin_t('fonts.tabs_label'),
      'force' => match (true) {
          $themesNotice || is_string($themesError) || $unknownTheme || ($_GET['tab'] ?? '') === 'thema' => 'thema',
          $fontsNotice || is_string($fontsError) || ($_GET['tab'] ?? '') === 'lettertypen' => 'lettertypen',
          $buttonsNotice !== '' || is_string($buttonsError) || ($_GET['tab'] ?? '') === 'knoppen' => 'knoppen',
          $saved || $errors !== [] => 'stijl',
          default => null,
      },
  ]); ?>

  <?php admin_tab_panel('thema'); ?>
  <section class="admin-card" id="thema" data-themes>
    <header class="admin-page-head">
      <div>
        <h2 class="admin-page-head__title"><?= admin_te('themes.title') ?></h2>
        <p class="admin-page-head__desc"><?= admin_te('themes.intro') ?></p>
      </div>
    </header>

    <?php if ($unknownTheme): ?>
      <p class="admin-alert admin-alert--warning" data-themes-unknown><?= admin_te('themes.unknown_stored', [
          'key' => $storedThemeKey,
          'fallback' => $themeLabel($activeTheme),
      ]) ?></p>
    <?php endif; ?>

    <div class="admin-page-sections">
      <?php foreach ($themes as $themeKey => $theme): ?>
        <?php
        $themeIsActive = $themeKey === $activeTheme->key;
        // The fallback in use for an unknown stored key is active, but not
        // chosen: storing it is how the editor settles the warning.
        $themeCanActivate = !$themeIsActive || $unknownTheme;
        ?>
        <div class="admin-section-row admin-palette-row" data-theme-row="<?= $h($themeKey) ?>"<?= $themeIsActive ? ' data-theme-active' : '' ?>>
          <div class="admin-section-row__body">
            <p class="admin-section-row__name">
              <?= $h($themeLabel($theme)) ?>
              <?php if ($themeIsActive): ?>
                <span class="admin-badge admin-badge--published" data-theme-status="active"><?= admin_te('themes.status_active') ?></span>
              <?php endif; ?>
            </p>
          </div>
          <div class="admin-section-row__actions">
            <?php /* Loads the preview into the frame below (target = the
                     frame's name): no script, and without a frame it still
                     opens the preview on its own. */ ?>
            <a href="/admin/theme-preview.php?theme=<?= $h(rawurlencode($themeKey)) ?>" class="admin-section-row__edit" target="theme-preview" data-theme-preview-link><?= admin_te('themes.preview') ?><span class="admin-visually-hidden">: <?= $h($themeLabel($theme)) ?></span></a>
            <?php if ($themeCanActivate): ?>
              <form method="post" action="/api/admin/save-active-theme.php" class="admin-inline-form" data-theme-activate>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="theme" value="<?= $h($themeKey) ?>">
                <button type="submit" class="admin-btn-text"><?= admin_te('themes.activate') ?><span class="admin-visually-hidden">: <?= $h($themeLabel($theme)) ?></span></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="admin-text-muted" data-themes-keeps><?= admin_te('themes.keeps') ?></p>

    <?php /* The website's start page in a theme (admin/theme-preview.php),
             first the one the website uses. Sandboxed like the block
             library's preview: allow-scripts and nothing else, so the
             page's own scripts run (the hero, the menu) in an opaque origin
             that cannot reach this screen, its session or its storage; no
             forms, no popups, no navigation of this screen. */ ?>
    <h3><?= admin_te('themes.preview_title') ?></h3>
    <p class="admin-text-muted"><?= admin_te('themes.preview_intro') ?></p>
    <iframe class="admin-theme-preview" name="theme-preview" title="<?= admin_te('themes.preview_frame') ?>"
            src="/admin/theme-preview.php?theme=<?= $h(rawurlencode($activeTheme->key)) ?>"
            data-theme-preview sandbox="allow-scripts" referrerpolicy="same-origin" loading="lazy"></iframe>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('stijl'); ?>
  <section class="admin-card" id="paletten" data-palettes>
    <header class="admin-page-head">
      <div>
        <h2 class="admin-page-head__title"><?= admin_te('palettes.title') ?></h2>
        <p class="admin-page-head__desc"><?= admin_t('palettes.intro') ?></p>
      </div>
      <a href="/admin/color-palette.php" class="admin-btn-link" data-palette-new><?= admin_te('palettes.new') ?></a>
    </header>

    <?= admin_info_panel(admin_t('help.palettes.overview')) ?>

    <?php if ($activePalette === null): ?>
      <p class="admin-alert admin-alert--warning" data-palettes-none-active><?= admin_te('palettes.none_active') ?></p>
    <?php endif; ?>

    <div class="admin-page-sections">
      <?php foreach ($palettes as $palette): ?>
        <?php $paletteId = (int) $palette['id']; ?>
        <div class="admin-section-row admin-palette-row" data-palette-row="<?= $paletteId ?>"<?= $palette['active'] ? ' data-palette-active' : '' ?>>
          <div class="admin-section-row__body">
            <p class="admin-section-row__name">
              <span class="admin-page-theme-swatches" aria-hidden="true">
                <?php foreach ($swatchKeys as $swatchKey): ?>
                  <span class="admin-page-theme-swatch" style="background:<?= $h((string) $palette[$swatchKey]) ?>;"></span>
                <?php endforeach; ?>
              </span>
              <?= $h((string) $palette['name']) ?>
              <?php if ($palette['active']): ?>
                <span class="admin-badge admin-badge--published" data-palette-status="active"><?= admin_te('palettes.status_active') ?></span>
              <?php else: ?>
                <span class="admin-badge admin-badge--muted" data-palette-status="inactive"><?= admin_te('palettes.status_inactive') ?></span>
              <?php endif; ?>
            </p>
            <?php if ($palette['active']): ?>
              <p class="admin-section-row__note"><?= admin_te('palettes.active_no_delete') ?></p>
            <?php endif; ?>
          </div>
          <div class="admin-section-row__actions">
            <a href="/admin/color-palette.php?id=<?= $paletteId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
            <?php if (!$palette['active']): ?>
              <form method="post" action="/api/admin/activate-color-palette.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('palettes.activate_confirm_title'),
                  admin_t('palettes.activate_confirm', ['name' => (string) $palette['name']]),
                  admin_t('palettes.activate')
              ) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $paletteId ?>">
                <button type="submit" class="admin-btn-text"><?= admin_te('palettes.activate') ?></button>
              </form>
            <?php endif; ?>
            <form method="post" action="/api/admin/duplicate-color-palette.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $paletteId ?>">
              <button type="submit" class="admin-btn-text"><?= admin_te('palettes.duplicate') ?></button>
            </form>
            <?php if (!$palette['active'] && count($palettes) > 1): ?>
              <form method="post" action="/api/admin/delete-color-palette.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('palettes.delete_confirm_title'),
                  admin_t('palettes.delete_confirm', ['name' => (string) $palette['name']]),
                  admin_t('common.delete')
              ) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $paletteId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <form method="post" action="/api/admin/update-theme-settings.php" class="admin-product-form" data-theme-typography>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card" id="typografie">
      <h2><?= admin_te('design.appearance_title') ?></h2>
      <p class="admin-text-muted"><?= admin_te('design.appearance_intro') ?></p>

      <h3><?= admin_te('design.typografie') ?></h3>
      <p class="admin-text-muted"><?= admin_te('design.e_n_combinatie_kop') ?></p>
      <div class="admin-form-row">
        <label for="theme-font-pairing"><?= admin_te('design.lettertypecombinatie') ?>
          <select id="theme-font-pairing" name="font_pairing">
            <?php foreach (ThemeFonts::all() as $key => $pairing): ?>
              <option value="<?= $h($key) ?>" <?= ($values['font_pairing'] ?? '') === $key ? 'selected' : '' ?>><?= $h(admin_registry_label('themefont.' . $key, (string) $pairing['label'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <?php if ($usableFonts !== []): ?>
        <?php /* A library family per role, over the pairing. Only shown when the
                 library has a family with a file: an empty library means
                 exactly the screen there was before. */ ?>
        <?php foreach (['heading' => 'heading_font_family_id', 'body' => 'body_font_family_id'] as $fontRole => $fontKey): ?>
          <div class="admin-field">
            <?= admin_field_label('theme-' . $fontKey, admin_t('fonts.role_label_' . $fontRole), admin_t('help.fonts.role_' . $fontRole)) ?>
            <?= admin_font_role_select('theme-' . $fontKey, $fontKey, (string) ($values[$fontKey] ?? ''), $usableFonts) ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="admin-text-muted" data-fonts-empty-hint><?= admin_t('fonts.empty_hint') ?></p>
      <?php endif; ?>

      <?php /* The same preview document as the colour palettes
               (admin/color-palette-preview.php): the active palette, with the
               fonts chosen here. Reloaded by admin/assets/theme-fonts-admin.js
               when a choice changes; nothing is saved until Opslaan. Framed
               like the palette editor frames it: allow-same-origin and
               nothing else, because the document itself runs no script (its
               Content-Security-Policy refuses scripts and forms). */ ?>
      <p class="admin-text-muted"><?= admin_te('fonts.preview_intro') ?></p>
      <iframe class="admin-typography-preview" title="<?= admin_te('palettes.preview_frame') ?>"
              src="/admin/color-palette-preview.php?<?= $h($typographyPreviewQuery) ?>"
              data-typography-preview sandbox="allow-same-origin" referrerpolicy="same-origin"></iframe>

      <?php /* The button shape moved into the button styles (tab Knoppen):
               one place for everything about a button, and no second
               setting that could disagree with it. */ ?>
      <h3><?= admin_te('buttons.tab') ?></h3>
      <p class="admin-text-muted" data-buttons-moved><?= admin_t('buttons.moved_hint') ?></p>

      <div class="admin-theme-actions">
        <button type="submit"><?= admin_te('common.save') ?></button>
      </div>
    </section>
  </form>

  <?php if ($appearanceModules !== []): ?>
  <section class="admin-card" id="onderdelen">
    <h2><?= admin_te('design.modules_title') ?></h2>
    <?= admin_info_panel(admin_t('help.design.modules')) ?>
    <?php foreach ($appearanceModules as $moduleKey => $appearanceModule): ?>
      <?php
      $modulePinned = \App\Module\ModuleConfig::isPinnedByEnvironment($moduleKey);
      $moduleOn = \App\Module\ModuleRegistry::isEnabled($moduleKey);
      ?>
      <form method="post" action="/api/admin/update-appearance-module.php" class="admin-product-form" data-appearance-module="<?= $h($moduleKey) ?>">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="module" value="<?= $h($moduleKey) ?>">
        <input type="hidden" name="enabled" value="0">
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="enabled" value="1"<?= $moduleOn ? ' checked' : '' ?><?= $modulePinned ? ' disabled' : '' ?>>
            <?= $h($appearanceModule->label()) ?>
          </label>
          <?= admin_help($appearanceModule->label(), $appearanceModule->description()) ?>
        </div>
        <?php if ($modulePinned): ?>
          <p class="admin-text-muted"><?= admin_te('design.module_pinned', ['variable' => \App\Module\ModuleConfig::variableName($moduleKey)]) ?></p>
        <?php else: ?>
          <p class="admin-text-muted"><?= admin_te($moduleOn ? 'design.module_on' : 'design.module_off') ?></p>
          <button type="submit" class="admin-btn-secondary"><?= admin_te('common.save') ?></button>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('design.standaardvormgeving_herstellen') ?></h2>
    <p class="admin-text-muted">
      <?= admin_t('design.zet_kleuren_lettertype_knopvorm') ?>
    </p>
    <?php if ($isDefault): ?>
      <p class="admin-text-muted"><?= admin_te('design.gebruikt_moment_al_standaardvormgeving') ?></p>
    <?php else: ?>
      <form method="post" action="/api/admin/reset-theme-settings.php" onsubmit="return confirm('Vormgeving terugzetten naar de standaard? Je bedrijfsgegevens, logo en favicon blijven ongewijzigd.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <button type="submit" class="admin-btn-secondary admin-theme-reset"><?= admin_te('design.standaardvormgeving_herstellen_2') ?></button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('design.wat_er_nu_meegestuurd') ?></h2>
    <p class="admin-text-muted"><?= admin_te('design.website_laadt_n_vaste') ?></p>
    <?php $declarations = ThemeCss::declarations(); ?>
    <?php if ($declarations === []): ?>
      <p class="admin-text-muted"><?= admin_te('design.moment_niets_site_draait') ?></p>
    <?php else: ?>
      <pre class="admin-code-block"><?php foreach ($declarations as $property => $value): ?>
<?= $h($property) ?>: <?= $h($value) ?>;
<?php endforeach; ?></pre>
    <?php endif; ?>
    <p class="admin-text-muted"><?= admin_t('design.current_site_name', ['name' => $h(SiteSettings::get('site_name'))]) ?></p>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('lettertypen'); ?>
  <section class="admin-card" id="lettertypen" data-font-library>
    <header class="admin-page-head">
      <div>
        <h2 class="admin-page-head__title"><?= admin_te('fonts.title') ?></h2>
        <p class="admin-page-head__desc"><?= admin_te('fonts.intro') ?></p>
      </div>
      <a href="/admin/font-family.php" class="admin-btn-link" data-font-new><?= admin_te('fonts.new') ?></a>
    </header>

    <?= admin_font_licence_warning() ?>

    <?php if ($fontFamilies === []): ?>
      <p class="admin-text-muted" data-fonts-empty><?= admin_te('fonts.none_yet') ?></p>
    <?php else: ?>
      <div class="admin-page-sections">
        <?php foreach ($fontFamilies as $fontFamily): ?>
          <?php
          $fontId = (int) $fontFamily['id'];
          $familyUsage = $fontUsage[$fontId];
          $familyInUse = FontLibrary::inUse($familyUsage);
          ?>
          <div class="admin-section-row admin-font-row" data-font-row="<?= $fontId ?>"<?= $familyInUse ? ' data-font-in-use' : '' ?>>
            <div class="admin-section-row__body">
              <p class="admin-section-row__name">
                <span class="admin-font-sample"<?= admin_font_sample_style($fontId, (string) $fontFamily['category']) ?>><?= $h((string) $fontFamily['name']) ?></span>
                <?php if ($familyInUse): ?>
                  <span class="admin-badge admin-badge--published" data-font-status="in-use"><?= admin_te('fonts.status_in_use') ?></span>
                <?php else: ?>
                  <span class="admin-badge admin-badge--muted" data-font-status="unused"><?= admin_te('fonts.status_unused') ?></span>
                <?php endif; ?>
                <?php if ((int) $fontFamily['missing_files'] > 0): ?>
                  <span class="admin-badge admin-badge--canceled" data-font-missing><?= admin_te('fonts.file_missing') ?></span>
                <?php endif; ?>
              </p>
              <p class="admin-section-row__note"><?= $h(admin_font_variant_summary($fontFamily['variants'])) ?></p>
              <?php if ($familyInUse): ?>
                <p class="admin-section-row__note" data-font-usage><?= $h(admin_font_usage_line($familyUsage)) ?></p>
              <?php endif; ?>
            </div>
            <div class="admin-section-row__actions">
              <a href="/admin/font-family.php?id=<?= $fontId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
              <?php if (!$familyInUse): ?>
                <form method="post" action="/api/admin/delete-font-family.php" class="admin-inline-form"<?= admin_confirm_attributes(
                    admin_t('fonts.delete_confirm_title'),
                    admin_t('fonts.delete_confirm', ['name' => (string) $fontFamily['name']]),
                    admin_t('common.delete')
                ) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $fontId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="admin-text-muted" data-fonts-storage><?= admin_te('fonts.storage_used', [
        'used' => admin_font_megabytes($fontsBytes),
        'max' => (string) (int) round(FontLibrary::MAX_LIBRARY_BYTES / (1024 * 1024)),
    ]) ?></p>
  </section>

  <?= admin_font_help() ?>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('knoppen'); ?>
  <section class="admin-card" id="knoppen" data-button-styles>
    <header class="admin-page-head">
      <div>
        <h2 class="admin-page-head__title"><?= admin_te('buttons.title') ?></h2>
        <p class="admin-page-head__desc"><?= admin_te('buttons.intro') ?></p>
      </div>
      <a href="/admin/button-style.php" class="admin-btn-link" data-button-style-new><?= admin_te('buttons.new') ?></a>
    </header>

    <?= admin_info_panel(admin_t('help.buttons.overview')) ?>

    <div class="admin-page-sections">
      <?php foreach ($buttonStyles as $buttonStyle): ?>
        <?php
        $buttonStyleId = (int) $buttonStyle['id'];
        $buttonUses = (int) $buttonStyle['uses'];
        $buttonInUse = ButtonStyles::inUse(['roles' => $buttonStyle['roles'], 'buttons' => $buttonUses]);
        $usesKey = $buttonUses === 0 ? 'buttons.uses_none' : ($buttonUses === 1 ? 'buttons.uses_one' : 'buttons.uses_many');
        ?>
        <div class="admin-section-row admin-palette-row" data-button-style-row="<?= $buttonStyleId ?>"<?= $buttonInUse ? ' data-button-style-in-use' : '' ?>>
          <div class="admin-section-row__body">
            <p class="admin-section-row__name">
              <?= $h((string) $buttonStyle['name']) ?>
              <?php foreach ($buttonStyle['roles'] as $buttonRole): ?>
                <span class="admin-badge admin-badge--published" data-button-status="<?= $h($buttonRole) ?>"><?= admin_te('buttons.status_' . $buttonRole) ?></span>
              <?php endforeach; ?>
              <?php if ($buttonStyle['roles'] === []): ?>
                <span class="admin-badge admin-badge--muted" data-button-status="available"><?= admin_te('buttons.status_available') ?></span>
              <?php endif; ?>
            </p>
            <p class="admin-section-row__note" data-button-style-uses="<?= $buttonUses ?>"><?= admin_te($usesKey, ['count' => $buttonUses]) ?></p>
            <?php if ($buttonStyle['roles'] !== []): ?>
              <p class="admin-section-row__note"><?= admin_te('buttons.default_no_delete') ?></p>
            <?php elseif ($buttonInUse): ?>
              <p class="admin-section-row__note"><?= admin_te('buttons.in_use_no_delete') ?></p>
            <?php endif; ?>
          </div>
          <div class="admin-section-row__actions">
            <a href="/admin/button-style.php?id=<?= $buttonStyleId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
            <?php foreach (['primary', 'secondary'] as $buttonRole): ?>
              <?php if (!in_array($buttonRole, $buttonStyle['roles'], true)): ?>
                <form method="post" action="/api/admin/set-default-button-style.php" class="admin-inline-form"<?= admin_confirm_attributes(
                    admin_t('buttons.make_' . $buttonRole . '_confirm_title'),
                    admin_t('buttons.make_' . $buttonRole . '_confirm', ['name' => (string) $buttonStyle['name']]),
                    admin_t('buttons.make_default')
                ) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $buttonStyleId ?>">
                  <input type="hidden" name="role" value="<?= $h($buttonRole) ?>">
                  <button type="submit" class="admin-btn-text"><?= admin_te('buttons.make_' . $buttonRole) ?></button>
                </form>
              <?php endif; ?>
            <?php endforeach; ?>
            <form method="post" action="/api/admin/duplicate-button-style.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $buttonStyleId ?>">
              <button type="submit" class="admin-btn-text"><?= admin_te('buttons.duplicate') ?></button>
            </form>
            <?php if (!$buttonInUse): ?>
              <form method="post" action="/api/admin/delete-button-style.php" class="admin-inline-form"<?= admin_confirm_attributes(
                  admin_t('buttons.delete_confirm_title'),
                  admin_t('buttons.delete_confirm', ['name' => (string) $buttonStyle['name']]),
                  admin_t('common.delete')
              ) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $buttonStyleId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tabs_end(); ?>
</main>
<?= admin_confirm_dialog() ?>
<?php admin_tabs_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-fonts-admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
