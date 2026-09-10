<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
        exit('Menu-item niet gevonden.');
    }
    $item = $repository->findById($idParam);
    if ($item === null) {
        http_response_code(404);
        exit('Menu-item niet gevonden.');
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
$pageTitle = $isNew ? ($isChild ? 'Nieuw submenu-item' : 'Nieuw menu-item') : (string) $item['label_nl'];
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/navigation.php">&larr; Terug naar navigatie</a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <?php if ($isChild && $parentLabel !== null): ?>
    <p class="admin-text-muted">Submenu-item onder "<?= $h($parentLabel) ?>".</p>
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
      <h2>Label</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Label (NL)*
          <input type="text" name="label_nl" maxlength="100" required value="<?= $h(navFieldValue($old, $item, 'label_nl')) ?>">
        </label>
        <label>Label (EN)*
          <input type="text" name="label_en" maxlength="100" required value="<?= $h(navFieldValue($old, $item, 'label_en')) ?>">
        </label>
      </div>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
        Zichtbaar in de navigatie
      </label>
    </section>

    <section class="admin-card">
      <h2>Link</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Type
          <select name="link_type" id="nav-link-type">
            <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>>Applicatieroute</option>
            <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>>CMS-pagina</option>
            <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>>Externe URL</option>
            <?php if (!$isChild): ?>
              <option value="none" <?= $linkType === 'none' ? 'selected' : '' ?>>Geen link (dropdown-kop)</option>
            <?php endif; ?>
          </select>
        </label>
        <label class="admin-checkbox-label" style="align-self:flex-end;">
          <input type="checkbox" name="open_in_new_tab" value="1" <?= $openInNewTab ? 'checked' : '' ?>>
          Open in nieuw tabblad
        </label>
      </div>

      <label data-nav-link-field="route">Route
        <select name="target_route">
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= navFieldValue($old, $item, 'target_route') === $key ? 'selected' : '' ?>><?= $h($route['label_nl']) ?> (<?= $h($route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-nav-link-field="page">CMS-pagina
        <select name="target_page_id">
          <option value="">— Kies een pagina —</option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= navFieldValue($old, $item, 'target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h((string) $page['title']) ?><?= PageContent::isPublished($page) ? '' : ' (concept)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-nav-link-field="external">Externe URL
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
      <button type="submit">Opslaan</button>
    </section>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2>Verwijderen</h2>
      <p class="admin-text-muted">Verwijdert dit menu-item definitief. Een item met submenu-items kan pas verwijderd worden nadat de submenu-items zijn verplaatst of verwijderd.</p>
      <form method="post" action="/api/admin/delete-nav-item.php" onsubmit="return confirm('Dit menu-item verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Menu-item verwijderen</button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
