<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_theme_color_field.php';
require_once __DIR__ . '/_save_bar.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;

/**
 * One colour palette of the website: new (no id) or an existing one (?id=).
 * A name and the five colours on the left, a live preview of the website in
 * those colours on the right, and a contrast warning under the colours. See
 * THEMING.md, "Kleurenpaletten".
 *
 * WHAT SAVING DOES depends on the palette, and the screen says so up front:
 * saving the ACTIVE palette changes the website; saving any other palette
 * (or creating one) changes nothing a visitor sees until it is activated on
 * admin/theme.php.
 *
 * THE PREVIEW is admin/color-palette-preview.php in a frame, drawn with the
 * real core.css and the real tokens. admin/assets/color-palette-admin.js
 * repaints it on every change straight from the form — no reload, no
 * request — with the tints computed from ThemePalette::recipe(), printed
 * below as data, so the preview and the website share one set of formulas.
 * Unsaved changes stay in the form: the save bar (admin/_save_bar.php) says
 * so and warns before leaving, and "Annuleren" leaves without saving.
 *
 * A NEW PALETTE STARTS AS THE WEBSITE'S CURRENT COLOURS
 * (ColorPaletteService::defaults()), never as a palette written into this
 * file. Behind settings.manage, like the rest of Vormgeving.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$palette = null;

if ($idParam !== null) {
    $palette = $idParam === false ? null : ColorPaletteService::find($idParam);

    if ($palette === null) {
        http_response_code(404);
        exit(admin_t('palettes.not_found'));
    }
}

$isNew = $palette === null;
$paletteId = $isNew ? 0 : (int) $palette['id'];
$isActive = !$isNew && $palette['active'];

$errors = $_SESSION['admin_palette_errors'] ?? [];
$old = $_SESSION['admin_palette_old'] ?? null;
unset($_SESSION['admin_palette_errors'], $_SESSION['admin_palette_old']);

// A refused save's handback belongs to the palette it was typed for.
if (!is_array($old) || (int) ($old['id'] ?? 0) !== $paletteId) {
    $old = null;
}

$stored = $isNew ? ColorPaletteService::defaults() + ['name' => ''] : $palette;
$value = static function (string $field) use ($old, $stored): string {
    if ($old !== null && array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($stored[$field] ?? '');
};

$colors = [];
foreach (ThemeSettings::COLOR_KEYS as $colorKey) {
    $colors[$colorKey] = $value($colorKey);
}
$failingPairs = [];
foreach (ThemeColor::contrastWarnings($colors) as $warning) {
    $failingPairs[$warning['foreground'] . '/' . $warning['background']] = $warning['ratio'];
}

$notice = is_string($_GET['done'] ?? null) && in_array($_GET['done'], ['created', 'saved', 'saved_active', 'duplicated'], true)
    ? $_GET['done'] : '';

$csrfToken = Csrf::token();
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$decimalSeparator = \App\Service\Language\AdminLocale::current() === 'nl' ? ',' : '.';
$formatRatio = static fn (float $ratio): string => number_format($ratio, 1, $decimalSeparator, '');

/** The five colours, with the site theme's own labels and explanations. */
$colorFields = [
    'background_color' => ['label' => admin_t('design.colour_background'), 'help' => admin_t('design.colour_background_help')],
    'text_color' => ['label' => admin_t('design.colour_text'), 'help' => admin_t('design.colour_text_help')],
    'primary_color' => ['label' => admin_t('design.colour_primary'), 'help' => admin_t('design.colour_primary_help')],
    'on_primary_color' => ['label' => admin_t('design.colour_on_primary'), 'help' => admin_t('design.colour_on_primary_help')],
    'surface_color' => ['label' => admin_t('design.colour_surface'), 'help' => admin_t('design.colour_surface_help')],
];

$previewQuery = http_build_query(array_intersect_key($colors, array_flip(ThemeSettings::COLOR_KEYS)));

// The data the live preview needs, from the classes that paint the website.
$paletteModel = [
    'direct' => ThemeCss::DIRECT,
    'roles' => ThemeCss::ROLES,
    'recipe' => ThemePalette::recipe(),
];

