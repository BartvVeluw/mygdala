<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
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
    exit('Onbekende sectie.');
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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('contact_form')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('contact_form')) ?></h1>
  <p class="admin-text-muted">Sectie op <strong><?= $h((string) $page['title']) ?></strong>. Je kiest hier welk formulier hier staat; de velden, het e-mailadres en het bedankbericht beheer je bij <a href="/admin/forms.php">Formulieren</a>.</p>
  <p class="admin-text-muted">De kaart "Direct contact" ernaast toont het e-mailadres en de werkplaats-plaats uit <a href="/admin/settings.php">Site-instellingen</a>.</p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success">Opgeslagen.</p>
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
    <p class="admin-alert admin-alert--error">Er zijn nog geen formulieren om te kiezen. Maak er eerst een aan bij <a href="/admin/forms.php">Formulieren</a>.</p>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-contact-form.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">

      <label>Welk formulier?
        <select name="form_id">
          <option value="">— Nog geen formulier gekozen —</option>
          <?php foreach ($forms as $form): ?>
            <option value="<?= (int) $form['id'] ?>" <?= (string) $values['form_id'] === (string) $form['id'] ? 'selected' : '' ?>>
              <?= $h((string) $form['name']) ?><?= $form['is_active'] ? '' : ' (staat uit)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="admin-text-muted">Zonder formulier — of met een formulier dat uit staat of nog geen velden heeft — blijven alleen de kop en de kaart "Direct contact" staan.</p>

      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Kop boven het formulier*
          <input type="text" name="title_nl" maxlength="255" <?= admin_lang_required('nl') ?> value="<?= $h((string) ($values['title_nl'] ?? '')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Kop boven het formulier
          <input type="text" name="title_en" maxlength="255" value="<?= $h((string) ($values['title_en'] ?? '')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="allow_attachment" value="1" <?= ($values['allow_attachment'] ?? false) ? 'checked' : '' ?>>
        Bezoekers mogen een bestand meesturen (foto, logo of ontwerp)
      </label>
      <p class="admin-text-muted">Eén bestand van maximaal 8 MB: JPG, PNG, WEBP, GIF of PDF. Het wordt buiten de website opgeslagen, met de melding meegestuurd, en is alleen via het CMS te downloaden. Dit is de enige plek waar een bezoeker een bestand kan uploaden; gewone formulieren hebben geen uploadveld.</p>

      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_active" value="1" <?= ($values['is_active'] ?? true) ? 'checked' : '' ?>>
        Actief (uitgevinkt = deze sectie wordt niet getoond op de pagina)
      </label>

      <button type="submit">Opslaan</button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
