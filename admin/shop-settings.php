<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_tabs.php';
require_once __DIR__ . '/_save_bar.php';

use App\Mail\EmailPlaceholders;
use App\Mail\OrderConfirmationBuilder;
use App\Module\ModuleGuard;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ShopOverview;
use App\Service\ShopSettings;
use App\Service\SiteSettings;

/**
 * Shop-instellingen: the company details and fixed texts on an invoice, the
 * order-number prefix, and the order confirmation e-mail.
 *
 * All of it used to be the Facturen and E-mails tabs of admin/settings.php.
 * It is the Shop's screen now because none of it means anything without a
 * shop. The values are the same site_settings keys they always were;
 * App\Service\ShopSettings owns the list, the tabs and the validation.
 *
 * THE GUARD. This screen asks settings.manage, the permission those tabs
 * asked, so nobody gained or lost access by the move. That permission is
 * Core's and stays holdable with the Shop switched off, so unlike the other
 * Shop screens this one carries a ModuleGuard as well.
 *
 * One tab, one <form>, one endpoint: the shape of admin/settings.php. The
 * page editor's save bar (admin/_save_bar.php) watches those forms, so text
 * that "Herstel standaardtekst" put back but nobody saved yet is shown as an
 * unsaved change instead of looking stored.
 *
 * WHAT AN INVOICE ALSO PRINTS — the address, the KVK number, the e-mail
 * address and the phone number — is shown here read-only and edited on
 * Instellingen, the one place those live: the site footer and the
 * e-mail footer line read them too.
 */

ModuleGuard::requireAdmin('shop');
AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$errors = $_SESSION['admin_shop_settings_errors'] ?? [];
$old = $_SESSION['admin_shop_settings_old'] ?? null;
$failedSection = $_SESSION['admin_shop_settings_section'] ?? null;
unset($_SESSION['admin_shop_settings_errors'], $_SESSION['admin_shop_settings_old'], $_SESSION['admin_shop_settings_section']);

$savedSection = isset($_GET['saved']) ? ShopSettings::section($_GET['section'] ?? null) : null;

$stored = SiteSettings::all();
$values = is_array($old) ? $old : $stored;

// The standard text "Herstel standaardtekst" puts back, from its one source.
$standardCopy = OrderConfirmationBuilder::defaultCopy();
$mailProblems = ShopSettings::confirmationMailProblems();

/*
 * The address as one line, from what is stored. The country alone is not an
 * address: it has a default, so counting it would claim every site has one.
 */
$addressParts = array_values(array_filter([
    trim($stored['company_street'] . ' ' . $stored['company_house_number']),
    trim($stored['company_postal_code'] . ' ' . $stored['company_city']),
], static fn (string $part): bool => $part !== ''));
if ($addressParts !== []) {
    $addressParts[] = $stored['company_country'];
}

