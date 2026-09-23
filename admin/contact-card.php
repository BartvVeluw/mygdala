<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_localized_fields.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use App\Repository\PageRepository;
use App\Repository\ContactCardRepository;

/**
 * Editor for one Contactkaart block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/cta-band.php, ...). A page may carry several of these; each is
 * edited here under its own key.
 *
 * ONE WEBSITE LANGUAGE AT A TIME (Multilingual 2.0, admin/_localized_fields.php):
 * heading, text and button label show the language chosen in the CMS shell,
 * as stored; the heading is required only in the default language, and the
 * save writes that language only. The button URL and "Actief" are the same in
 * every language. Input a refused save hands back comes back in the language
 * it was typed in, and the form then starts out unsaved in the save bar.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactCardRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$sectionId = (int) $section['id'];
$editLanguage = admin_localized_language();

$errors = $_SESSION['admin_contact_card_errors'] ?? [];
$old = $_SESSION['admin_contact_card_old'] ?? null;
unset($_SESSION['admin_contact_card_errors'], $_SESSION['admin_contact_card_old']);

$saved = isset($_GET['saved']);

$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: typed and handed back in this language, else stored in it. */
$word = static fn (string $field): string => $oldInThisLanguage
    ? (string) ($old[$field] ?? '')
    : BlockLocalization::raw('contact_cards', $sectionId, $field, $editLanguage);

$buttonUrl = is_array($old) ? (string) ($old['button_url'] ?? '') : (string) ($section['button_url'] ?? '');
$isActive = is_array($old) ? !empty($old['is_active']) : (bool) $section['is_active'];

$siteEmail = (string) SiteSettings::get('email');

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$required = admin_localized_required($editLanguage);
$placeholder = admin_localized_placeholder_attr($editLanguage);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('contact_card')) ?> <?= admin_t('block_contactcard.admin', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_contactcard.text', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('contact_card')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_contactcard.kaart_kop_korte_tekst', ['v1' => $h(\App\Service\PageLocalization::name((int) $page['id']))]) ?></p>

  <?php if ($saved): ?>
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

  <section class="admin-card">
    <form method="post" action="/api/admin/update-contact-card.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <?php admin_localized_bar($editLanguage); ?>
      <div class="admin-form-row">
        <label><?= admin_te('block_contactcard.kop') ?><?= $required !== '' ? '*' : '' ?>
          <input type="text" name="title" maxlength="255"<?= $required ?> value="<?= $h($word('title')) ?>"<?= $placeholder ?>>
        </label>
      </div>

      <div class="admin-form-row">
        <label><?= admin_te('block_contactcard.tekst') ?>
          <textarea name="body" maxlength="600" rows="4"<?= $placeholder ?>><?= $h($word('body')) ?></textarea>
        </label>
      </div>

      <h2 style="margin-top:2rem;"><?= admin_te('block_contactcard.knop') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('block_contactcard.label') ?>
          <input type="text" name="button_label" maxlength="150" value="<?= $h($word('button_label')) ?>"<?= $placeholder ?>>
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('common.url') ?>
          <input type="text" name="button_url" maxlength="255" value="<?= $h($buttonUrl) ?>" placeholder="Leeg = mailto:<?= $h($siteEmail) ?>">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('block_contactcard.laat_url_leeg_mailen', ['v1' => $h($siteEmail)]) ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
        <?= admin_te('block_contactcard.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
