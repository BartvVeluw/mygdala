<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Install\SetupState;
use App\Install\SetupWizard;
use App\Module\ModuleConfig;
use App\Module\ModuleRegistry;
use App\Service\AdminAuth;
use App\Service\AppUrl;
use App\Service\AssetVersion;
use App\Service\Branding;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\SiteSettings;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeSettings;

require_once __DIR__ . '/_media_picker.php';

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

/**
 * The Setup Wizard: the one screen a brand-new installation of this CMS
 * opens on, and the only one it can reach until it is finished.
 *
 * It is ONBOARDING, not a settings screen. Five steps, everything but the
 * site name optional, and each answer written through the screen that
 * permanently owns it — Site-instellingen, Vormgeving, Pagina's, Navigatie.
 * Nothing here is a second copy of those screens, and after completion this
 * page becomes a short list of links to them (SETUP.md).
 *
 * WHY IT IS ONE FORM. Every step posts together, once, at the end. That is
 * what lets App\Install\SetupWizard::complete() validate the whole
 * submission before it writes anything and mark setup complete only after
 * the last change succeeded — a wizard that saved step by step would leave a
 * site half-configured the moment a browser tab closed. Without JavaScript
 * the steps are simply all visible at once and the same single submit
 * finishes the job.
 *
 * WHO GETS HERE. App\Service\AdminAuth::requireLogin() sends every signed-in
 * administrator here while App\Install\SetupState says setup is unfinished,
 * and this file is on the short exemption list so that redirect cannot loop.
 * An installation that predates the install marker — every existing site —
 * is configured by definition and never sees it.
 */

$isComplete = SetupState::isComplete();
$completedAt = SetupState::completedAt();

$errors = $_SESSION['admin_setup_errors'] ?? [];
$old = $_SESSION['admin_setup_old'] ?? [];
unset($_SESSION['admin_setup_errors'], $_SESSION['admin_setup_old']);

$errors = is_array($errors) ? $errors : [];
$old = is_array($old) ? $old : [];

$csrfToken = Csrf::token();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** A previously submitted value, or the given fallback. */
$previous = static function (string $key, string $fallback = '') use ($old): string {
    $value = $old[$key] ?? null;

    return is_string($value) ? $value : $fallback;
};

$themeDefaults = ThemeSettings::defaults();
$themeValues = ThemeSettings::all();

/**
 * The origin this request happened to arrive on. Shown as a hint and stored
 * nowhere: a canonical URL that follows the request is not a canonical URL
 * (App\Service\AppUrl), so the owner confirms the address by typing it.
 */
$detectedOrigin = '';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
if ($requestHost !== '' && preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $requestHost) === 1) {
    $requestIsHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $detectedOrigin = ($requestIsHttps ? 'https' : 'http') . '://' . $requestHost;
}

$baseUrlIsPinned = AppUrl::isPinnedByEnvironment();

/**
 * The five colours in the order they build on each other, worded exactly as
 * admin/theme.php words them — this is the same setting, not a second one.
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
        'help' => 'De tekst bovenop een gevulde knop. Moet goed leesbaar zijn op de primaire kleur.',
    ],
    'background_color' => [
        'label' => 'Achtergrond',
        'help' => 'De ondergrond van elke pagina.',
    ],
    'surface_color' => [
        'label' => 'Kaartvlak',
        'help' => 'Kaarten en panelen: één tint boven de achtergrond.',
    ],
    'text_color' => [
        'label' => 'Tekst',
        'help' => 'Lopende tekst en koppen.',
    ],
];

/** @var array<int, array{key: string, title: string}> */
$steps = [
    ['key' => 'website', 'title' => 'Website'],
    ['key' => 'branding', 'title' => 'Merk'],
    ['key' => 'appearance', 'title' => 'Vormgeving'],
    ['key' => 'features', 'title' => 'Onderdelen'],
    ['key' => 'pages', 'title' => "Pagina's"],
];

$siteName = SiteSettings::get('site_name');

/**
 * What the language dropdown starts on: whatever a rejected submission chose,
 * otherwise the project default. Never a guess from the browser's
 * Accept-Language — a visitor's browser says nothing about which language the
 * owner intends to publish in.
 */
