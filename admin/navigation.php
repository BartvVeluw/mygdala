<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\NavigationRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new NavigationRepository();
$allItems = $repository->findAllForAdmin();

$byParent = [];
foreach ($allItems as $item) {
    $parentId = $item['parent_id'] === null ? 0 : (int) $item['parent_id'];
    $byParent[$parentId][] = $item;
}
$topLevel = $byParent[0] ?? [];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$deleted = isset($_GET['deleted']);
$navError = $_SESSION['admin_nav_error'] ?? null;
unset($_SESSION['admin_nav_error']);

function navLinkSummary(array $item): string
{
    return match ($item['link_type']) {
        'page' => admin_t('navigation.cms_page'),
        'route' => 'Route: ' . (string) $item['target_route'],
        'external' => (string) $item['external_url'],
        'none' => admin_t('navigation.no_link'),
        default => (string) $item['link_type'],
    };
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('navigation.navigatie_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('navigation.navigatie') ?></h1>
      <p class="admin-page-head__desc"><?= admin_t('navigation.beheer_hoofdnavigatie_desktop_mobiel') ?></p>
    </div>
    <a href="/admin/navigation-item.php" class="admin-btn-link"><?= admin_te('navigation.menu_item_toevoegen') ?></a>
  </header>

  <?php if ($navError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h($navError) ?></p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('navigation.menu_item_verwijderd') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <div class="admin-page-sections" data-nav-zone data-parent-id="" data-reorder-url="/api/admin/reorder-nav-items.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php if ($topLevel === []): ?>
        <p class="admin-text-muted"><?= admin_te('navigation.menu_items') ?></p>
      <?php endif; ?>
      <?php foreach ($topLevel as $item): ?>
        <?php
          $itemId = (int) $item['id'];
          $isHidden = !(bool) $item['is_visible'];
          $children = $byParent[$itemId] ?? [];
        ?>
        <div class="admin-section-row admin-nav-item-row<?= $isHidden ? ' is-hidden-section' : '' ?>" data-nav-item-id="<?= $itemId ?>">
          <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
          <div class="admin-section-row__body">
            <p class="admin-section-row__name"><?= $h(admin_lang_summary($item, 'label')) ?></p>
            <p class="admin-section-row__note"><?= $h(navLinkSummary($item)) ?><?= $isHidden ? ' — verborgen' : '' ?></p>
          </div>
          <div class="admin-section-row__actions">
            <a href="/admin/navigation-item.php?id=<?= $itemId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
            <a href="/admin/navigation-item.php?parent_id=<?= $itemId ?>" class="admin-btn-text"><?= admin_te('navigation.submenu_item') ?></a>
            <form method="post" action="/api/admin/toggle-nav-item.php" class="admin-inline-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $itemId ?>">
              <input type="hidden" name="is_visible" value="<?= $isHidden ? '1' : '0' ?>">
              <button type="submit" class="admin-btn-text"><?= $isHidden ? 'Tonen' : 'Verbergen' ?></button>
            </form>
            <form method="post" action="/api/admin/delete-nav-item.php" class="admin-inline-form" onsubmit="return confirm('Dit menu-item verwijderen?');">
              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
              <input type="hidden" name="id" value="<?= $itemId ?>">
              <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
            </form>
          </div>
        </div>

        <?php if ($children !== []): ?>
        <div class="admin-nav-children" data-nav-zone data-parent-id="<?= $itemId ?>" data-reorder-url="/api/admin/reorder-nav-items.php" data-csrf-token="<?= $h($csrfToken) ?>">
          <?php foreach ($children as $child): ?>
            <?php $childId = (int) $child['id']; $childHidden = !(bool) $child['is_visible']; ?>
            <div class="admin-section-row admin-nav-item-row admin-nav-item-row--child<?= $childHidden ? ' is-hidden-section' : '' ?>" data-nav-item-id="<?= $childId ?>">
              <span class="admin-drag-handle" draggable="true" role="button" tabindex="0" aria-label="Sleep om te herordenen">&#8801;</span>
              <div class="admin-section-row__body">
                <p class="admin-section-row__name"><?= $h(admin_lang_summary($child, 'label')) ?></p>
                <p class="admin-section-row__note"><?= $h(navLinkSummary($child)) ?><?= $childHidden ? ' — verborgen' : '' ?></p>
              </div>
              <div class="admin-section-row__actions">
                <a href="/admin/navigation-item.php?id=<?= $childId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a>
                <form method="post" action="/api/admin/toggle-nav-item.php" class="admin-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $childId ?>">
                  <input type="hidden" name="is_visible" value="<?= $childHidden ? '1' : '0' ?>">
                  <button type="submit" class="admin-btn-text"><?= $childHidden ? 'Tonen' : 'Verbergen' ?></button>
                </form>
                <form method="post" action="/api/admin/delete-nav-item.php" class="admin-inline-form" onsubmit="return confirm('Dit menu-item verwijderen?');">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $childId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