$fromSiteSettings = [
    'shop_settings.summary_address' => implode(', ', $addressParts),
    'settings.kvk_nummer' => $stored['kvk_number'],
    'common.email_address' => $stored['email'],
    'settings.telefoonnummer' => $stored['company_phone'],
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('shop_settings.title_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/shop-settings.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop_settings.title') ?></h1>
  <?= admin_info_panel(admin_t('help.shop_settings.intro')) ?>

  <?php if ($savedSection !== null): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
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

  <?php /* The tab keys are ShopSettings::TABS, which is also the closed list
           of sections a save may return to; a failed save opens the tab its
           messages are about. */ ?>
  <?php admin_tabs_start('shop-settings', [
      'bedrijf' => admin_t('shop_settings.tab_company'),
      'facturen' => admin_t('shop_settings.tab_invoices'),
      'bestellingen' => admin_t('shop_settings.tab_orders'),
      'emails' => admin_t('shop_settings.tab_emails'),
      'overzicht' => admin_t('shop.overview.tab'),
  ], [
      'label' => admin_t('shop_settings.tabs_label'),
      'force' => $failedSection !== null ? ShopSettings::section($failedSection) : $savedSection,
  ]); ?>

  <?php admin_tab_panel('bedrijf'); ?>
  <section class="admin-card">
    <h2><?= admin_te('shop_settings.company_title') ?></h2>
    <?= admin_info_panel(admin_t('help.shop_settings.company')) ?>
    <form method="post" action="/api/admin/update-shop-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="bedrijf">

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-company-name', admin_t('shop_settings.company_name'), admin_t('help.shop_settings.company_name')) ?>
          <input type="text" id="shop-company-name" name="company_name" maxlength="150" value="<?= $v('company_name') ?>" placeholder="<?= $h($stored['site_name']) ?>">
        </div>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('shop-company-vat-id', admin_t('shop_settings.vat_id'), admin_t('help.shop_settings.vat_id')) ?>
          <input type="text" id="shop-company-vat-id" name="company_vat_id" maxlength="30" value="<?= $v('company_vat_id') ?>">
        </div>
        <div class="admin-field">
          <?= admin_field_label('shop-company-website', admin_t('shop_settings.website'), admin_t('help.shop_settings.website')) ?>
          <input type="text" id="shop-company-website" name="company_website" maxlength="150" value="<?= $v('company_website') ?>">
        </div>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('shop_settings.from_site_settings_title') ?></h2>
    <p class="admin-text-muted"><?= admin_t('shop_settings.from_site_settings_intro') ?></p>
    <dl class="admin-shop-summary">
      <?php foreach ($fromSiteSettings as $labelKey => $summaryValue): ?>
        <dt><?= admin_te($labelKey) ?></dt>
        <dd><?php if (trim($summaryValue) !== ''): ?><?= $h($summaryValue) ?><?php else: ?><span class="admin-text-muted"><?= admin_te('shop_settings.not_filled_in') ?></span><?php endif; ?></dd>
      <?php endforeach; ?>
    </dl>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('facturen'); ?>
  <section class="admin-card">
    <h2><?= admin_te('shop_settings.invoices_title') ?></h2>
    <?= admin_info_panel(admin_t('help.shop_settings.invoices')) ?>
    <form method="post" action="/api/admin/update-shop-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="facturen">

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('shop-invoice-prefix', admin_t('shop_settings.invoice_prefix'), admin_t('help.shop_settings.invoice_prefix')) ?>
          <input type="text" id="shop-invoice-prefix" name="invoice_number_prefix" maxlength="20" value="<?= $v('invoice_number_prefix') ?>">
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-invoice-tax-note', admin_t('shop_settings.invoice_tax_note'), admin_t('help.shop_settings.invoice_tax_note')) ?>
          <textarea id="shop-invoice-tax-note" name="invoice_tax_note" maxlength="500" rows="2"><?= $v('invoice_tax_note') ?></textarea>
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-invoice-payment-note', admin_t('shop_settings.invoice_payment_note'), admin_t('help.shop_settings.invoice_payment_note')) ?>
          <textarea id="shop-invoice-payment-note" name="invoice_payment_note" maxlength="500" rows="2"><?= $v('invoice_payment_note') ?></textarea>
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-invoice-footer-text', admin_t('shop_settings.invoice_footer_text'), admin_t('help.shop_settings.invoice_footer_text')) ?>
          <textarea id="shop-invoice-footer-text" name="invoice_footer_text" maxlength="500" rows="2"><?= $v('invoice_footer_text') ?></textarea>
        </div>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('bestellingen'); ?>
  <section class="admin-card">
    <h2><?= admin_te('shop_settings.orders_title') ?></h2>
    <form method="post" action="/api/admin/update-shop-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="bestellingen">

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('shop-order-prefix', admin_t('shop_settings.order_prefix'), admin_t('help.shop_settings.order_prefix')) ?>
          <input type="text" id="shop-order-prefix" name="order_number_prefix" maxlength="10" pattern="[A-Za-z0-9]{1,10}" value="<?= $v('order_number_prefix') ?>">
        </div>
      </div>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('emails'); ?>
  <section class="admin-card">
    <h2><?= admin_te('shop_settings.email_title') ?></h2>
    <?= admin_info_panel(admin_t('help.shop_settings.email')) ?>

    <?php if ($mailProblems !== []): ?>
      <div class="admin-alert admin-alert--warning" role="status">
        <?php foreach ($mailProblems as $mailProblem): ?>
          <p><?= admin_t($mailProblem) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php /* autocomplete="off": some browsers put typed text back into the
             fields on a reload. After "Herstel standaardtekst" that would
             show the standard text again although the stored text is still
             the old one; a reload has to show what is stored. */ ?>
    <form method="post" action="/api/admin/update-shop-settings.php" class="admin-product-form" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="emails">

      <?php /* The placeholders the e-mail really replaces, from the list that
               does the replacing (App\Mail\EmailPlaceholders::KNOWN): one
               explanation for all of them rather than the same list beside
               each of the seven fields. */ ?>
      <div class="admin-shop-placeholders">
        <div class="admin-field__label">
          <span><?= admin_te('shop_settings.placeholders') ?></span>
          <?= admin_help(admin_t('shop_settings.placeholders'), ShopSettings::placeholderHelp()) ?>
        </div>
        <p class="admin-shop-placeholders__list">
          <?php foreach (EmailPlaceholders::KNOWN as $placeholder): ?>
            <code><?= $h('{{' . $placeholder . '}}') ?></code>
          <?php endforeach; ?>
        </p>
      </div>

      <?php /* Every field carries its standard text in data-default-value,
               written from OrderConfirmationBuilder::defaultCopy(). The
               restore button only copies that into the fields; saving stays
               the owner's own click (admin/assets/shop-settings.js). */ ?>
      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-subject', admin_t('shop_settings.email_subject'), admin_t('help.shop_settings.email_subject')) ?>
          <input type="text" id="shop-order-email-subject" name="order_email_subject" maxlength="255" value="<?= $v('order_email_subject') ?>" data-default-value="<?= $h($standardCopy['order_email_subject']) ?>">
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-heading', admin_t('shop_settings.email_heading'), admin_t('help.shop_settings.email_heading')) ?>
          <input type="text" id="shop-order-email-heading" name="order_email_heading" maxlength="150" value="<?= $v('order_email_heading') ?>" data-default-value="<?= $h($standardCopy['order_email_heading']) ?>">
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-intro', admin_t('shop_settings.email_intro'), admin_t('help.shop_settings.email_intro')) ?>
          <textarea id="shop-order-email-intro" name="order_email_intro" maxlength="1000" rows="4" data-default-value="<?= $h($standardCopy['order_email_intro']) ?>"><?= $v('order_email_intro') ?></textarea>
        </div>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-before-items', admin_t('shop_settings.email_before_items'), admin_t('help.shop_settings.email_before_items')) ?>
          <textarea id="shop-order-email-before-items" name="order_email_before_items" maxlength="500" rows="2" data-default-value="<?= $h($standardCopy['order_email_before_items']) ?>"><?= $v('order_email_before_items') ?></textarea>
        </div>
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-after-items', admin_t('shop_settings.email_after_items'), admin_t('help.shop_settings.email_after_items')) ?>
          <textarea id="shop-order-email-after-items" name="order_email_after_items" maxlength="500" rows="2" data-default-value="<?= $h($standardCopy['order_email_after_items']) ?>"><?= $v('order_email_after_items') ?></textarea>
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-closing', admin_t('shop_settings.email_closing'), admin_t('help.shop_settings.email_closing')) ?>
          <textarea id="shop-order-email-closing" name="order_email_closing" maxlength="500" rows="2" data-default-value="<?= $h($standardCopy['order_email_closing']) ?>"><?= $v('order_email_closing') ?></textarea>
        </div>
      </div>

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-order-email-signature', admin_t('shop_settings.email_signature'), admin_t('help.shop_settings.email_signature')) ?>
          <textarea id="shop-order-email-signature" name="order_email_signature" maxlength="500" rows="2" data-default-value="<?= $h($standardCopy['order_email_signature']) ?>"><?= $v('order_email_signature') ?></textarea>
        </div>
      </div>

      <div class="admin-form-actions">
        <button type="submit"><?= admin_te('common.save') ?></button>
        <?php /* type="button": it never submits. Hidden until the script
                 runs, because without the script it could do nothing. */ ?>
        <button type="button" class="admin-btn-secondary" data-restore-defaults data-restore-defaults-confirm="<?= admin_te('shop_settings.restore_defaults_confirm') ?>" data-restore-defaults-done="<?= admin_te('shop_settings.restore_defaults_done') ?>" hidden><?= admin_te('shop_settings.restore_defaults') ?></button>
        <?= admin_help(admin_t('shop_settings.restore_defaults'), admin_t('help.shop_settings.restore_defaults')) ?>
      </div>
      <p class="admin-text-muted" data-restore-defaults-status role="status" aria-live="polite"></p>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tab_panel('overzicht'); ?>
  <?php
    // The storefront is a page the owner chooses, or none
    // (App\Service\ShopOverview). The automatic listing of an older
    // installation is offered only while it is the stored value.
    $overviewValue = (string) ($values[ShopOverview::SETTING_KEY] ?? '');
    $storedOverview = (string) ($stored[ShopOverview::SETTING_KEY] ?? '');
    $overviewChoices = ShopOverview::choices();
    \App\Service\PageLocalization::preload(array_map(static fn (array $p): int => (int) $p['id'], $overviewChoices));
    $chosenOverviewPage = null;
    foreach ($overviewChoices as $candidate) {
        if ((string) (int) $candidate['id'] === $storedOverview) {
            $chosenOverviewPage = $candidate;
        }
    }
  ?>
  <section class="admin-card">
    <h2><?= admin_te('shop.overview.heading') ?></h2>
    <?= admin_info_panel(admin_t('shop.overview.intro')) ?>
    <form method="post" action="/api/admin/update-shop-settings.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="overzicht">

      <div class="admin-form-row">
        <div class="admin-field">
          <?= admin_field_label('shop-overview', admin_t('shop.overview.label'), admin_t('shop.overview.help')) ?>
          <select class="admin-select" id="shop-overview" name="shop_overview">
            <option value=""><?= admin_te('shop.overview.none') ?></option>
            <?php if ($storedOverview === ShopOverview::BUILTIN_VALUE): ?>
              <option value="<?= $h(ShopOverview::BUILTIN_VALUE) ?>"<?= $overviewValue === ShopOverview::BUILTIN_VALUE ? ' selected' : '' ?>><?= admin_te('shop.overview.builtin') ?></option>
            <?php endif; ?>
            <?php foreach ($overviewChoices as $overviewPage): ?>
              <?php
                $overviewTitle = \App\Service\PageLocalization::name((int) $overviewPage['id']);
                $overviewLabel = \App\Service\PageContent::isPublished($overviewPage)
                    ? admin_t('shop.overview.page_option', ['title' => $overviewTitle])
                    : admin_t('shop.overview.page_option_draft', ['title' => $overviewTitle]);
              ?>
              <option value="<?= (int) $overviewPage['id'] ?>"<?= $overviewValue === (string) (int) $overviewPage['id'] ? ' selected' : '' ?>><?= $h($overviewLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if ($storedOverview === ShopOverview::BUILTIN_VALUE): ?>
        <p class="admin-alert admin-alert--info"><?= admin_te('shop.overview.builtin_note') ?></p>
      <?php elseif ($chosenOverviewPage !== null): ?>
        <?php if (!\App\Service\PageContent::isPublished($chosenOverviewPage)): ?>
          <p class="admin-alert admin-alert--info"><?= admin_te('shop.overview.draft_note') ?></p>
        <?php endif; ?>
        <?php if (!ShopOverview::hasProductGrid($chosenOverviewPage)): ?>
          <p class="admin-alert admin-alert--info"><?= admin_te('shop.overview.no_grid_note') ?></p>
        <?php endif; ?>
        <?php if (AdminAuth::can('pages.manage')): ?>
          <p><a class="admin-btn-secondary" href="/admin/page.php?id=<?= (int) $chosenOverviewPage['id'] ?>"><?= admin_te('shop.overview.edit_page') ?></a></p>
        <?php endif; ?>
      <?php endif; ?>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tabs_end(); ?>
</main>
<?php save_bar(); ?>
<?php admin_tabs_script(); ?>
<?php save_bar_script(); ?>
</body>
</html>
