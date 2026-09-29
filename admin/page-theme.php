<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_theme_color_field.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\ThemeColor;
use App\Service\Theme\ThemeFonts;

/**
 * One page theme: new (no id) or an existing one (?id=). A name, the five
 * colours of the site theme's own colour field (admin/_theme_color_field.php),
 * a font pairing from the closed ThemeFonts list, a live preview and a
 * contrast warning. The slug is never typed: it is made from the name.
 *
 * A NEW THEME STARTS AS THE SITE THEME (PageThemeService::defaults()), never
 * as a palette written into this file: it is readable from its first save,
 * and the editor changes what should be different.
 *
 * THE PREVIEW is a real page, not a sketch: admin/page-theme-preview.php in a
 * sandboxed frame, with the site's own core.css and this theme's tokens,
 * reloaded by admin/assets/page-theme-admin.js with what is in the form (not
 * yet saved). It validates those values exactly like a save does.
 *
 * THE CONTRAST WARNING is computed here, from the values on screen
 * (PageThemeService::contrastWarnings()), and kept up to date by the same
 * script while the editor types. It warns; it never stops a save.
 *
 * Behind page_themes.manage, like the overview (admin/page-themes.php).
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('page_themes.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$theme = null;

if ($idParam !== null && $idParam !== false) {
    $theme = $idParam > 0 ? PageThemeService::theme($idParam) : null;

    if ($theme === null) {
        http_response_code(404);
        exit(admin_t('pagethemes.not_found'));
    }
}

$isNew = $theme === null;
$themeId = $isNew ? 0 : (int) $theme['id'];

$errors = $_SESSION['admin_page_theme_errors'] ?? [];
$old = $_SESSION['admin_page_theme_old'] ?? null;
unset($_SESSION['admin_page_theme_errors'], $_SESSION['admin_page_theme_old']);

// A refused save's handback belongs to the theme it was typed for.
if (!is_array($old) || (int) ($old['id'] ?? 0) !== $themeId) {
    $old = null;
}

$stored = $isNew ? PageThemeService::defaults() + ['name' => ''] : $theme;
$value = static function (string $field) use ($old, $stored): string {
    if ($old !== null && array_key_exists($field, $old)) {
        return (string) $old[$field];
    }

    return (string) ($stored[$field] ?? '');
};

$colors = [];
foreach (\App\Service\Theme\ThemeSettings::COLOR_KEYS as $colorKey) {
    $colors[$colorKey] = $value($colorKey);
}
$contrastWarnings = PageThemeService::contrastWarnings($colors);
$failingPairs = [];
foreach ($contrastWarnings as $warning) {
    $failingPairs[$warning['foreground'] . '/' . $warning['background']] = $warning['ratio'];
}

$usingPages = $isNew ? [] : PageThemeService::pagesUsing($themeId);

// The preview starts from what the form shows; the script takes over from
// there. The preview validates every value itself.
$previewValues = [];
foreach (PageThemeService::VALUE_FIELDS as $field) {
    $previewValues[$field] = $value($field);
}
$previewQuery = http_build_query($previewValues);

$csrfToken = Csrf::token();
$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
$decimalSeparator = \App\Service\Language\AdminLocale::current() === 'nl' ? ',' : '.';
$formatRatio = static fn (float $ratio): string => number_format($ratio, 1, $decimalSeparator, '');

/** The five colours, with the site theme's own labels and explanations. */
$colorFields = [
    'primary_color' => ['label' => admin_t('design.colour_primary'), 'help' => admin_t('design.colour_primary_help')],
    'on_primary_color' => ['label' => admin_t('design.colour_on_primary'), 'help' => admin_t('design.colour_on_primary_help')],
    'background_color' => ['label' => admin_t('design.colour_background'), 'help' => admin_t('design.colour_background_help')],
    'surface_color' => ['label' => admin_t('design.colour_surface'), 'help' => admin_t('design.colour_surface_help')],
    'text_color' => ['label' => admin_t('design.colour_text'), 'help' => admin_t('design.colour_text_help')],
];

