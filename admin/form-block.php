<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FormBlockRepository;
use App\Repository\FormRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;

/**
 * Editor for one "Formulier" block
 * (?section=<page content_key>:<section_key>) — the same `<page>:<key>`
 * addressing and the same "valid only when the page AND its content row
 * really exist" gate as every other repeater editor (admin/rich-text.php,
 * admin/cta-band.php, ...).
 *
 * It edits WHERE a form appears, never WHAT it asks. The fields, the
 * recipient and the confirmation belong to the form itself, under Beheer →
 * Formulieren, and the link at the top of this screen is how an editor gets
 * there. That split is the point of Core Forms: the same form on three
 * pages is one definition, not three copies (FORMS.md).
 *
 * The dropdown lists the ACTIVE forms plus whichever one this block already
 * points at, so a form that was switched off does not silently vanish from
 * the block that uses it (FormRepository::selectable()).
 *
 * A page editor may choose a form here — that is placing content — but does
 * not thereby get to change it, or to read what people sent: those are
 * `forms.manage` and `forms.submissions`, and this screen only needs
 * `pages.manage`.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$sectionParam = (string) ($_GET['section'] ?? '');

[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new FormBlockRepository();
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
    error_log('[admin/form-block.php] ' . $e->getMessage());
    $forms = [];
}

$errors = $_SESSION['admin_form_block_errors'] ?? [];
$old = $_SESSION['admin_form_block_old'] ?? null;
unset($_SESSION['admin_form_block_errors'], $_SESSION['admin_form_block_old']);

$saved = isset($_GET['saved']);

$values = $old ?? [
    'form_id' => $currentFormId === null ? '' : (string) $currentFormId,
    'title_nl' => (string) ($section['title_nl'] ?? ''),
    'title_en' => (string) ($section['title_en'] ?? ''),
    'intro_nl' => (string) ($section['intro_nl'] ?? ''),
    'intro_en' => (string) ($section['intro_en'] ?? ''),
    'is_active' => (bool) $section['is_active'],
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$v = static fn (array $values, string $key): string => htmlspecialchars((string) ($values[$key] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label('form')) ?> — <?= $h((string) $page['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p class="admin-text-muted"><a href="/admin/page.php?id=<?= (int) $page['id'] ?>">&larr; <?= $h((string) $page['title']) ?></a></p>
  <h1><?= $h(SectionRegistry::label('form')) ?></h1>
  <p class="admin-text-muted">Sectie op <strong><?= $h((string) $page['title']) ?></strong>. Je kiest hier welk formulier op deze plek staat; de velden, het e-mailadres en het bedankbericht beheer je bij <a href="/admin/forms.php">Formulieren</a>.</p>

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
    <form method="post" action="/api/admin/update-form-block.php" class="admin-product-form">
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
      <p class="admin-text-muted">Zolang er geen formulier is gekozen — of het gekozen formulier uit staat of nog geen velden heeft — laat dit blok op de pagina niets zien.</p>

      <div class="admin-form-row admin-form-row--split">
        <label>Kop boven het formulier (NL)
          <input type="text" name="title_nl" maxlength="255" value="<?= $v($values, 'title_nl') ?>">
        </label>
        <label>Kop boven het formulier (EN)
          <input type="text" name="title_en" maxlength="255" value="<?= $v($values, 'title_en') ?>" placeholder="Leeg = zelfde als NL">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Inleiding (NL)
          <textarea name="intro_nl" maxlength="1000" rows="3"><?= $v($values, 'intro_nl') ?></textarea>
        </label>
        <label>Inleiding (EN)
          <textarea name="intro_en" maxlength="1000" rows="3" placeholder="Leeg = zelfde als NL"><?= $v($values, 'intro_en') ?></textarea>
        </label>
      </div>

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
</body>
</html>
