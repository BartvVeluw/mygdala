<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

require_once __DIR__ . '/_language_fields.php';

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
        exit(admin_t('screen.footer_kolom_gevonden'));
    }
    $column = $repository->findColumnById($columnIdParam);
    if ($column === null) {
        http_response_code(404);
        exit(admin_t('screen.footer_kolom_gevonden'));
    }
} else {
    $idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($idParam === false || $idParam === null || $idParam < 1) {
        http_response_code(404);
        exit(admin_t('screen.footer_link_gevonden'));
    }
    $link = $repository->findLinkById($idParam);
    if ($link === null) {
        http_response_code(404);
        exit(admin_t('screen.footer_link_gevonden'));
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
$pageTitle = $isNew ? admin_t('footer.new_link') : (string) $link['label_nl'];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> <?= admin_te('footer.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/footer.php"><?= admin_t('footer.terug_footer') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <p class="admin-text-muted"><?= admin_t('footer.kolom', ['v1' => $h((string) $column['title_nl'])]) ?></p>

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
      <h2><?= admin_te('footer.label') ?></h2>
      <?php admin_lang_tabs(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('footer.label_2') ?>*
          <input type="text" name="label_nl" maxlength="100" <?= admin_lang_required('nl') ?> value="<?= $h(footerLinkFieldValue($old, $link, 'label_nl')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('footer.label_3') ?>*
          <input type="text" name="label_en" maxlength="100" required value="<?= $h(footerLinkFieldValue($old, $link, 'label_en')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <label class="admin-checkbox-label">
        <input type="checkbox" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
        <?= admin_te('common.visible') ?>
      </label>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('footer.link') ?></h2>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.type') ?>
          <select name="link_type" id="footer-link-type">
            <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>><?= admin_te('footer.applicatieroute') ?></option>
            <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>><?= admin_te('footer.cms_pagina') ?></option>
            <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>><?= admin_te('footer.externe_url') ?></option>
            <option value="action" <?= $linkType === 'action' ? 'selected' : '' ?>><?= admin_te('common.action') ?></option>
          </select>
        </label>
        <label class="admin-checkbox-label" style="align-self:flex-end;">
          <input type="checkbox" name="open_in_new_tab" value="1" <?= $openInNewTab ? 'checked' : '' ?>>
          <?= admin_te('footer.open_nieuw_tabblad') ?>
        </label>
      </div>

      <label data-footer-link-field="route"><?= admin_te('footer.route') ?>
        <select name="target_route">
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= footerLinkFieldValue($old, $link, 'target_route') === $key ? 'selected' : '' ?>><?= $h($route['label_nl']) ?> (<?= $h($route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-footer-link-field="page"><?= admin_te('footer.cms_pagina_2') ?>
        <select name="target_page_id">
          <option value=""><?= admin_te('footer.kies_pagina') ?></option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= footerLinkFieldValue($old, $link, 'target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h((string) $page['title']) ?><?= PageContent::isPublished($page) ? '' : ' (concept)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label data-footer-link-field="external"><?= admin_te('footer.externe_interne_url') ?>
        <input type="text" name="external_url" maxlength="2048" value="<?= $h(footerLinkFieldValue($old, $link, 'external_url')) ?>" placeholder="https://... of /pad">
      </label>

      <label data-footer-link-field="action"><?= admin_te('common.action') ?>
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
      <button type="submit"><?= admin_te('common.save') ?></button>
    </section>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <form method="post" action="/api/admin/delete-footer-link.php" onsubmit="return confirm('Deze link verwijderen?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
        <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('footer.link_verwijderen') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php admin_lang_tabs_script(); ?>
</body>
</html>