$setupPrimaryLanguage = \App\Service\Language\AdminLocale::normalise(
    $previous('primary_content_language') !== ''
        ? $previous('primary_content_language')
        : \App\Service\Language\ContentLanguages::primary()
);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $isComplete ? 'Installatie voltooid' : 'Installatie' ?> — Admin</title>
<link rel="stylesheet" href="<?= AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body class="admin-setup-page"<?= \App\Service\AdminTheme::bodyAttribute() ?>>

<header class="admin-setup__bar">
  <span class="admin-setup__brand"><?= $h($siteName) ?></span>
  <form method="post" action="/admin/logout.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <button type="submit" class="admin-btn-text">Uitloggen</button>
  </form>
</header>

<main class="admin-main admin-setup">

<?php if ($isComplete): ?>

  <h1>De installatie is al afgerond</h1>
  <p class="admin-text-muted">
    Deze site is ingesteld<?= $completedAt !== null ? ' op ' . $h($completedAt) : '' ?>.
    De installatiewizard draait daarom niet opnieuw: hij is bedoeld om te beginnen, niet om
    later nog iets te wijzigen. Alles wat hij heeft ingesteld beheer je vanaf nu op de
    schermen die het echt bezitten.
  </p>

  <section class="admin-card">
    <h2>Waar je nu wat aanpast</h2>
    <ul class="admin-setup__links">
      <li><a href="/admin/settings.php">Site-instellingen</a> — naam, e-mailadres, logo, favicon, deel-afbeelding en bedrijfsgegevens.</li>
      <li><a href="/admin/theme.php">Vormgeving</a> — kleuren, lettertypecombinatie en knopvorm.</li>
      <li><a href="/admin/pages.php">Pagina's</a> — pagina's maken, bewerken en publiceren.</li>
      <li><a href="/admin/navigation.php">Navigatie</a> en <a href="/admin/footer.php">Footer</a> — het menu en de voettekst.</li>
      <li><a href="/admin/media.php">Mediabibliotheek</a> — afbeeldingen uploaden en hergebruiken.</li>
    </ul>
    <p><a href="/admin/index.php">Terug naar het dashboard</a></p>
  </section>

