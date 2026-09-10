<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\RouteRegistry;
use App\Repository\FooterRepository;
use App\Repository\PageRepository;
use App\Service\PageContent;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$repository = new FooterRepository();

$isNew = !array_key_exists('id', $_GET);
$link = null;
$column = null;

if ($isNew) {
    $columnIdParam = filter_input(INPUT_GET, 'column_id', FILTER_VALIDATE_INT);
    if ($columnIdParam === false || $columnIdParam === null || $columnIdParam < 1) {
        http_response_code(404);
        exit('Footer-kolom niet gevonden.');
    }
    $column = $repository->findColumnById($columnIdParam);
    if ($column === null) {
        http_response_code(404);
        exit('Footer-kolom niet gevonden.');
    }
} else {
    $idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($idParam === false || $idParam === null || $idParam < 1) {
        http_response_code(404);
        exit('Footer-link niet gevonden.');
    }
    $link = $repository->findLinkById($idParam);
    if ($link === null) {
        http_response_code(404);
        exit('Footer-link niet gevonden.');
    }
    $column = $repository->findColumnById((int) $link['column_id']);
}

$link ??= [
    'id' => null,
    'column_id' => $column['id'],
    'label_nl' => '',
    'label_en' => '',
    'link_type' => 'route',
    'target_page_id' => null,
    'target_route' => null,
    'external_url' => null,
    'action_key' => null,
    'open_in_new_tab' => 0,
    'is_visible' => 1,
];

$errors = $_SESSION['admin_footer_link_errors'] ?? [];
$old = $_SESSION['admin_footer_link_old'] ?? null;
unset($_SESSION['admin_footer_link_errors'], $_SESSION['admin_footer_link_old']);

function footerLinkFieldValue(?array $old, array $link, string $key, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($link[$key] ?? $default);
}

$linkType = $old['link_type'] ?? (string) $link['link_type'];
$openInNewTab = $old !== null ? !empty($old['open_in_new_tab']) : (int) $link['open_in_new_tab'] === 1;
$isVisible = $old !== null ? !empty($old['is_visible']) : (int) $link['is_visible'] === 1;

/**
 * The pages an admin may point a link at: published pages only, plus this
 * item's own current target when that page has since been set back to
 * Concept — so editing an item never silently drops a target the admin
 * cannot see. A draft page is otherwise deliberately not offered: it would
 * render as a link to a 404 the moment someone published the menu item
 * (App\Service\LinkResolver hides it on the public site regardless).
 */
$currentTargetPageId = (int) ($link['target_page_id'] ?? 0);
$linkablePages = array_values(array_filter(
    (new PageRepository())->findAllForAdmin(),
    static fn (array $p): bool => PageContent::isPublished($p) || (int) $p['id'] === $currentTargetPageId
));
$routes = RouteRegistry::all();
$actions = ['cookie_preferences' => 'Cookie-instellingen openen'];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$pageTitle = $isNew ? 'Nieuwe footer-link' : (string) $link['label_nl'];
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/footer.php">&larr; Terug naar footer</a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <p class="admin-text-muted">In kolom "<?= $h((string) $column['title_nl']) ?>".</p>

  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= $isNew ? '/api/admin/create-footer-link.php' : '/api/admin/update-footer-link.php' ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
    <?php else: ?>
      <input type="hidden" name="column_id" value="<?= (int) $column['id'] ?>">
    <?php endif; ?>

    <section class="admin-card">
      <h2>Label</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Label (NL)*
          <input type="text" name="label_nl" maxlength="100" required value="<?= $h(footerLinkFieldValue($old, $link, 'label_nl')) ?>">
        </label>
        <label>Label (EN)*
          <input type="text" name="label_en" maxlength="100" required value="<?= $h(footerLinkFieldValue($old, $link, 'label_en')) ?>">
        </label>
      </div>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
        Zichtbaar
      </label>
    </section>

    <section class="admin-card">
      <h2>Link</h2>
      <div class="admin-form-row admin-form-row--split">
        <label>Type
          <select name="link_type" id="footer-link-type">
            <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>>Applicatieroute</option>
            <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>>CMS-pagina</option>
            <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>>Externe URL</option>
            <option value="action" <?= $linkType === 'action' ? 'selected' : '' ?>>Actie</option>
          </select>
        </label>
        <label class="admin-checkbox-label" style="align-self:flex-end;">
          <input type="checkbox" name="open_in_new_tab" value="1" <?= $openInNewTab ? 'checked' : '' ?>>
          Open in nieuw tabblad
        </label>
      </div>

      <label data-footer-link-field="route">Route
        <select name="target_route">
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= footerLinkFieldValue($old, $link, 'target_route') === $key ? 'selected' : '' ?>><?= $h($route['label_nl']) ?> (<?= $h($route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-footer-link-field="page">CMS-pagina
        <select name="target_page_id">
          <option value="">— Kies een pagina —</option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= footerLinkFieldValue($old, $link, 'target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h((string) $page['title']) ?><?= PageContent::isPublished($page) ? '' : ' (concept)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-footer-link-field="external">Externe / interne URL
        <input type="text" name="external_url" maxlength="2048" value="<?= $h(footerLinkFieldValue($old, $link, 'external_url')) ?>" placeholder="https://... of /pad">
      </label>

      <label data-footer-link-field="action">Actie
        <select name="action_key">
          <?php foreach ($actions as $key => $label): ?>
            <option value="<?= $h($key) ?>" <?= footerLinkFieldValue($old, $link, 'action_key') === $key ? 'selected' : '' ?>><?= $h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <script>
        (function () {
          var select = document.getElementById('footer-link-type');
          if (!select) return;
          function sync() {
            document.querySelectorAll('[data-footer-link-field]').forEach(function (field) {
              field.hidden = field.getAttribute('data-footer-link-field') !== select.value;
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
      <form method="post" action="/api/admin/delete-footer-link.php" onsubmit="return confirm('Deze link verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger">Link verwijderen</button>
      </form>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
