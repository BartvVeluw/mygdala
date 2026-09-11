<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeSettings;

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$errors = $_SESSION['admin_theme_errors'] ?? [];
$old = $_SESSION['admin_theme_old'] ?? null;
unset($_SESSION['admin_theme_errors'], $_SESSION['admin_theme_old']);

$saved = isset($_GET['saved']);
$wasReset = isset($_GET['reset']);

$values = is_array($old) ? array_merge(ThemeSettings::all(), $old) : ThemeSettings::all();
$defaults = ThemeSettings::defaults();
$isDefault = ThemeSettings::isDefault();

$csrfToken = Csrf::token();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * The five colours, in the order they build on each other: what carries the
 * brand, what sits on top of it, then the three grounds and the type.
 *
 * @var array<string, array{label: string, help: string}>
 */
$colorFields = [
    'primary_color' => [
        'label' => admin_t('design.colour_primary'),
        'help' => admin_t('design.colour_primary_help'),
    ],
    'on_primary_color' => [
        'label' => admin_t('design.colour_on_primary'),
        'help' => admin_t('design.colour_on_primary_help'),
    ],
    'background_color' => [
        'label' => admin_t('design.colour_background'),
        'help' => admin_t('design.colour_background_help'),
    ],
    'surface_color' => [
        'label' => admin_t('design.colour_surface'),
        'help' => admin_t('design.colour_surface_help'),
    ],
    'text_color' => [
        'label' => admin_t('design.colour_text'),
        'help' => admin_t('design.colour_text_help'),
    ],
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_t('design.vormgeving_branding_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_t('design.vormgeving_branding') ?></h1>
  <p class="admin-text-muted"><?= admin_t('design.hier_bepaal_hoe_website') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('design.vormgeving_opgeslagen') ?></p>
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

  <form method="post" action="/api/admin/update-theme-settings.php" class="admin-product-form">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <section class="admin-card">
      <h2><?= admin_te('design.kleuren') ?></h2>
      <p class="admin-text-muted"><?= admin_te('design.vijf_kleuren_meer_randen') ?></p>

      <div class="admin-theme-colors">
        <?php foreach ($colorFields as $key => $field): ?>
          <?php $value = (string) ($values[$key] ?? $defaults[$key]); ?>
          <div class="admin-theme-color">
            <label for="theme-<?= $h($key) ?>"><?= $h($field['label']) ?></label>
            <div class="admin-theme-color__inputs">
              <input
                type="color"
                class="admin-theme-color__swatch"
                value="<?= $h($value) ?>"
                data-theme-color-for="theme-<?= $h($key) ?>"
                aria-label="Kleurkiezer voor <?= $h($field['label']) ?>"
                tabindex="-1">
              <input
                type="text"
                id="theme-<?= $h($key) ?>"
                name="<?= $h($key) ?>"
                value="<?= $h($value) ?>"
                maxlength="7"
                pattern="#?[0-9A-Fa-f]{6}"
                spellcheck="false"
                class="admin-theme-color__hex">
            </div>
            <p class="admin-text-muted"><?= $h($field['help']) ?></p>
            <p class="admin-text-muted"><?= admin_t('design.standaard', ['v1' => $h($defaults[$key])]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.typografie') ?></h2>
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
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.stijl') ?></h2>
      <p class="admin-text-muted"><?= admin_te('design.geldt_gewone_knoppen_ronde') ?></p>

      <div class="admin-form-row">
        <label for="theme-button-shape"><?= admin_te('design.knopvorm') ?>
          <select id="theme-button-shape" name="button_shape">
            <?php foreach (ThemeSettings::buttonShapes() as $key => $shape): ?>
              <option value="<?= $h($key) ?>" <?= ($values['button_shape'] ?? '') === $key ? 'selected' : '' ?>><?= $h($shape['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('design.voorbeeld') ?></h2>
      <p class="admin-text-muted"><?= admin_te('design.indruk_gekozen_kleuren_knopvorm') ?></p>

      <div class="admin-theme-preview" data-theme-preview>
        <p class="admin-theme-preview__heading" data-theme-preview-heading><?= admin_te('design.kop_koplettertype') ?></p>
        <p class="admin-theme-preview__body"><?= admin_t('design.lopende_tekst_zoals_bezoeker') ?></p>
        <div class="admin-theme-preview__card" data-theme-preview-card>
          <span class="admin-theme-preview__muted"><?= admin_te('design.kaart_zachtere_tekst') ?></span>
        </div>
        <span class="admin-theme-preview__btn" data-theme-preview-btn><?= admin_te('design.knop') ?></span>
      </div>
    </section>

    <div class="admin-theme-actions">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </div>
  </form>

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
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
</body>
</html>
