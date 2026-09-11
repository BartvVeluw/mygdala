<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Repository\FormRepository;
use App\Repository\FormSubmissionRepository;
use App\Service\AdminAuth;
use App\Service\AdminPermissions;
use App\Service\Csrf;
use App\Service\Forms\FormUsage;

/**
 * Beheer → Formulieren: every form on this site, what it asks and where it
 * is used.
 *
 * Counting is done in TWO queries, not two per row: the field lists and the
 * submission totals are fetched for every form at once
 * (FormRepository::fieldsForMany(), FormSubmissionRepository::countsForForms()).
 * Where a form is placed is asked per row, because that answer is the
 * expensive one and the list is short.
 *
 * Submission COUNTS are shown to anybody who may manage forms; the
 * submissions themselves are a different screen behind a different
 * permission (FORMS.md, "Rechten"). Knowing that fourteen people wrote in is
 * not the same as reading what they said.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

try {
    $repository = new FormRepository();
    $forms = $repository->all();
    $ids = array_map(static fn (array $form): int => (int) $form['id'], $forms);
    $fieldsByForm = $repository->fieldsForMany($ids);
    $submissionCounts = (new FormSubmissionRepository())->countsForForms($ids);
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/forms.php] ' . $e->getMessage());
    $forms = [];
    $fieldsByForm = [];
    $submissionCounts = [];
    $loadFailed = true;
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['admin_forms_flash'] ?? null;
$errors = $_SESSION['admin_forms_errors'] ?? [];
unset($_SESSION['admin_forms_flash'], $_SESSION['admin_forms_errors']);

$canSeeSubmissions = AdminAuth::can(AdminPermissions::FORMS_SUBMISSIONS);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('forms.formulieren_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <div class="admin-main__heading">
    <h1><?= admin_t('forms.formulieren') ?></h1>
  </div>
  <p class="admin-text-muted"><?= admin_t('forms.formulier_maak_hier_n') ?></p>

  <?php if ($flash !== null): ?>
    <p class="admin-alert admin-alert--success"><?= $h((string) $flash) ?></p>
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

  <?php if ($loadFailed): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('forms.formulieren_konden_geladen') ?></p>
  <?php endif; ?>

  <?php if (!$loadFailed && $forms === []): ?>
    <p><?= admin_te('forms.er_formulieren_maak_er') ?></p>
  <?php elseif ($forms !== []): ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('common.name') ?></th>
          <th><?= admin_te('forms.velden') ?></th>
          <th><?= admin_te('forms.bewaren') ?></th>
          <th><?= admin_te('forms.inzendingen') ?></th>
          <th><?= admin_te('forms.gebruikt') ?></th>
          <th><?= admin_te('common.status') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($forms as $form): ?>
          <?php
            $formId = (int) $form['id'];
            $fieldCount = count($fieldsByForm[$formId] ?? []);
            $stores = (bool) $form['store_submissions'];
            $submissions = $submissionCounts[$formId] ?? 0;
            $placements = FormUsage::placements($formId);
            $isActive = (bool) $form['is_active'];
            $blockers = FormUsage::deletionBlockers($formId);
          ?>
          <tr>
            <td><a href="/admin/form.php?id=<?= $formId ?>"><?= $h((string) $form['name']) ?></a></td>
            <td><?= $fieldCount ?></td>
            <td><?= $stores ? 'Ja' : 'Nee' ?></td>
            <td>
              <?php if (!$stores): ?>
                <span class="admin-text-muted">—</span>
              <?php elseif ($canSeeSubmissions && $submissions > 0): ?>
                <a href="/admin/form-submissions.php?form=<?= $formId ?>"><?= $submissions ?></a>
              <?php else: ?>
                <?= $submissions ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($placements === []): ?>
                <span class="admin-text-muted">Nergens</span>
              <?php else: ?>
                <?php foreach ($placements as $index => $placement): ?><?= $index > 0 ? ', ' : '' ?><?php if ($placement['edit_url'] !== ''): ?><a href="<?= $h($placement['edit_url']) ?>"><?= $h($placement['page_title']) ?></a><?php else: ?><?= $h($placement['page_title']) ?><?php endif; ?><?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td><span class="admin-badge admin-badge--<?= $isActive ? 'info' : 'muted' ?>"><?= $isActive ? admin_t('common.active') : 'Uit' ?></span></td>
            <td>
              <?php if ($blockers === []): ?>
                <form method="post" action="/api/admin/delete-form.php" class="admin-inline-form" onsubmit="return confirm('Dit formulier definitief verwijderen?');">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $formId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                </form>
              <?php else: ?>
                <span class="admin-text-muted" title="<?= $h(implode(' ', $blockers)) ?>">In gebruik</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('forms.nieuw_formulier') ?></h2>
    <p class="admin-text-muted"><?= admin_te('forms.geeft_formulier_eerst_naam') ?></p>
    <form method="post" action="/api/admin/create-form.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <label><?= admin_te('forms.naam_formulier') ?>*
        <input type="text" name="name" maxlength="150" required placeholder="Bijvoorbeeld: Contactformulier">
      </label>
      <button type="submit"><?= admin_te('forms.formulier_aanmaken') ?></button>
    </form>
  </section>
</main>
</body>
</html>
