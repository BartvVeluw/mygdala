<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
        'label' => 'Primair (accent)',
        'help' => 'Knoppen, links, iconen en lijnen. Alles wat opvalt.',
    ],
    'on_primary_color' => [
        'label' => 'Tekst op primair',
        'help' => 'De tekst en iconen bovenop een gevulde knop. Moet goed leesbaar zijn op de primaire kleur.',
    ],
    'background_color' => [
        'label' => 'Achtergrond',
        'help' => 'De ondergrond van elke pagina. Bepaalt ook de kleur van de browserbalk op mobiel.',
    ],
    'surface_color' => [
        'label' => 'Kaartvlak',
        'help' => 'Kaarten en panelen: één tint boven de achtergrond.',
    ],
    'text_color' => [
        'label' => 'Tekst',
        'help' => 'Lopende tekst en koppen. Zachtere varianten worden hier automatisch van afgeleid.',
    ],
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vormgeving &amp; Branding — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Vormgeving &amp; Branding</h1>
  <p class="admin-text-muted">Hier bepaal je hoe de website eruitziet: kleuren, lettertypes en de vorm van knoppen. Wie de site is — naam, logo, favicon, deel-afbeelding en bedrijfsgegevens — staat bij <a href="/admin/settings.php">Site-instellingen</a>. Wijzigingen zijn direct zichtbaar op alle pagina's.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Vormgeving opgeslagen.</p>
  <?php endif; ?>

  <?php if ($wasReset): ?>
    <p class="admin-alert admin-alert--success">De standaardvormgeving is hersteld. Je bedrijfsgegevens, logo, favicon en deel-afbeelding zijn niet aangeraakt.</p>
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
      <h2>Kleuren</h2>
      <p class="admin-text-muted">Vijf kleuren, meer niet. Randen, schaduwen, zachte tekst en hover-tinten worden hiervan afgeleid, zodat ze altijd bij elkaar passen. Meldingskleuren (fout, gelukt, waarschuwing) staan hier bewust los van en veranderen nooit mee.</p>

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
            <p class="admin-text-muted">Standaard: <?= $h($defaults[$key]) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="admin-card">
      <h2>Typografie</h2>
      <p class="admin-text-muted">Eén combinatie van een kop- en een tekstlettertype. Alleen het gekozen lettertype wordt gedownload; de andere combinaties kosten je bezoeker niets.</p>

      <div class="admin-form-row">
        <label for="theme-font-pairing">Lettertypecombinatie
          <select id="theme-font-pairing" name="font_pairing">
            <?php foreach (ThemeFonts::all() as $key => $pairing): ?>
              <option value="<?= $h($key) ?>" <?= ($values['font_pairing'] ?? '') === $key ? 'selected' : '' ?>><?= $h($pairing['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2>Stijl</h2>
      <p class="admin-text-muted">Geldt voor de gewone knoppen. Ronde icoonknoppen, labels en stappentellers houden hun eigen vorm, omdat die vorm iets betekent.</p>

      <div class="admin-form-row">
        <label for="theme-button-shape">Knopvorm
          <select id="theme-button-shape" name="button_shape">
            <?php foreach (ThemeSettings::buttonShapes() as $key => $shape): ?>
              <option value="<?= $h($key) ?>" <?= ($values['button_shape'] ?? '') === $key ? 'selected' : '' ?>><?= $h($shape['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
    </section>

    <section class="admin-card">
      <h2>Voorbeeld</h2>
      <p class="admin-text-muted">Een indruk van de gekozen kleuren en knopvorm. Dit is geen volledige weergave van de site — bekijk de website zelf om het echte resultaat te zien.</p>

      <div class="admin-theme-preview" data-theme-preview>
        <p class="admin-theme-preview__heading" data-theme-preview-heading>Een kop in het koplettertype</p>
        <p class="admin-theme-preview__body">Lopende tekst zoals een bezoeker die leest, met een <span data-theme-preview-link>link</span> erin.</p>
        <div class="admin-theme-preview__card" data-theme-preview-card>
          <span class="admin-theme-preview__muted">Een kaart met zachtere tekst</span>
        </div>
        <span class="admin-theme-preview__btn" data-theme-preview-btn>Knop</span>
      </div>
    </section>

    <div class="admin-theme-actions">
      <button type="submit">Opslaan</button>
    </div>
  </form>

  <section class="admin-card">
    <h2>Standaardvormgeving herstellen</h2>
    <p class="admin-text-muted">
      Zet de kleuren, het lettertype en de knopvorm terug naar de standaard van deze installatie.
      <strong>Alleen de vormgeving.</strong> Je bedrijfsnaam, logo, favicon, deel-afbeelding, adres,
      KVK-nummer, factuur- en e-mailteksten blijven ongewijzigd — die staan bij Site-instellingen en
      zijn hiervandaan niet te bereiken.
    </p>
    <?php if ($isDefault): ?>
      <p class="admin-text-muted">Je gebruikt op dit moment al de standaardvormgeving.</p>
    <?php else: ?>
      <form method="post" action="/api/admin/reset-theme-settings.php" onsubmit="return confirm('Vormgeving terugzetten naar de standaard? Je bedrijfsgegevens, logo en favicon blijven ongewijzigd.');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <button type="submit" class="admin-btn-secondary admin-theme-reset">Standaardvormgeving herstellen</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Wat er nu wordt meegestuurd</h2>
    <p class="admin-text-muted">De website laadt één vaste stylesheet met de standaardvormgeving erin. Alleen wat jij hebt gewijzigd wordt daarna nog meegestuurd — staat alles op standaard, dan wordt er niets extra's geladen.</p>
    <?php $declarations = ThemeCss::declarations(); ?>
    <?php if ($declarations === []): ?>
      <p class="admin-text-muted">Op dit moment: niets. De site draait volledig op de standaardvormgeving.</p>
    <?php else: ?>
      <pre class="admin-code-block"><?php foreach ($declarations as $property => $value): ?>
<?= $h($property) ?>: <?= $h($value) ?>;
<?php endforeach; ?></pre>
    <?php endif; ?>
    <p class="admin-text-muted">Huidige sitenaam: <?= $h(SiteSettings::get('site_name')) ?> — te wijzigen bij <a href="/admin/settings.php">Site-instellingen</a>.</p>
  </section>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
</body>
</html>
