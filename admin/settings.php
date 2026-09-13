<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Mail\EmailIdentity;
use App\Repository\FormRepository;
use App\Service\AdminAuth;
use App\Service\AdminTheme;
use App\Service\Csrf;
use App\Service\Forms\FormRecipient;
use App\Service\Media\MediaService;
use App\Service\SiteSettings;

require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_admin_tabs.php';
require_once __DIR__ . '/_translate.php';

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

/*
 * What an empty contact address means right now, said where the address is
 * entered rather than discovered later in a server log. Both read what is
 * STORED, because that is what the website runs on: without any sender
 * address no mail leaves at all (App\Mail\EmailIdentity), and a form with no
 * address of its own notifies nobody (App\Service\Forms\FormRecipient).
 */
$siteSenderMissing = !EmailIdentity::isConfigured();
$formsWithoutRecipient = [];
if (FormRecipient::siteFallback() === null) {
    try {
        foreach ((new FormRepository())->all() as $formRow) {
            if (FormRecipient::reliesOnSiteAddress($formRow)) {
                $formsWithoutRecipient[] = (string) $formRow['name'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[admin/settings.php] could not read the forms: ' . $e->getMessage());
    }
}

$csrfToken = Csrf::token();

/**
 * The WEBSITE's languages (MULTILINGUAL.md). Read through
 * App\Service\Language\ContentLanguages rather than out of $values, because
 * that class owns every rule about them — the primary is always enabled, an
 * unknown stored code falls back, and V1 allows one secondary at most. A
 * screen that read the raw setting would be a second, weaker copy of those
 * rules.
 */
$primaryLanguage = \App\Service\Language\ContentLanguages::primary();
$adminLocale = \App\Service\Language\AdminLocale::current();

$languageErrors = $_SESSION['admin_language_errors'] ?? [];
unset($_SESSION['admin_language_errors']);
$languageSaved = isset($_GET['saved']) && $savedSection === 'talen';

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

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
        <p class="admin-text-muted"><?= admin_t('settings.huidige_waarde_mediabibliotheek', ['v1' => $h($legacyPath)]) ?></code></p>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('settings.title') ?> <?= admin_te('settings.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('settings.title') ?></h1>
  <p class="admin-text-muted"><?= admin_te('settings.intro') ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('settings.saved') ?></p>
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
      'algemeen' => admin_t('settings.tab_general'),
      'talen' => admin_t('language.settings_title'),
      'seo' => admin_t('settings.tab_seo'),
      'facturen' => admin_t('settings.tab_invoices'),
      'email' => admin_t('settings.tab_emails'),
      'dashboard' => admin_t('settings.tab_dashboard'),
  ], [
      'label' => admin_t('settings.tabs_label'),
      'force' => match (true) {
          $adminThemeSaved || $adminThemeError !== null => 'dashboard',
          $languageSaved || $languageErrors !== [] => 'talen',
          default => null,
      },
  ]); ?>

  <?php admin_tab_panel('algemeen'); ?>
  <section class="admin-card">
    <h2><?= admin_te('settings.algemeen') ?></h2>
    <?= admin_info_panel(admin_t('help.settings.general')) ?>
    <?php /* No enctype: this form no longer carries a file. The branding
             images are media references now, and uploading happens inside the
             picker (api/admin/media-upload.php). */ ?>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <?php /* Grouped in the order somebody new fills this in: who the site
               is, how to reach it, the words visitors read, and a postal
               address. Only the site name is required. Every required and
               maxlength below is App\Service\SiteSettingsValidator's list,
               and Tests\Service\SiteSettingsValidatorTest keeps the two the
               same. */ ?>
      <h3><?= admin_te('settings.group_website') ?></h3>
      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('settings-site-name', admin_t('settings.site_name'), admin_t('help.settings.site_name'), true) ?>
          <input type="text" id="settings-site-name" name="site_name" maxlength="150" required value="<?= settingValue($values, 'site_name') ?>">
        </div>
      </div>

      <p class="admin-text-muted"><?= admin_t('settings.kies_hieronder_afbeelding_uit') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <?php brandingImageField($values, 'logo_path', 'logo', admin_t('settings.logo'), admin_t('settings.logo_help'), false); ?>
        <?php brandingImageField($values, 'logo_alt_path', 'logo_alt', admin_t('settings.logo_alt'), admin_t('settings.logo_alt_help'), true); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php brandingImageField($values, 'favicon_path', 'favicon', admin_t('settings.favicon'), admin_t('settings.favicon_help'), false); ?>
        <?php brandingImageField($values, 'og_image_path', 'og_image', admin_t('settings.og_image'), admin_t('settings.og_image_help'), true); ?>
      </div>

      <h3><?= admin_te('settings.group_contact') ?></h3>
      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('settings-email', admin_t('common.email_address'), admin_t('help.settings.email')) ?>
          <input type="email" id="settings-email" name="email" maxlength="150" value="<?= settingValue($values, 'email') ?>">
        </div>
        <div class="admin-field">
          <?= admin_field_label('settings-phone', admin_t('settings.telefoonnummer'), admin_t('help.settings.phone')) ?>
          <input type="tel" id="settings-phone" name="company_phone" maxlength="30" value="<?= settingValue($values, 'company_phone') ?>">
        </div>
      </div>

      <?php if ($siteSenderMissing || $formsWithoutRecipient !== []): ?>
        <div class="admin-alert admin-alert--warning" role="status">
          <?php if ($siteSenderMissing): ?>
            <p><?= admin_te('settings.warning_no_sender') ?></p>
          <?php endif; ?>
          <?php if ($formsWithoutRecipient !== []): ?>
            <p><?= admin_t('settings.warning_forms_without_recipient', ['forms' => $h(implode(', ', $formsWithoutRecipient))]) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h3><?= admin_te('settings.group_on_site') ?></h3>
      <?php /* Both language panes carry the same explanation: only the pane
               of the language being edited is on screen. Optional in both
               languages: the contact block and the footer leave an empty one
               out. */ ?>
      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <div class="admin-field">
          <?= admin_field_label('settings-city-nl', admin_t('settings.plaats_locatie'), admin_t('help.settings.city')) ?>
          <input type="text" id="settings-city-nl" name="city_nl" maxlength="150" value="<?= settingValue($values, 'city_nl') ?>">
        </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <div class="admin-field">
          <?= admin_field_label('settings-city-en', admin_t('settings.plaats_locatie_2'), admin_t('help.settings.city')) ?>
          <input type="text" id="settings-city-en" name="city_en" maxlength="150" value="<?= settingValue($values, 'city_en') ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <div class="admin-field">
          <?= admin_field_label('settings-footer-description-nl', admin_t('settings.footer_omschrijving'), admin_t('help.settings.footer_description')) ?>
          <textarea id="settings-footer-description-nl" name="footer_description_nl" maxlength="500" rows="3"><?= settingValue($values, 'footer_description_nl') ?></textarea>
        </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <div class="admin-field">
          <?= admin_field_label('settings-footer-description-en', admin_t('settings.footer_omschrijving_2'), admin_t('help.settings.footer_description')) ?>
          <textarea id="settings-footer-description-en" name="footer_description_en" maxlength="500" rows="3"<?= admin_lang_placeholder_attr('en') ?>><?= settingValue($values, 'footer_description_en') ?></textarea>
        </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h3><?= admin_te('settings.group_address') ?></h3>
      <?= admin_info_panel(admin_t('help.settings.address')) ?>
      <?php /* The structured postal address. These keys sat on the Facturen
               tab once, but they are the site's address rather than the
               invoice's: App\Mail\EmailIdentity already puts the city under
               every e-mail. Not city_nl/city_en above, which is what visitors
               are told in two languages; this is an address. */ ?>
      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('settings-company-street', admin_t('settings.straat')) ?>
          <input type="text" id="settings-company-street" name="company_street" maxlength="150" value="<?= settingValue($values, 'company_street') ?>">
        </div>
        <div class="admin-field">
          <?= admin_field_label('settings-company-house-number', admin_t('settings.huisnummer')) ?>
          <input type="text" id="settings-company-house-number" name="company_house_number" maxlength="20" value="<?= settingValue($values, 'company_house_number') ?>">
        </div>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('settings-company-postal-code', admin_t('settings.postcode')) ?>
          <input type="text" id="settings-company-postal-code" name="company_postal_code" maxlength="20" value="<?= settingValue($values, 'company_postal_code') ?>">
        </div>
        <div class="admin-field">
          <?= admin_field_label('settings-company-city', admin_t('settings.plaats'), admin_t('help.settings.company_city')) ?>
          <input type="text" id="settings-company-city" name="company_city" maxlength="150" value="<?= settingValue($values, 'company_city') ?>">
        </div>
        <div class="admin-field">
          <?= admin_field_label('settings-company-country', admin_t('settings.land'), admin_t('help.settings.country')) ?>
          <input type="text" id="settings-company-country" name="company_country" maxlength="2" value="<?= settingValue($values, 'company_country') ?>">
        </div>
      </div>

      <?php /* Out of the main flow on purpose: plenty of websites are not a
               registered business. Open when it holds a value, so nothing an
               owner filled in is hidden from them. */ ?>
      <details class="admin-collapse admin-settings-optional"<?= trim((string) ($values['kvk_number'] ?? '')) !== '' ? ' open' : '' ?>>
        <summary class="admin-collapse__summary">
          <span class="admin-collapse__caret" aria-hidden="true"></span>
          <h3 class="admin-collapse__title"><?= admin_te('settings.group_business') ?></h3>
        </summary>
        <div class="admin-collapse__body">
          <div class="admin-form-row admin-form-row--split">
            <div class="admin-field">
              <?= admin_field_label('settings-kvk-number', admin_t('settings.kvk_nummer'), admin_t('help.settings.kvk_number')) ?>
              <input type="text" id="settings-kvk-number" name="kvk_number" maxlength="20" value="<?= settingValue($values, 'kvk_number') ?>">
            </div>
          </div>
        </div>
      </details>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('talen'); ?>
  <?php /* WEBSITE languages, and only those. The two PERSONAL language
           preferences - which language the CMS interface runs in, and which
           language version of the content this administrator is editing -
           live on admin/account.php and in the CMS shell. Three states that
           must never be confused, so they are not on the same screen
           (MULTILINGUAL.md).

           There is no "enable English" control any more, and that is the
           correction: this product is bilingual, so a visitor can always ask
           for either language and an editor can always write either one. All
           that is left to configure is which of the two a visitor gets
           first. */ ?>
  <section class="admin-card">
    <h2><?= $h(\App\Service\Language\AdminTranslator::trans('language.settings_title')) ?></h2>
    <p class="admin-text-muted"><?= $h(\App\Service\Language\AdminTranslator::trans('language.settings_intro')) ?></p>

    <?php if ($languageSaved): ?>
      <p class="admin-alert admin-alert--success"><?= $h(\App\Service\Language\AdminTranslator::trans('common.saved')) ?></p>
    <?php endif; ?>

    <?php if ($languageErrors !== []): ?>
      <div class="admin-alert admin-alert--error">
        <ul>
          <?php foreach ($languageErrors as $languageError): ?>
            <li><?= $h((string) $languageError) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-language-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">

      <p class="admin-text-muted admin-lang-note"><?= $h(\App\Service\Language\AdminTranslator::trans('language.always_bilingual')) ?></p>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('field-primary-language', \App\Service\Language\AdminTranslator::trans('language.default_website'), admin_t('help.settings.primary_language')) ?>
          <select name="primary_content_language" id="field-primary-language" class="admin-select">
            <?php foreach (\App\Service\Language\LanguageRegistry::contentLanguages() as $languageCode => $languageDefinition): ?>
              <option value="<?= $h($languageCode) ?>"<?= $languageCode === $primaryLanguage ? ' selected' : '' ?>><?= $h($languageDefinition->labelIn($adminLocale)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="admin-text-muted"><?= $h(\App\Service\Language\AdminTranslator::trans('language.default_website_help')) ?></p>
      </div>

      <button type="submit"><?= $h(\App\Service\Language\AdminTranslator::trans('common.save')) ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= $h(\App\Service\Language\AdminTranslator::trans('language.cms')) ?></h2>
    <p class="admin-text-muted"><?= $h(\App\Service\Language\AdminTranslator::trans('account.interface_language_help')) ?></p>
    <p class="admin-text-muted"><?= $h(\App\Service\Language\AdminTranslator::trans('account.content_language_help')) ?></p>
    <p><a href="/admin/account.php" class="admin-btn-link"><?= $h(\App\Service\Language\AdminTranslator::trans('shell.my_account')) ?> &rarr;</a></p>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('seo'); ?>
  <section class="admin-card">
    <h2><?= admin_te('settings.seo') ?></h2>
    <p class="admin-text-muted"><?= admin_t('settings.standaarden_waar_elke_pagina') ?></p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('settings-seo-default-description', admin_t('settings.standaard_meta_description'), admin_t('help.settings.seo_description')) ?>
          <textarea id="settings-seo-default-description" name="seo_default_description" maxlength="<?= \App\Service\Seo::MAX_META_DESCRIPTION_LENGTH ?>" rows="3"><?= settingValue($values, 'seo_default_description') ?></textarea>
        </div>
        <p class="admin-text-muted"><?= admin_t('settings.gebruikt_pagina_s_zelf') ?></p>
      </div>

      <div class="admin-form-row">
        <?php /* The hidden field is what makes the checkbox honest: an
                 unticked checkbox sends nothing at all, and this endpoint
                 treats "absent" as "keep the stored value", so without it
                 the setting could be switched on but never off. PHP keeps
                 the LAST value for a repeated name, so a ticked box wins
                 over the hidden 0 and an unticked one leaves the 0.
                 The switch (ADMIN-UI.md) is still that one checkbox, so the
                 hidden 0 keeps doing exactly this. */ ?>
        <input type="hidden" name="seo_robots_index_default" value="0">
        <div class="admin-field admin-field--inline">
          <label class="admin-checkbox-label">
            <input type="checkbox" class="admin-switch" role="switch" name="seo_robots_index_default" value="1" <?= \App\Service\SeoDefaults::indexesByDefault() ? 'checked' : '' ?>>
            <?= admin_te('settings.zoekmachines_mogen_website_indexeren') ?>
          </label>
          <?= admin_help(admin_t('settings.zoekmachines_mogen_website_indexeren'), admin_t('help.settings.robots')) ?>
        </div>
        <p class="admin-text-muted"><?= admin_t('settings.uit_betekent_elke_publieke') ?></p>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('facturen'); ?>
  <section class="admin-card">
    <h2><?= admin_te('settings.bedrijfsgegevens_facturen') ?></h2>
    <p class="admin-text-muted"><?= admin_te('settings.gegevens_gebruikt_elke_nieuw') ?></p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('settings.website') ?>
          <input type="text" name="company_website" maxlength="150" value="<?= settingValue($values, 'company_website') ?>">
        </label>
        <label><?= admin_te('settings.btw_id_indien_toepassing') ?>
          <input type="text" name="company_vat_id" maxlength="30" value="<?= settingValue($values, 'company_vat_id') ?>">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('settings.factuurnummer_voorvoegsel') ?>
          <input type="text" name="invoice_number_prefix" maxlength="20" value="<?= settingValue($values, 'invoice_number_prefix') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.fiscale_juridische_toelichting_bijv') ?>
          <textarea name="invoice_tax_note" maxlength="500" rows="2"><?= settingValue($values, 'invoice_tax_note') ?></textarea>
        </label>
        <p class="admin-text-muted"><?= admin_te('settings.leeg_extra_tekst_factuur') ?></p>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.betaalopmerking_optioneel') ?>
          <textarea name="invoice_payment_note" maxlength="500" rows="2"><?= settingValue($values, 'invoice_payment_note') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.factuur_footer') ?>
          <textarea name="invoice_footer_text" maxlength="500" rows="2"><?= settingValue($values, 'invoice_footer_text') ?></textarea>
        </label>
      </div>

      <?php /* Its own heading and its own field: the order number is not part
               of the invoice number. Still inside this tab's one form, which
               is the rule every tab on this screen keeps. */ ?>
      <h3><?= admin_te('settings.bestelnummers') ?></h3>
      <p class="admin-text-muted"><?= admin_te('settings.bestelnummers_intro') ?></p>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('settings.bestelnummerprefix') ?>
          <input type="text" name="order_number_prefix" maxlength="10" pattern="[A-Za-z0-9]{1,10}" value="<?= settingValue($values, 'order_number_prefix') ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('settings.bestelnummerprefix_uitleg') ?></p>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('email'); ?>
  <section class="admin-card">
    <h2><?= admin_te('settings.e_mailtekst_bestelbevestiging') ?></h2>
    <p class="admin-text-muted"><?= admin_t('settings.tekst_gebruikt_bevestigingsmail_klant') ?></p>
    <form method="post" action="/api/admin/update-site-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <div class="admin-form-row">
        <label><?= admin_te('settings.onderwerp') ?>
          <input type="text" name="order_email_subject" maxlength="255" value="<?= settingValue($values, 'order_email_subject') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.kop_heading') ?>
          <input type="text" name="order_email_heading" maxlength="150" value="<?= settingValue($values, 'order_email_heading') ?>">
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.introductietekst') ?>
          <textarea name="order_email_intro" maxlength="1000" rows="3"><?= settingValue($values, 'order_email_intro') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('settings.tekst_v_r_productoverzicht') ?>
          <textarea name="order_email_before_items" maxlength="500" rows="2"><?= settingValue($values, 'order_email_before_items') ?></textarea>
        </label>
        <label><?= admin_te('settings.tekst_n_productoverzicht_optioneel') ?>
          <textarea name="order_email_after_items" maxlength="500" rows="2"><?= settingValue($values, 'order_email_after_items') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.afsluittekst') ?>
          <textarea name="order_email_closing" maxlength="500" rows="2"><?= settingValue($values, 'order_email_closing') ?></textarea>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('settings.ondertekening_optioneel') ?>
          <textarea name="order_email_signature" maxlength="500" rows="2"><?= settingValue($values, 'order_email_signature') ?></textarea>
        </label>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
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
    <h2><?= admin_te('settings.dashboard_uiterlijk') ?></h2>
    <p class="admin-text-muted"><?= admin_t('settings.hoe_cms_eruitziet_iedereen') ?></p>

    <?php if ($adminThemeSaved): ?>
      <p class="admin-alert admin-alert--success"><?= admin_t('settings.dashboard_uiterlijk_opgeslagen', ['v1' => htmlspecialchars(AdminTheme::label($currentAdminTheme), ENT_QUOTES, 'UTF-8')]) ?></p>
    <?php endif; ?>

    <?php if ($adminThemeError !== null): ?>
      <p class="admin-alert admin-alert--error"><?= htmlspecialchars((string) $adminThemeError, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <form method="post" action="/api/admin/update-admin-theme.php">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

      <fieldset class="admin-theme-choices">
        <legend class="admin-visually-hidden"><?= admin_te('settings.kies_uiterlijk_dashboard') ?></legend>
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
              <span class="admin-theme-choice__name"><?= htmlspecialchars(admin_registry_label('admintheme.' . $themeKey . '.label', (string) $theme['label']), ENT_QUOTES, 'UTF-8') ?></span>
              <?php /* Never colour alone: the card in use says so in words as
                       well as with its border and its checked radio. */ ?>
              <?php if ($isCurrentTheme): ?>
                <span class="admin-badge admin-badge--info">In gebruik</span>
              <?php endif; ?>
            </span>
            <span class="admin-theme-choice__desc"><?= htmlspecialchars(admin_registry_label('admintheme.' . $themeKey . '.description', (string) $theme['description']), ENT_QUOTES, 'UTF-8') ?></span>
          </label>
        <?php endforeach; ?>
      </fieldset>

      <button type="submit"><?= admin_te('settings.uiterlijk_opslaan') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tabs_end(); ?>
</main>
<?php media_picker_modal(); ?>
<?php admin_tabs_script(); ?>
<?php media_picker_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