$pageTitle = $isNew ? admin_t('pagethemes.new') : admin_t('pagethemes.edit_title', ['name' => (string) $theme['name']]);
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
  <p><a href="/admin/page-themes.php"><?= admin_te('pagethemes.back') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>

  <?php if (isset($_GET['duplicated']) && $errors === []): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('pagethemes.done_duplicated') ?></p>
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

  <form method="post" action="/api/admin/save-page-theme.php" class="admin-product-form" data-page-theme-form autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= $themeId ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('pagethemes.section_name') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('page-theme-name', admin_t('pagethemes.name'), admin_t('help.pagethemes.name'), true) ?>
        <input type="text" id="page-theme-name" name="name" maxlength="<?= PageThemeService::MAX_NAME_LENGTH ?>" required value="<?= $h($value('name')) ?>"<?= isset($errors['name']) ? ' aria-invalid="true"' : '' ?>>
      </div>
      <?php if (!$isNew): ?>
        <p class="admin-text-muted"><?= admin_te('pagethemes.slug_note', ['slug' => (string) $theme['slug']]) ?></p>
      <?php endif; ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.kleuren') ?></h2>
      <p class="admin-text-muted"><?= admin_te('pagethemes.colours_intro') ?></p>
      <div class="admin-theme-colors">
        <?php foreach ($colorFields as $colorKey => $field): ?>
          <?= admin_theme_color_field($colorKey, $field['label'], $field['help'], $value($colorKey)) ?>
        <?php endforeach; ?>
      </div>

      <div class="admin-alert admin-alert--warning" data-page-theme-contrast<?= $contrastWarnings === [] ? ' hidden' : '' ?> role="status">
        <p><?= admin_te('pagethemes.contrast_intro') ?></p>
        <ul class="admin-error-list">
          <?php foreach (PageThemeService::CONTRAST_PAIRS as [$foreground, $background]): ?>
            <?php $pairKey = $foreground . '/' . $background; ?>
            <li data-contrast-fg="<?= $h($foreground) ?>" data-contrast-bg="<?= $h($background) ?>"<?= array_key_exists($pairKey, $failingPairs) ? '' : ' hidden' ?>>
              <?= admin_te('pagethemes.contrast_' . $foreground . '_' . $background) ?>:
              <span data-contrast-ratio><?= $h($formatRatio((float) ($failingPairs[$pairKey] ?? 0))) ?></span>:1
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="admin-text-muted"><?= admin_te('pagethemes.contrast_note', ['min' => $formatRatio(ThemeColor::MIN_TEXT_CONTRAST)]) ?></p>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.typografie') ?></h2>
      <div class="admin-field">
        <?= admin_field_label('theme-font_pairing', admin_t('design.lettertypecombinatie'), admin_t('help.pagethemes.fonts')) ?>
        <select id="theme-font_pairing" name="font_pairing" class="admin-select"<?= isset($errors['font_pairing']) ? ' aria-invalid="true"' : '' ?>>
          <?php foreach (ThemeFonts::all() as $pairingKey => $pairing): ?>
            <option value="<?= $h($pairingKey) ?>"<?= $value('font_pairing') === $pairingKey ? ' selected' : '' ?>><?= $h(admin_registry_label('themefont.' . $pairingKey, (string) $pairing['label'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.voorbeeld') ?></h2>
      <p class="admin-text-muted"><?= admin_te('pagethemes.preview_intro') ?></p>
      <?php /* Sandboxed with nothing allowed: no script, no form, no same
               origin. The document itself refuses scripts and forms too
               (its Content-Security-Policy); it only has to be looked at. */ ?>
      <iframe class="admin-page-theme-preview" title="<?= admin_te('pagethemes.preview_frame') ?>"
              src="/admin/page-theme-preview.php?<?= $h($previewQuery) ?>"
              data-page-theme-preview sandbox="" referrerpolicy="same-origin" loading="lazy"></iframe>
    </section>

    <?php if (!$isNew): ?>
      <section class="admin-card">
        <h2><?= admin_te('pagethemes.used_by') ?></h2>
        <?php if ($usingPages === []): ?>
          <p class="admin-text-muted"><?= admin_te('pagethemes.used_by_none') ?></p>
        <?php else: ?>
          <ul>
            <?php foreach ($usingPages as $usingPage): ?>
              <li><a href="/admin/page.php?id=<?= (int) $usingPage['id'] ?>"><?= $h($usingPage['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te($isNew ? 'pagethemes.create' : 'common.save') ?></button>
    </section>
  </form>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/page-theme-admin.js') ?>" defer></script>
</body>
</html>
