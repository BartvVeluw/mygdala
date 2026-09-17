<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FooterLocalization;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * The editor of one footer column: its title in one website language at a
 * time (admin/_localized_fields.php) and whether it is
 * on the website. The links in it are managed on the Footer screen itself
 * (admin/footer.php), where they are listed under the column.
 *
 * Same shape as admin/navigation-item.php: the visibility switch first, the
 * text of the language chosen in the CMS shell, one form so the save bar guards it and a
 * refused save starts out unsaved, and a delete that asks first in the CMS's
 * own dialog.
 */

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit(admin_t('screen.footer_kolom_gevonden'));
}

$repository = new FooterRepository();
$column = $repository->findColumnById($idParam);
if ($column === null) {
    http_response_code(404);
    exit(admin_t('screen.footer_kolom_gevonden'));
}

$errors = $_SESSION['admin_footer_column_errors'] ?? [];
$old = $_SESSION['admin_footer_column_old'] ?? null;
unset($_SESSION['admin_footer_column_errors'], $_SESSION['admin_footer_column_old']);

$saved = isset($_GET['saved']) && $old === null;

$editLanguage = admin_localized_language();
$title = is_array($old) && ($old['language_code'] ?? null) === $editLanguage
    ? (string) ($old['title'] ?? '')
    : FooterLocalization::rawColumnTitle($idParam, $editLanguage);
$isVisible = is_array($old) ? !empty($old['is_visible']) : (int) $column['is_visible'] === 1;
$linkCount = count($repository->findLinksForColumn($idParam));

$pageTitle = FooterLocalization::columnName($idParam);
if ($pageTitle === '') {
    $pageTitle = admin_t('footer.edit_column');
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — <?= admin_te('footer.footer') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/footer.php#footer-column-<?= (int) $column['id'] ?>"><?= admin_te('footer.back') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <p class="admin-text-muted"><?= admin_te('footer.column_links_count', ['count' => (string) $linkCount]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error" role="alert">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-footer-column.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= (int) $column['id'] ?>">

    <section class="admin-card">
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_visible" value="1"<?= $isVisible ? ' checked' : '' ?>>
          <?= admin_te('footer.column_visible') ?>
        </label>
        <?= admin_help(admin_t('footer.column_visible'), admin_t('help.footer.column_visible')) ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('footer.column_title_heading') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>
      <?= admin_localized_input($editLanguage) ?>
      <div class="admin-field">
        <?= admin_field_label('footer-column-title', admin_t('footer.column_title_label'), admin_t('help.footer.column_title'), admin_localized_required($editLanguage) !== '') ?>
        <input type="text" id="footer-column-title" name="title" maxlength="<?= FooterLocalization::TITLE_MAX_LENGTH ?>"<?= admin_localized_required($editLanguage) ?> value="<?= $h($title) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
      </div>
    </section>

    <section class="admin-card">
      <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
    </section>
  </form>

  <section class="admin-card">
    <h2><?= admin_te('common.delete') ?></h2>
    <p class="admin-text-muted"><?= admin_te('footer.delete_column_explained') ?></p>
    <form method="post" action="/api/admin/delete-footer-column.php" class="admin-inline-form"<?= admin_confirm_attributes(
        admin_t('footer.delete_column_title'),
        admin_t('footer.delete_column_message', ['item' => $pageTitle]),
        admin_t('common.delete')
    ) ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int) $column['id'] ?>">
      <button type="submit" class="admin-btn-danger"><?= admin_te('footer.delete_column') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<?php save_bar_script(); ?>
</body>
</html>
