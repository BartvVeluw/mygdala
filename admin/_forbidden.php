<?php


require_once __DIR__ . '/_translate.php';
/**
 * The CMS's "no access" page, rendered by
 * App\Service\AdminAuth::requirePermission() / requireSuperAdmin() after a
 * 403 status has already been sent. One shared view so every refused page
 * and every refused deep link looks the same, inside the normal admin
 * layout, instead of a bare error string.
 *
 * It is reached by a logged-in user who simply may not open this section, so
 * it shows the sidebar (which only lists what they *may* open) and links to
 * the first section their permissions do allow.
 */

use App\Service\AdminAuth;
use App\Service\AdminNavigation;

$forbiddenLandingUrl = AdminNavigation::firstAccessibleUrl();
$forbiddenUserName = AdminAuth::userName();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('forbidden.toegang_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('forbidden.toegang') ?></h1>

  <div class="admin-card">
    <p><?= admin_te('forbidden.account_heeft_rechten_onderdeel') ?></p>
    <p class="admin-text-muted">
      <?= admin_t('forbidden.ingelogd_vraag_beheerder_recht', ['v1' => htmlspecialchars($forbiddenUserName, ENT_QUOTES, 'UTF-8')]) ?>
    </p>
    <?php if ($forbiddenLandingUrl !== null): ?>
      <p><a href="<?= htmlspecialchars($forbiddenLandingUrl, ENT_QUOTES, 'UTF-8') ?>" class="admin-btn-link"><?= admin_te('forbidden.terug_cms') ?></a></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