$pageTitle = $isNew ? admin_t('palettes.new') : admin_t('palettes.edit_title', ['name' => (string) $palette['name']]);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/theme.php#paletten"><?= admin_te('palettes.back') ?></a></p>
  <h1>
    <?= $h($pageTitle) ?>
    <?php if (!$isNew): ?>
      <?php if ($isActive): ?>
        <span class="admin-badge admin-badge--published" data-palette-status="active"><?= admin_te('palettes.status_active') ?></span>
      <?php else: ?>
        <span class="admin-badge admin-badge--muted" data-palette-status="inactive"><?= admin_te('palettes.status_inactive') ?></span>
      <?php endif; ?>
    <?php endif; ?>
  </h1>

  <p class="admin-alert <?= $isActive ? 'admin-alert--warning' : 'admin-alert--note' ?>" data-palette-state="<?= $isNew ? 'new' : ($isActive ? 'active' : 'inactive') ?>">
    <?= admin_te($isNew ? 'palettes.state_new' : ($isActive ? 'palettes.state_active' : 'palettes.state_inactive')) ?>
  </p>

  <?php if ($notice !== '' && $errors === []): ?>
    <p class="admin-alert admin-alert--success" data-palette-notice="<?= $h($notice) ?>"><?= admin_te('palettes.done_' . $notice) ?></p>
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

  <form method="post" action="/api/admin/save-color-palette.php" class="admin-product-form admin-palette-form" autocomplete="off"
        data-palette-form data-palette-model="<?= $h(json_encode($paletteModel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) ?>"
        <?= $errors !== [] ? 'data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= $paletteId ?>">
    <?php endif; ?>

    <div class="admin-palette-editor">
      <div class="admin-palette-editor__settings">
        <section class="admin-card">
          <div class="admin-field">
            <?= admin_field_label('palette-name', admin_t('palettes.name'), admin_t('help.palettes.name'), true) ?>
            <input type="text" id="palette-name" name="name" maxlength="<?= ColorPaletteService::MAX_NAME_LENGTH ?>" required value="<?= $h($value('name')) ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
          </div>
        </section>

        <section class="admin-card">
          <h2><?= admin_te('palettes.colours_title') ?></h2>
          <p class="admin-text-muted"><?= admin_te('palettes.colours_intro') ?></p>
          <div class="admin-palette-editor__colors">
            <?php foreach ($colorFields as $colorKey => $field): ?>
              <?= admin_theme_color_field($colorKey, $field['label'], $field['help'], $value($colorKey)) ?>
            <?php endforeach; ?>
          </div>

          <div class="admin-alert admin-alert--warning" data-palette-contrast<?= $failingPairs === [] ? ' hidden' : '' ?> role="status">
            <p><?= admin_te('palettes.contrast_intro') ?></p>
            <ul class="admin-error-list">
              <?php foreach (ThemeColor::CONTRAST_PAIRS as [$foreground, $background]): ?>
                <?php $pairKey = $foreground . '/' . $background; ?>
                <li data-contrast-fg="<?= $h($foreground) ?>" data-contrast-bg="<?= $h($background) ?>"<?= array_key_exists($pairKey, $failingPairs) ? '' : ' hidden' ?>>
                  <?= admin_te('palettes.contrast_' . $foreground . '_' . $background) ?>:
                  <span data-contrast-ratio><?= $h($formatRatio((float) ($failingPairs[$pairKey] ?? 0))) ?></span>:1
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="admin-text-muted"><?= admin_te('palettes.contrast_note', ['min' => $formatRatio(ThemeColor::MIN_TEXT_CONTRAST)]) ?></p>
          </div>
        </section>
      </div>

      <section class="admin-card admin-palette-editor__preview" id="palet-voorbeeld">
        <h2><?= admin_te('palettes.preview_title') ?></h2>
        <p class="admin-text-muted"><?= admin_te('palettes.preview_intro') ?></p>
        <?php /* allow-same-origin and nothing else: the editor's script sets
                 the palette's tokens inside this document, which itself runs
                 no script (its Content-Security-Policy refuses scripts and
                 forms). */ ?>
        <iframe class="admin-palette-preview" title="<?= admin_te('palettes.preview_frame') ?>"
                src="/admin/color-palette-preview.php?<?= $h($previewQuery) ?>"
                data-palette-preview sandbox="allow-same-origin" referrerpolicy="same-origin"></iframe>
      </section>
    </div>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te($isNew ? 'palettes.create' : 'common.save') ?></button>
      <a href="/admin/theme.php#paletten" class="admin-btn-secondary" data-save-bar-discard data-palette-cancel><?= admin_te('palettes.cancel') ?></a>
    </section>
  </form>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/color-palette-admin.js') ?>" defer></script>
</body>
</html>
