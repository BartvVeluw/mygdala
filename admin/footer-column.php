<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\FooterRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$repository = new FooterRepository();
$column = $repository->findColumnById($idParam);
if ($column === null) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$errors = $_SESSION['admin_footer_column_errors'] ?? [];
$old = $_SESSION['admin_footer_column_old'] ?? null;
unset($_SESSION['admin_footer_column_errors'], $_SESSION['admin_footer_column_old']);

$titleNl = $old['title_nl'] ?? (string) $column['title_nl'];
$titleEn = $old['title_en'] ?? (string) $column['title_en'];
$isVisible = $old !== null ? !empty($old['is_visible']) : (int) $column['is_visible'] === 1;

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $column['title_nl']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/footer.php">&larr; Terug naar footer</a></p>
  <h1><?= $h((string) $column['title_nl']) ?></h1>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/api/admin/update-footer-column.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= (int) $column['id'] ?>">
    <section class="admin-card">
      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label>Titel*
          <input type="text" name="title_nl" maxlength="100" <?= admin_lang_required('nl') ?> value="<?= $h($titleNl) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label>Titel*
          <input type="text" name="title_en" maxlength="100" required value="<?= $h($titleEn) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
        Zichtbaar in de footer
      </label>
    </section>
    <section class="admin-card">
      <button type="submit">Opslaan</button>
    </section>
  </form>

  <section class="admin-card">
    <h2>Verwijderen</h2>
    <p class="admin-text-muted">Verwijdert deze kolom en al zijn links definitief. Dit kan niet ongedaan worden gemaakt.</p>
    <form method="post" action="/api/admin/delete-footer-column.php" onsubmit="return confirm('Deze kolom en al zijn links definitief verwijderen?');">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="id" value="<?= (int) $column['id'] ?>">
      <button type="submit" class="admin-btn-text admin-btn-text--danger">Kolom verwijderen</button>
    </form>
  </section>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
