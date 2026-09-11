<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_language_fields.php';

use App\Repository\ContactFormRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;

/**
 * Editor for one Offerte-/contactformulier block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/cta-band.php, ...).
 *
 * Since Core Forms this block CHOOSES a form rather than carrying one: the
 * fields, the recipient and the confirmation are managed once under Beheer →
 * Formulieren, and the link below goes there. What stays here is the block's
 * own heading, the "Direct contact" card beside it, and the optional file
 * attachment this site's quote form has always accepted.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactFormRepository();
$page = ($pageSlug === null || $pageSlug === '') ? null : (new PageRepository())->findByContentKey($pageSlug);

if ($page === null || $sectionKey === null || $sectionKey === ''
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$section = $repository->findBySlugAndKey($pageSlug, $sectionKey);
$currentFormId = $section['form_id'] === null ? null : (int) $section['form_id'];

try {
    $forms = (new FormRepository())->selectable($currentFormId);
} catch (\Throwable $e) {
    error_log('[admin/contact-form.php] ' . $e->getMessage());
    $forms = [];
}

$errors = $_SESSION['admin_contact_form_errors'] ?? [];
$old = $_SESSION['admin_contact_form_old'] ?? null;
unset($_SESSION['admin_contact_form_errors'], $_SESSION['admin_contact_form_old']);

$saved = isset($_GET['saved']);

$values = $old ?? [
    'title_nl' => (string) $section['title_nl'],
    'title_en' => (string) ($section['title_en'] ?? ''),
    'form_id' => $currentFormId === null ? '' : (string) $currentFormId,
    'allow_attachment' => (bool) $section['allow_attachment'],
    'is_active' => (bool) $section['is_active'],
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('contact_form')) ?> <?= admin_t('block_contactform.admin', ['v1' => $h((string) $page['title'])]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>"><?= admin_t('block_contactform.text', ['v1' => $h((string) $page['title'])]) ?></a></p>
  <h1><?= $h(SectionRegistry::label('contact_form')) ?></h1>
  <p class="admin-text-muted"><?= admin_t('block_contactform.sectie_kiest_hier_welk', ['v1' => $h((string) $page['title'])]) ?></p>
  <p class="admin-text-muted"><?= admin_t('block_contactform.kaart_direct_contact_ernaast') ?></p>

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

  <?php if ($forms === []): ?>
    <p class="admin-alert admin-alert--error"><?= admin_t('block_contactform.er_formulieren_kiezen_maak') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-contact-form.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <label><?= admin_te('block_contactform.welk_formulier') ?>
        <select name="form_id">
          <option value=""><?= admin_te('block_contactform.formulier_gekozen') ?></option>
          <?php foreach ($forms as $form): ?>
            <option value="<?= (int) $form['id'] ?>" <?= (string) $values['form_id'] === (string) $form['id'] ? 'selected' : '' ?>>
              <?= $h((string) $form['name']) ?><?= $form['is_active'] ? '' : ' (staat uit)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted"><?= admin_te('block_contactform.zonder_formulier_formulier_uit') ?></p>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('block_contactform.kop_boven_formulier') ?>*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= $h((string) ($values['title_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('block_contactform.kop_boven_formulier_2') ?>
          <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($values['title_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="allow_attachment" value="1" <?= ($values['allow_attachment'] ?? false) ? 'checked' : '' ?>>
        <?= admin_te('block_contactform.bezoekers_mogen_bestand_meesturen') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('block_contactform.e_n_bestand_maximaal') ?></p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        <?= admin_te('block_contactform.actief_uitgevinkt_sectie_getoond') ?>
      </label>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