<?php else: ?>

  <h1>Welkom. Laten we deze website instellen.</h1>
  <p class="admin-text-muted">
    Vijf korte stappen. Alleen de naam van de site is verplicht — al het andere mag leeg
    blijven en is later te wijzigen. Er wordt pas iets opgeslagen wanneer je onderaan op
    <em>Installatie afronden</em> klikt.
  </p>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <ol class="admin-setup__steps" data-setup-steps>
    <?php foreach ($steps as $index => $step): ?>
      <li class="admin-setup__steps-item<?= $index === 0 ? ' is-current' : '' ?>" data-setup-step-label="<?= $index + 1 ?>">
        <span class="admin-setup__steps-number"><?= $index + 1 ?></span>
        <span><?= $h($step['title']) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>

  <form method="post" action="/api/admin/complete-setup.php" class="admin-product-form" data-setup-form>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

    <!-- ------------------------------------------------ 1. Website -->
    <section class="admin-card admin-setup__panel" data-setup-panel="1">
      <h2>1. Website</h2>
      <p class="admin-text-muted">Wie is deze site? Vul in wat je nu weet.</p>

      <div class="admin-form-row">
        <label for="setup-site-name"><span>Naam van de site <span aria-hidden="true">*</span></span>
          <input
            type="text"
            id="setup-site-name"
            name="site_name"
            maxlength="120"
            required
            autocomplete="organization"
            value="<?= $h($previous('site_name')) ?>">
        </label>
        <p class="admin-text-muted">Staat in de browsertitel, in de koptekst als je nog geen logo hebt, en achter elke paginatitel in Google.</p>
      </div>

      <?php /* The WEBSITE's language, and one question only. A second
               language starts off; an owner who wants one turns it on later
               under Instellingen. That default is what gives a new site a
               single-language editing experience with no duplicate English
               fields, which is the whole point of Multilingual V1
               (MULTILINGUAL.md).

               Deliberately worded "Taal van de website" and not just "Taal":
               the language the CMS itself is shown in is a different setting,
               it belongs to a person rather than to the site, and it lives on
               admin/account.php. Confusing the two is exactly what this
               feature exists to stop. */ ?>
      <div class="admin-form-row">
        <label for="setup-primary-language">Taal van de website
          <select id="setup-primary-language" name="primary_content_language">
            <?php foreach (\App\Service\Language\LanguageRegistry::contentLanguages() as $languageCode => $languageDefinition): ?>
              <option value="<?= $h($languageCode) ?>"<?= $languageCode === $setupPrimaryLanguage ? ' selected' : '' ?>><?= $h($languageDefinition->nativeLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <p class="admin-text-muted">De taal waarin je de inhoud van deze website schrijft. Een tweede taal kun je later aanzetten bij Instellingen &rarr; Talen. De taal van het CMS zelf kies je per persoon bij Mijn account.</p>
      </div>

      <div class="admin-form-row">
        <label for="setup-base-url">Publiek webadres
          <input
            type="url"
            id="setup-base-url"
            name="<?= $h(AppUrl::SETTING_KEY) ?>"
            placeholder="https://www.voorbeeld.nl"
            <?= $baseUrlIsPinned ? 'disabled' : '' ?>
            value="<?= $h($baseUrlIsPinned ? AppUrl::base() : $previous(AppUrl::SETTING_KEY)) ?>">
        </label>
        <?php if ($baseUrlIsPinned): ?>
          <p class="admin-text-muted">
            Dit adres staat vast in <code><?= $h(AppUrl::environmentVariableName()) ?></code> in het
            <code>.env</code>-bestand op de server. Dat is bewust serverconfiguratie: het bepaalt de
            canonieke adressen die zoekmachines volgen, en die mogen niet meebewegen met wie er inlogt.
            Wijzigen kan alleen op de server.
          </p>
        <?php else: ?>
          <p class="admin-text-muted">
            Het adres waarop bezoekers de site straks bereiken, inclusief <code>https://</code>. Het wordt
            gebruikt voor canonieke links, deelvoorbeelden en de sitemap — niet om de site te bereiken.
            <?php if ($detectedOrigin !== ''): ?>
              Dit verzoek kwam binnen op <code><?= $h($detectedOrigin) ?></code>; neem dat alleen over
              als dat ook het publieke adres is.
            <?php endif; ?>
            Laat je het leeg, dan vul je het later in bij Site-instellingen.
          </p>
          <p class="admin-text-muted">
            Staat er later <code><?= $h(AppUrl::environmentVariableName()) ?></code> in het
            <code>.env</code>-bestand van de server, dan wint die waarde van deze.
          </p>
        <?php endif; ?>
      </div>

      <div class="admin-form-row">
        <label for="setup-email">Contact-e-mailadres
          <input
            type="email"
            id="setup-email"
            name="email"
            maxlength="190"
            autocomplete="email"
            value="<?= $h($previous('email')) ?>">
        </label>
        <p class="admin-text-muted">Waar bezoekers je bereiken. Wordt gebruikt in de footer en onder uitgaande e-mail. Optioneel.</p>
      </div>

      <div class="admin-form-row">
        <label for="setup-description">Korte omschrijving
          <textarea id="setup-description" name="footer_description_nl" rows="3" maxlength="300"><?= $h($previous('footer_description_nl')) ?></textarea>
        </label>
        <p class="admin-text-muted">Eén of twee zinnen over wat je doet, voor in de footer. Optioneel.</p>
      </div>

      <details class="admin-setup__optional">
        <summary>Bedrijfsgegevens (optioneel)</summary>
        <p class="admin-text-muted">Alleen nodig als je facturen of juridische vermeldingen wilt. Later aan te vullen bij Site-instellingen.</p>

        <div class="admin-form-row">
          <label for="setup-city">Plaats
            <input type="text" id="setup-city" name="city_nl" maxlength="120" value="<?= $h($previous('city_nl')) ?>">
          </label>
        </div>

        <div class="admin-form-row">
          <label for="setup-kvk">KVK-nummer
            <input type="text" id="setup-kvk" name="kvk_number" maxlength="40" value="<?= $h($previous('kvk_number')) ?>">
          </label>
        </div>
      </details>
    </section>

    <!-- ------------------------------------------------ 2. Merk -->
    <section class="admin-card admin-setup__panel" data-setup-panel="2" hidden>
      <h2>2. Merk</h2>
      <p class="admin-text-muted">
        Kies of upload je logo en pictogrammen. Alles hier is optioneel: zonder logo toont de
        site gewoon de naam als tekst, en zonder deel-afbeelding blijft die simpelweg weg.
        Wat je hier uploadt komt in de Mediabibliotheek te staan.
      </p>

      <?php
      $brandingFields = [
          'logo_path' => ['Logo', 'Linksboven in de koptekst. Een SVG of PNG met transparante achtergrond werkt het best.'],
          'logo_alt_path' => ['Tweede logo', 'Voor de footer, bijvoorbeeld een lichte variant. Leeg betekent: gebruik het gewone logo.'],
          'favicon_path' => ['Favicon', 'Het kleine pictogram in de browsertab.'],
          'og_image_path' => ['Deel-afbeelding', 'De standaardafbeelding wanneer iemand een pagina deelt op sociale media.'],
      ];
      foreach ($brandingFields as $pathKey => [$label, $help]):
          $mediaKey = Branding::MEDIA_KEYS[$pathKey];
          $selectedId = (int) $previous($mediaKey, '0');
          media_picker_field($mediaKey, MediaService::find($selectedId > 0 ? $selectedId : null), $label, $help);
      endforeach;
      ?>
    </section>

    <!-- ------------------------------------------------ 3. Vormgeving -->
    <section class="admin-card admin-setup__panel" data-setup-panel="3" hidden>
      <h2>3. Vormgeving</h2>
      <p class="admin-text-muted">
        Vijf kleuren, één lettertypecombinatie en de vorm van de knoppen. Randen, schaduwen en
        hover-tinten worden hiervan afgeleid, zodat ze altijd bij elkaar passen. Je verandert dit
        later net zo makkelijk bij Vormgeving.
      </p>

      <div class="admin-form-row">
        <label for="theme-font_pairing">Lettertypecombinatie
          <select id="theme-font_pairing" name="font_pairing">
            <?php $selectedPairing = $previous('font_pairing', (string) $themeValues['font_pairing']); ?>
            <?php foreach (ThemeFonts::all() as $key => $pairing): ?>
              <option value="<?= $h($key) ?>" <?= $selectedPairing === $key ? 'selected' : '' ?>><?= $h($pairing['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="admin-theme-colors">
        <?php foreach ($colorFields as $key => $field): ?>
          <?php $value = $previous($key, (string) ($themeValues[$key] ?? $themeDefaults[$key])); ?>
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
          </div>
        <?php endforeach; ?>
      </div>

      <div class="admin-form-row">
        <label for="theme-button_shape">Knopvorm
          <select id="theme-button_shape" name="button_shape">
            <?php $selectedShape = $previous('button_shape', (string) $themeValues['button_shape']); ?>
            <?php foreach (ThemeSettings::buttonShapes() as $key => $shape): ?>
              <option value="<?= $h($key) ?>" <?= $selectedShape === $key ? 'selected' : '' ?>><?= $h($shape['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>

      <div class="admin-theme-preview" data-theme-preview>
        <p class="admin-theme-preview__heading" data-theme-preview-heading>Een kop in het koplettertype</p>
        <p class="admin-theme-preview__body">Lopende tekst zoals een bezoeker die leest, met een <span data-theme-preview-link>link</span> erin.</p>
        <div class="admin-theme-preview__card" data-theme-preview-card>
          <span class="admin-theme-preview__muted">Een kaart met zachtere tekst</span>
        </div>
        <span class="admin-theme-preview__btn" data-theme-preview-btn>Knop</span>
      </div>
    </section>

    <!-- ------------------------------------------------ 4. Onderdelen -->
    <section class="admin-card admin-setup__panel" data-setup-panel="4" hidden>
      <h2>4. Onderdelen</h2>
      <p class="admin-text-muted">
        Deze site is in de kern een CMS. Zet alleen aan wat je gebruikt — uitzetten verwijdert niets
        en is later terug te draaien. Wat uit staat, draagt niets bij: geen menu-items, geen rechten,
        geen openbare adressen en geen extra bestanden voor de bezoeker.
      </p>

      <?php foreach (ModuleRegistry::all() as $moduleKey => $module): ?>
        <?php
        $pinnedValue = ModuleConfig::environmentValue($moduleKey);
        $isPinned = $pinnedValue !== null;
        // First visit: whatever the deployment currently wants, which is
        // "enabled" unless something already said otherwise. After a
        // rejected submission: exactly what was ticked, so nobody loses a
        // choice to a typo three steps earlier.
        $checked = $isPinned
            ? $pinnedValue
            : ($old !== []
                ? !empty(((array) ($old['modules'] ?? []))[$moduleKey])
                : ModuleConfig::wants($moduleKey));
        $dependencies = $module->dependencies();
        ?>
        <div class="admin-form-row admin-setup__module">
          <label class="admin-checkbox-label">
            <input
              type="checkbox"
              name="modules[<?= $h($moduleKey) ?>]"
              value="1"
              <?= $checked ? 'checked' : '' ?>
              <?= $isPinned ? 'disabled' : '' ?>
              data-setup-module="<?= $h($moduleKey) ?>"
              <?= $dependencies !== [] ? 'data-setup-module-requires="' . $h(implode(' ', $dependencies)) . '"' : '' ?>>
            <span><?= $h($module->label()) ?></span>
          </label>
          <?php if ($module->description() !== ''): ?>
            <p class="admin-text-muted"><?= $h($module->description()) ?></p>
          <?php endif; ?>
          <?php if ($dependencies !== []): ?>
            <p class="admin-text-muted">
              Werkt alleen samen met
              <?= $h(implode(', ', array_map(static fn (string $key): string => ModuleRegistry::label($key), $dependencies))) ?>.
            </p>
          <?php endif; ?>
          <?php if ($isPinned): ?>
            <p class="admin-text-muted">
              Vastgezet in <code><?= $h(ModuleConfig::variableName($moduleKey)) ?></code> in het
              <code>.env</code>-bestand op de server, en daarom hier niet te wijzigen.
            </p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

    <!-- ------------------------------------------------ 5. Pagina's -->
    <section class="admin-card admin-setup__panel" data-setup-panel="5" hidden>
      <h2>5. Startpagina's</h2>
      <p class="admin-text-muted">
        De homepage bestaat al. Wil je alvast een paar veelgebruikte pagina's erbij? Elke pagina
        krijgt de blokken die zo'n pagina meestal heeft, komt als <strong>concept</strong> in je
        pagina-overzicht te staan en krijgt een menu-item dat pas zichtbaar wordt wanneer je hem
        publiceert. Kies gerust niets — je maakt ze later net zo eenvoudig.
      </p>

      <?php foreach (SetupWizard::STARTER_PAGES as $key => $page): ?>
        <?php $plannedSlug = SetupWizard::plannedSlug($key); ?>
        <div class="admin-form-row">
          <label class="admin-checkbox-label">
            <input
              type="checkbox"
              name="pages[<?= $h($key) ?>]"
              value="1"
              <?= $plannedSlug === null ? 'disabled' : '' ?>
              <?= !empty(((array) ($old['pages'] ?? []))[$key]) ? 'checked' : '' ?>>
            <span>
              <?= $h($page['label']) ?>
              <span class="admin-text-muted"><?= $plannedSlug === null ? '— bestaat al' : '/' . $h($plannedSlug) ?></span>
            </span>
          </label>
          <p class="admin-text-muted"><?= $h($page['description']) ?></p>
        </div>
      <?php endforeach; ?>
    </section>

    <div class="admin-setup__actions">
      <button type="button" class="admin-btn-secondary" data-setup-prev hidden>Vorige</button>
      <button type="button" data-setup-next hidden>Volgende</button>
      <button type="submit" data-setup-finish>Installatie afronden</button>
    </div>
  </form>

<?php endif; ?>

</main>

<?php if (!$isComplete): ?>
  <?php media_picker_modal(); ?>
  <script src="<?= AssetVersion::url('/admin/assets/media-picker.js') ?>" defer></script>
  <script src="<?= AssetVersion::url('/admin/assets/theme-admin.js') ?>" defer></script>
  <script src="<?= AssetVersion::url('/admin/assets/setup-wizard.js') ?>" defer></script>
<?php endif; ?>

</body>
</html>
