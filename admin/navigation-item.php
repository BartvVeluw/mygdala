<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\RouteRegistry;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\PageContent;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new NavigationRepository();

$isNew = !array_key_exists('id', $_GET);
$item = null;

if (!$isNew) {
    $idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($idParam === false || $idParam < 1) {
        http_response_code(404);
        exit(admin_t('screen.menu_item_gevonden'));
    }
    $item = $repository->findById($idParam);
    if ($item === null) {
        http_response_code(404);
        exit(admin_t('screen.menu_item_gevonden'));
    }
}

// A new child item may be pre-selected via ?parent_id=; a new top-level item
// has no parent field to choose from at all (2-level cap — see the nav_items
// migration), it can only ever be created as top-level, then have children
// added to it afterwards.
$presetParentId = null;
if ($isNew) {
    $parentIdParam = filter_input(INPUT_GET, 'parent_id', FILTER_VALIDATE_INT);
    if ($parentIdParam !== false && $parentIdParam !== null && $parentIdParam > 0) {
        $parentItem = $repository->findById($parentIdParam);
        if ($parentItem !== null && $parentItem['parent_id'] === null) {
            $presetParentId = $parentIdParam;
        }
    }
}

$isChild = $isNew ? ($presetParentId !== null) : ($item['parent_id'] !== null);
$parentLabel = null;
if ($isChild) {
    $parentRow = $isNew ? $repository->findById($presetParentId) : $repository->findById((int) $item['parent_id']);
    $parentLabel = $parentRow['label_nl'] ?? null;
}

$item ??= [
    'id' => null,
    'label_nl' => '',
    'label_en' => '',
    'link_type' => 'route',
    'target_page_id' => null,
    'target_route' => null,
    'external_url' => null,
    'open_in_new_tab' => 0,
    'parent_id' => $presetParentId,
    'is_visible' => 1,
];

$errors = $_SESSION['admin_nav_item_errors'] ?? [];
$old = $_SESSION['admin_nav_item_old'] ?? null;
unset($_SESSION['admin_nav_item_errors'], $_SESSION['admin_nav_item_old']);

function navFieldValue(?array $old, array $item, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($item[$key] ?? $default);
}

$linkType = $old['link_type'] ?? (string) $item['link_type'];
$openInNewTab = $old !== null ? !empty($old['open_in_new_tab']) : (int) $item['open_in_new_tab'] === 1;
$isVisible = $old !== null ? !empty($old['is_visible']) : (int) $item['is_visible'] === 1;

/**
 * The pages an admin may point a link at: published pages only, plus this
 * item's own current target when that page has since been set back to
 * Concept — so editing an item never silently drops a target the admin
 * cannot see. A draft page is otherwise deliberately not offered: it would
 * render as a link to a 404 the moment someone published the menu item
 * (App\Service\LinkResolver hides it on the public site regardless).
 */
$currentTargetPageId = (int) ($item['target_page_id'] ?? 0);
$linkablePages = array_values(array_filter(
    (new PageRepository())->findAllForAdmin(),
    static fn (array $p): bool => PageContent::isPublished($p) || (int) $p['id'] === $currentTargetPageId
));
$routes = RouteRegistry::all();

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$pageTitle = $isNew ? ($isChild ? admin_t('navigation.new_child') : admin_t('navigation.new_item')) : (string) $item['label_nl'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('navigation.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/navigation.php"><?= admin_t('navigation.terug_navigatie') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <?php if ($isChild && $parentLabel !== null): ?>
    <p class="admin-text-muted"><?= admin_t('navigation.submenu_item_onder', ['v1' => $h($parentLabel)]) ?></p>
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

  <form method="post" action="<?= $isNew ? '/api/admin/create-nav-item.php' : '/api/admin/update-nav-item.php' ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php endif; ?>
    <?php if ($isNew && $isChild): ?>
      <input type="hidden" name="parent_id" value="<?= $presetParentId ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2><?= admin_te('navigation.label') ?></h2>
      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('navigation.label_2') ?>*
          <input type="text" name="label_nl" maxlength="100" <?= admin_lang_required('nl') ?> value="<?= $h(navFieldValue($old, $item, 'label_nl')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('navigation.label_3') ?>*
          <input type="text" name="label_en" maxlength="100" required value="<?= $h(navFieldValue($old, $item, 'label_en')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
        <?= admin_te('navigation.zichtbaar_navigatie') ?>
      </label>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('navigation.link') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.type') ?>
          <select name="link_type" id="nav-link-type">
            <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>><?= admin_te('navigation.applicatieroute') ?></option>
            <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>><?= admin_te('navigation.cms_pagina') ?></option>
            <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>><?= admin_te('navigation.externe_url') ?></option>
            <?php if (!$isChild): ?>
              <option value="none" <?= $linkType === 'none' ? 'selected' : '' ?>><?= admin_te('navigation.link_dropdown_kop') ?></option>
            <?php endif; ?>
          </select>
        </label>
        <label class="admin-checkbox-label" style="align-self:flex-end;">
          <input type="checkbox" name="open_in_new_tab" value="1" <?= $openInNewTab ? 'checked' : '' ?>>
          <?= admin_te('navigation.open_nieuw_tabblad') ?>
        </label>
      </div>

      <label data-nav-link-field="route"><?= admin_te('navigation.route') ?>
        <select name="target_route">
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= navFieldValue($old, $item, 'target_route') === $key ? 'selected' : '' ?>><?= $h($route['label_nl']) ?> (<?= $h($route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-nav-link-field="page"><?= admin_te('navigation.cms_pagina_2') ?>
        <select name="target_page_id">
          <option value=""><?= admin_te('navigation.kies_pagina') ?></option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= navFieldValue($old, $item, 'target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h((string) $page['title']) ?><?= PageContent::isPublished($page) ? '' : ' (concept)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-nav-link-field="external"><?= admin_te('navigation.externe_url_2') ?>
        <input type="text" name="external_url" maxlength="2048" value="<?= $h(navFieldValue($old, $item, 'external_url')) ?>" placeholder="https://... of /pad">
      </label>

      <script>
        (function () {
          var select = document.getElementById('nav-link-type');
          if (!select) return;
          function sync() {
            document.querySelectorAll('[data-nav-link-field]').forEach(function (field) {
              field.hidden = field.getAttribute('data-nav-link-field') !== select.value;
            });
          }
          select.addEventListener('change', sync);
          sync();
        })();
      </script>
    </section>

    <section class="admin-card">
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <p class="admin-text-muted"><?= admin_te('navigation.verwijdert_menu_item_definitief') ?></p>
      <form method="post" action="/api/admin/delete-nav-item.php" onsubmit="return confirm('Dit menu-item verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('navigation.menu_item_verwijderen') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
