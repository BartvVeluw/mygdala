<?php

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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Geen toegang — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1>Geen toegang</h1>

  <div class="admin-card">
    <p>Je account heeft geen rechten voor dit onderdeel van het CMS.</p>
    <p class="admin-text-muted">
      Ingelogd als <strong><?= htmlspecialchars($forbiddenUserName, ENT_QUOTES, 'UTF-8') ?></strong>.
      Vraag de beheerder om dit recht als je het nodig hebt.
    </p>
    <?php if ($forbiddenLandingUrl !== null): ?>
      <p><a href="<?= htmlspecialchars($forbiddenLandingUrl, ENT_QUOTES, 'UTF-8') ?>" class="admin-btn-link">Terug naar het CMS</a></p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
