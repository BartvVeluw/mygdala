<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\AdminTheme;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\SiteSettings;

require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_admin_tabs.php';

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$errors = $_SESSION['admin_settings_errors'] ?? [];
$old = $_SESSION['admin_settings_old'] ?? null;
unset($_SESSION['admin_settings_errors'], $_SESSION['admin_settings_old']);

/**
 * This page carries several independent forms. They all come back with the
 * project's usual ?saved=1 success marker, so `section` says WHICH one was
 * confirmed: the "Dashboard uiterlijk" card at the bottom has its own form
 * and its own endpoint, and a save there must not print "Instellingen
 * opgeslagen" over the identity card, nor the other way around.
 */
$savedSection = (string) ($_GET['section'] ?? '');
$saved = isset($_GET['saved']) && $savedSection === '';
$adminThemeSaved = isset($_GET['saved']) && $savedSection === 'dashboard-uiterlijk';
$adminThemeError = $_SESSION['admin_theme_choice_error'] ?? null;
unset($_SESSION['admin_theme_choice_error']);
$currentAdminTheme = AdminTheme::current();

$values = $old ?? SiteSettings::all();

$csrfToken = Csrf::token();

/**
 * @param array<string, mixed>|null $old
 */
function settingValue(?array $values, string $key): string
{
    return htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * One branding image, chosen from the Media Library.
 *
 * The stored PATH was a text field once, then a file input of its own, and is
 * now a media reference: the same picker every content block uses
 * (admin/_media_picker.php). One upload universe instead of four, one place
 * to write the alt text, and a usage count that can tell an editor the logo
 * is in use before they delete it.
 *
 * A form that submits no media id at all leaves the current value exactly as
 * it is — which is what keeps the several independent <form> sections on this
 * page from clearing each other's values. Clearing is therefore an explicit
 * act: the picker's "Wissen" button empties the field, and only the optional
 * assets offer it.
 *
 * @param array<string, mixed>|null $values
 */
function brandingImageField(
    ?array $values,
    string $key,
    string $inputName,
    string $label,
    string $help,
    bool $removable
): void {
    $mediaKey = \App\Service\Branding::MEDIA_KEYS[$key] ?? '';
    $selected = MediaService::find((int) ($values[$mediaKey] ?? 0));
    $legacyPath = trim((string) ($values[$key] ?? ''));
    $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="admin-branding-field">
      <?php media_picker_field($inputName . '_media_id', $selected, $label, $help, $removable); ?>
      <?php if ($selected === null && $legacyPath !== ''): ?>
        <?php /* A path this install had before the Media Library and that no
                 media item was created for — an absolute URL, for instance.
                 Still rendered by the site; shown here so it is not a mystery. */ ?>
        <p class="admin-text-muted">Huidige waarde (nog niet in de mediabibliotheek): <code><?= $h($legacyPath) ?></code></p>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Site-instellingen — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Site-instellingen</h1>
  <p class="admin-text-muted">Deze gegevens worden overal op de website gebruikt (header, footer, contactpagina). Wijzigingen zijn direct zichtbaar op alle pagina's.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Instellingen opgeslagen.</p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php /* Five tabs over the five independent forms this screen already had
           (admin/_admin_tabs.php). Grouped by what an editor came to change,
           and along the seams that were there: every tab is one whole
           <form> to its own endpoint, so nothing moved between forms, no
           save now carries fields it did not carry before, and where a
           setting is stored did not change at all. */ ?>
  <?php admin_tabs_start('site-settings', [
      'algemeen' => 'Algemeen',
      'seo' => 'SEO',
      'facturen' => 'Facturen',
      'email' => 'E-mails',
      'dashboard' => 'Dashboard',
  ], [
      'label' => 'Groepen instellingen',
      'force' => ($adminThemeSaved || $adminThemeError !== null) ? 'dashboard' : null,
  ]); ?>

  <?php admin_tab_panel('algemeen'); ?>
  <section class="admin-card">
    <h2>Algemeen</h2>
    <p class="admin-text-muted">Wie de site is: de naam, het beeldmerk en de contactgegevens die in de header, de footer en op de contactpagina terechtkomen.</p>
    <?php /* No enctype: this form no longer carries a file. The branding
             images are media references now, and uploading happens inside the
             picker (api/admin/media-upload.php). */ ?>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Bedrijfsnaam*
          <input type="text" name="site_name" maxlength="150" required value="<?= settingValue($values, 'site_name') ?>">
        </label>
        <label>KVK-nummer
          <input type="text" name="kvk_number" maxlength="20" value="<?= settingValue($values, 'kvk_number') ?>">
        </label>
      </div>

      <p class="admin-text-muted">Kies hieronder een afbeelding uit de <a href="/admin/media.php">mediabibliotheek</a>, of upload een nieuwe in het venster dat opent. Laat een veld ongemoeid om te houden wat er nu staat. Toegestaan: JPG, PNG, WEBP of GIF, maximaal 25 MB.</p>

      <div class="admin-form-row admin-form-row--split">
        <?php brandingImageField($values, 'logo_path', 'logo', 'Logo', 'Wordt gebruikt in de header en, als er geen tweede logo is, in de footer.', false); ?>
        <?php brandingImageField($values, 'logo_alt_path', 'logo_alt', 'Tweede logo (optioneel)', 'Wordt in de footer gebruikt als je hem instelt. Zonder tweede logo gebruikt de footer het gewone logo.', true); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php brandingImageField($values, 'favicon_path', 'favicon', 'Favicon', 'Het kleine pictogram in het tabblad van de browser. Vierkant, bij voorkeur minstens 180 x 180 pixels.', false); ?>
        <?php brandingImageField($values, 'og_image_path', 'og_image', 'Standaard deel-afbeelding', 'De preview wanneer iemand een pagina deelt op social media. Liggend, bij voorkeur 1200 x 630 pixels.', true); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>E-mailadres*
          <input type="email" name="email" maxlength="150" required value="<?= settingValue($values, 'email') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Plaats/locatie (NL)*
          <input type="text" name="city_nl" maxlength="150" required value="<?= settingValue($values, 'city_nl') ?>">
        </label>
        <label>Plaats/locatie (EN)*
          <input type="text" name="city_en" maxlength="150" required value="<?= settingValue($values, 'city_en') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Footer-omschrijving (NL)*
          <textarea name="footer_description_nl" maxlength="500" required rows="3"><?= settingValue($values, 'footer_description_nl') ?></textarea>
        </label>
        <label>Footer-omschrijving (EN)*
          <textarea name="footer_description_en" maxlength="500" required rows="3"><?= settingValue($values, 'footer_description_en') ?></textarea>
        </label>
      </div>

      <button type="submit">Opslaan</button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('seo'); ?>
  <section class="admin-card">
    <h2>SEO</h2>
    <p class="admin-text-muted">De standaarden waar elke pagina op terugvalt. De titel-achtervoegsel is de <strong>Bedrijfsnaam</strong> hierboven en de standaard deel-afbeelding is de <strong>Standaard deel-afbeelding</strong> hierboven &mdash; die staan er maar &eacute;&eacute;n keer, zodat ze niet uit elkaar kunnen lopen.</p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label>Standaard meta description
          <textarea name="seo_default_description" maxlength="<?= \App\Service\Seo::MAX_META_DESCRIPTION_LENGTH ?>" rows="3"><?= settingValue($values, 'seo_default_description') ?></textarea>
        </label>
        <p class="admin-text-muted">Wordt gebruikt op pagina&rsquo;s die zelf geen meta description hebben. Laat leeg om er dan helemaal geen te tonen &mdash; dat is beter dan overal dezelfde zin. Richtlijn: 120 tot 160 tekens.</p>
      </div>

      <div class="admin-form-row">
        <?php /* The hidden field is what makes the checkbox honest: an
                 unticked checkbox sends nothing at all, and this endpoint
                 treats "absent" as "keep the stored value", so without it
                 the setting could be switched on but never off. PHP keeps
                 the LAST value for a repeated name, so a ticked box wins
                 over the hidden 0 and an unticked one leaves the 0. */ ?>
        <input type="hidden" name="seo_robots_index_default" value="0">
        <label class="admin-checkbox-label">
          <input type="checkbox" name="seo_robots_index_default" value="1" <?= \App\Service\SeoDefaults::indexesByDefault() ? 'checked' : '' ?>>
          Zoekmachines mogen deze website indexeren
        </label>
        <p class="admin-text-muted">Uit betekent dat <em>elke</em> publieke pagina <code>noindex</code> krijgt. Alleen uitzetten voor een site die nog niet gevonden mag worden &mdash; laat &rsquo;m aan zodra de site live is.</p>
      </div>

      <button type="submit">Opslaan</button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('facturen'); ?>
  <section class="admin-card">
    <h2>Bedrijfsgegevens voor facturen</h2>
    <p class="admin-text-muted">Deze gegevens worden gebruikt op elke nieuw gegenereerde factuur. Bedrijfsnaam, logo, KVK-nummer en e-mailadres hierboven worden hergebruikt. Een al aangemaakte factuur verandert nooit mee met latere wijzigingen hier.</p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row admin-form-row--split">
        <label>Straat
          <input type="text" name="company_street" maxlength="150" value="<?= settingValue($values, 'company_street') ?>">
        </label>
        <label>Huisnummer
          <input type="text" name="company_house_number" maxlength="20" value="<?= settingValue($values, 'company_house_number') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Postcode
          <input type="text" name="company_postal_code" maxlength="20" value="<?= settingValue($values, 'company_postal_code') ?>">
        </label>
        <label>Plaats
          <input type="text" name="company_city" maxlength="150" value="<?= settingValue($values, 'company_city') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Land
          <input type="text" name="company_country" maxlength="2" value="<?= settingValue($values, 'company_country') ?>">
        </label>
        <label>Telefoonnummer
          <input type="text" name="company_phone" maxlength="30" value="<?= settingValue($values, 'company_phone') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Website
          <input type="text" name="company_website" maxlength="150" value="<?= settingValue($values, 'company_website') ?>">
        </label>
        <label>BTW-id (indien van toepassing)
          <input type="text" name="company_vat_id" maxlength="30" value="<?= settingValue($values, 'company_vat_id') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Factuurnummer-voorvoegsel
          <input type="text" name="invoice_number_prefix" maxlength="20" value="<?= settingValue($values, 'invoice_number_prefix') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label>Fiscale/juridische toelichting (bijv. KOR-vermelding)
          <textarea name="invoice_tax_note" maxlength="500" rows="2"><?= settingValue($values, 'invoice_tax_note') ?></textarea>
        </label>
        <p class="admin-text-muted">Leeg = geen extra tekst op de factuur. Vul dit alleen in als je fiscale regime (bijv. KOR) bekend en definitief is.</p>
      </div>

      <div class="admin-form-row">
        <label>Betaalopmerking (optioneel)
          <textarea name="invoice_payment_note" maxlength="500" rows="2"><?= settingValue($values, 'invoice_payment_note') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label>Factuur-footer
          <textarea name="invoice_footer_text" maxlength="500" rows="2"><?= settingValue($values, 'invoice_footer_text') ?></textarea>
        </label>
      </div>

      <button type="submit">Opslaan</button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('email'); ?>
  <section class="admin-card">
    <h2>E-mailtekst bestelbevestiging</h2>
    <p class="admin-text-muted">Deze tekst wordt gebruikt in de bevestigingsmail die een klant na een geslaagde betaling ontvangt. Productoverzicht, aantallen, prijzen, verzendgegevens en de factuurbijlage staan hier los van en blijven altijd correct. Beschikbare plaatshouders: <code>{{customer_name}}</code>, <code>{{order_number}}</code>, <code>{{order_date}}</code>, <code>{{order_total}}</code>, <code>{{site_name}}</code>.</p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label>Onderwerp
          <input type="text" name="order_email_subject" maxlength="255" value="<?= settingValue($values, 'order_email_subject') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label>Kop (heading)
          <input type="text" name="order_email_heading" maxlength="150" value="<?= settingValue($values, 'order_email_heading') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label>Introductietekst
          <textarea name="order_email_intro" maxlength="1000" rows="3"><?= settingValue($values, 'order_email_intro') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Tekst vóór productoverzicht (optioneel)
          <textarea name="order_email_before_items" maxlength="500" rows="2"><?= settingValue($values, 'order_email_before_items') ?></textarea>
        </label>
        <label>Tekst ná productoverzicht (optioneel)
          <textarea name="order_email_after_items" maxlength="500" rows="2"><?= settingValue($values, 'order_email_after_items') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label>Afsluittekst
          <textarea name="order_email_closing" maxlength="500" rows="2"><?= settingValue($values, 'order_email_closing') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label>Ondertekening (optioneel)
          <textarea name="order_email_signature" maxlength="500" rows="2"><?= settingValue($values, 'order_email_signature') ?></textarea>
        </label>
      </div>

      <button type="submit">Opslaan</button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('dashboard'); ?>
  <?php /* How the CMS itself looks. Deliberately the last card and
           deliberately its own <form> to its own endpoint: everything above
           is about the WEBSITE, this is about the panel you are standing in,
           and neither save may touch the other's values. The four skins live
           in App\Service\AdminTheme; their colours live in admin.css. */ ?>
  <section class="admin-card" id="dashboard-uiterlijk">
    <h2>Dashboard uiterlijk</h2>
    <p class="admin-text-muted">Hoe dit CMS eruitziet voor iedereen die ermee werkt. Dezelfde schermen, dezelfde knoppen &mdash; alleen andere kleuren. Dit staat helemaal los van de <a href="/admin/theme.php">vormgeving van de website</a>: bezoekers zien er niets van.</p>

    <?php if ($adminThemeSaved): ?>
      <p class="admin-alert admin-alert--success">Dashboard uiterlijk opgeslagen: <?= htmlspecialchars(AdminTheme::label($currentAdminTheme), ENT_QUOTES, 'UTF-8') ?>.</p>
    <?php endif; ?>

    <?php if ($adminThemeError !== null): ?>
      <p class="admin-alert admin-alert--error"><?= htmlspecialchars((string) $adminThemeError, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-admin-theme.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <fieldset class="admin-theme-choices">
        <legend class="admin-visually-hidden">Kies een uiterlijk voor het dashboard</legend>
        <?php foreach (AdminTheme::all() as $themeKey => $theme): ?>
          <?php $isCurrentTheme = $themeKey === $currentAdminTheme; ?>
          <label class="admin-theme-choice<?= $isCurrentTheme ? ' is-current' : '' ?>">
            <?php /* The sketch is drawn from the theme's OWN tokens: the
                     data-admin-theme attribute that skins a whole page skins
                     a single element just as well, so no palette is repeated
                     here and a changed theme cannot leave a stale swatch. */ ?>
            <span class="admin-theme-sketch" data-admin-theme="<?= htmlspecialchars($themeKey, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
              <span class="admin-theme-sketch__side">
                <span class="admin-theme-sketch__brand"></span>
                <span class="admin-theme-sketch__nav is-active"></span>
                <span class="admin-theme-sketch__nav"></span>
                <span class="admin-theme-sketch__nav"></span>
              </span>
              <span class="admin-theme-sketch__main">
                <span class="admin-theme-sketch__title"></span>
                <span class="admin-theme-sketch__row">
                  <span class="admin-theme-sketch__card"></span>
                  <span class="admin-theme-sketch__card"></span>
                </span>
                <span class="admin-theme-sketch__btn"></span>
              </span>
            </span>
            <span class="admin-theme-choice__label">
              <input type="radio" name="admin_theme" value="<?= htmlspecialchars($themeKey, ENT_QUOTES, 'UTF-8') ?>"<?= $isCurrentTheme ? ' checked' : '' ?>>
              <span class="admin-theme-choice__name"><?= htmlspecialchars($theme['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php /* Never colour alone: the card in use says so in words as
                       well as with its border and its checked radio. */ ?>
              <?php if ($isCurrentTheme): ?>
                <span class="admin-badge admin-badge--info">In gebruik</span>
              <?php endif; ?>
            </span>
            <span class="admin-theme-choice__desc"><?= htmlspecialchars($theme['description'], ENT_QUOTES, 'UTF-8') ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <button type="submit">Uiterlijk opslaan</button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tabs_end(); ?>
</main>
<?php media_picker_modal(); ?>
<?php admin_tabs_script(); ?>
<?php media_picker_script(); ?>
</body>
</html>
