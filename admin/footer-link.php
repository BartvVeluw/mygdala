<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\FooterRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FooterLocalization;
use App\Service\LinkResolver;
use App\Service\PageContent;
use App\Service\RouteRegistry;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * The editor of one footer link. The footer's counterpart of
 * admin/navigation-item.php, and deliberately the same screen in its parts:
 *
 *   Tonen       the visibility switch
 *   Tekst       the label, in ONE website language at a time
 *               (admin/_localized_fields.php), required only in the default
 *               language; a NEW link is written in the default language
 *   Bestemming  where it goes, in words: a page of this site, a fixed part of
 *               the site (RouteRegistry), another address, or — the footer's
 *               one extra — an action on the page itself, of which the only
 *               one is opening the cookie settings
 *               (LinkResolver::ALLOWED_ACTIONS)
 *
 * The destination fields that do not belong to the chosen kind are hidden by
 * admin/assets/navigation-item.js, through the same data attributes as the
 * menu editor; without JavaScript they are all on screen and
 * api/admin/_footer_link_input.php stores only the one that belongs.
 *
 * A DESTINATION THAT IS NOT AVAILABLE RIGHT NOW stays selected and is named
 * as such: a page back on Concept is offered with "(concept)", a route of a
 * switched-off module as "an unavailable part", and a warning says the link
 * is not on the website until that changes. Saving keeps it. Before Footer
 * phase B this editor silently selected the first route in the list instead.
 *
 * One form, so the save bar guards it and a refused save starts out unsaved.
 * Deleting asks first in the CMS's own dialog.
 */

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
    'link_type' => 'page',
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

$saved = !$isNew && isset($_GET['saved']) && $old === null;

// The label's language: the shell's choice on an existing link, the default
// language on a new one (api/admin/create-footer-link.php writes it there).
$editLanguage = $isNew ? admin_localized_default() : admin_localized_language();
$label = is_array($old) && ($old['language_code'] ?? null) === $editLanguage
    ? (string) ($old['label'] ?? '')
    : ($isNew ? '' : FooterLocalization::rawLinkLabel((int) $link['id'], $editLanguage));

$field = static function (string $key) use ($old, $link): string {
    if (is_array($old) && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($link[$key] ?? '');
};

$linkType = $field('link_type');
$openInNewTab = is_array($old) ? !empty($old['open_in_new_tab']) : (int) $link['open_in_new_tab'] === 1;
$isVisible = is_array($old) ? !empty($old['is_visible']) : (int) $link['is_visible'] === 1;

/**
 * The pages a link may point at: published pages only, plus this link's own
 * current target when that page has since been set back to Concept — so
 * editing a link never silently drops a target the editor cannot see.
 */
$currentTargetPageId = (int) ($link['target_page_id'] ?? 0);
$linkablePages = array_values(array_filter(
    (new PageRepository())->findAllForAdmin(),
    static fn (array $p): bool => PageContent::isPublished($p) || (int) $p['id'] === $currentTargetPageId
));
\App\Service\PageLocalization::preload(array_map(static fn (array $p): int => (int) $p['id'], $linkablePages));

// Only routes that exist right now; a switched-off module contributes none.
// A stored route that is not among them is offered as its own option, so
// saving the form keeps it (api/admin/_footer_link_input.php).
$routes = RouteRegistry::all();
$storedRoute = (string) ($link['target_route'] ?? '');
$storedRouteIsOff = !$isNew && (string) $link['link_type'] === 'route' && $storedRoute !== '' && !isset($routes[$storedRoute]);

// Is the SAVED destination reachable, as the public footer sees it? Only
// asked of what is stored, never of a refused save's input.
$unavailable = !$isNew && $old === null && LinkResolver::resolve($link) === null;

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$columnTitle = $column !== null ? FooterLocalization::columnName((int) $column['id']) : '';
if ($isNew) {
    $pageTitle = admin_t('footer.new_link');
} else {
    $pageTitle = FooterLocalization::linkName((int) $link['id']);
    if ($pageTitle === '') {
        $pageTitle = admin_t('footer.edit_link');
    }
}
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
  <p><a href="/admin/footer.php<?= $isNew ? '#footer-column-' . (int) $column['id'] : '#footer-link-' . (int) $link['id'] ?>"><?= admin_te('footer.back') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <p class="admin-text-muted"><?= admin_te('footer.in_column', ['column' => $columnTitle]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($unavailable): ?>
    <p class="admin-alert admin-alert--warning"><?= admin_te('navigation.unavailable_link') ?></p>
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

  <form method="post" action="<?= $isNew ? '/api/admin/create-footer-link.php' : '/api/admin/update-footer-link.php' ?>" class="admin-product-form" data-nav-item-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
    <?php else: ?>
      <input type="hidden" name="column_id" value="<?= (int) $column['id'] ?>">
    <?php endif; ?>

    <section class="admin-card">
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_visible" value="1"<?= $isVisible ? ' checked' : '' ?>>
          <?= admin_te('navigation.visible') ?>
        </label>
        <?= admin_help(admin_t('navigation.visible'), admin_t('help.footer.link_visible')) ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('navigation.text_heading') ?></h2>
      <?php admin_localized_bar($editLanguage); ?>
      <?php if ($isNew): ?>
        <?php admin_localized_new_item_note(admin_localized_language()); ?>
      <?php else: ?>
        <?= admin_localized_input($editLanguage) ?>
      <?php endif; ?>
      <div class="admin-field">
        <?= admin_field_label('footer-link-label', admin_t('navigation.text_label'), admin_t('help.footer.link_text'), admin_localized_required($editLanguage) !== '') ?>
        <input type="text" id="footer-link-label" name="label" maxlength="<?= FooterLocalization::LABEL_MAX_LENGTH ?>"<?= admin_localized_required($editLanguage) ?> value="<?= $h($label) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('navigation.destination_heading') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('footer-link-type', admin_t('navigation.destination_kind'), admin_t('help.footer.destination')) ?>
        <select class="admin-select" id="footer-link-type" name="link_type" data-nav-link-type>
          <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>><?= admin_te('navigation.kind_page') ?></option>
          <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>><?= admin_te('navigation.kind_route') ?></option>
          <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>><?= admin_te('navigation.kind_external') ?></option>
          <option value="action" <?= $linkType === 'action' ? 'selected' : '' ?>><?= admin_te('footer.kind_action') ?></option>
        </select>
      </div>

      <div class="admin-field" data-nav-link-field="page">
        <?= admin_field_label('footer-link-page', admin_t('navigation.page_label')) ?>
        <select class="admin-select" id="footer-link-page" name="target_page_id">
          <option value=""><?= admin_te('navigation.choose_page') ?></option>
          <?php foreach ($linkablePages as $page): ?>
            <option value="<?= (int) $page['id'] ?>" <?= $field('target_page_id') === (string) $page['id'] ? 'selected' : '' ?>><?= $h(\App\Service\PageLocalization::name((int) $page['id'])) ?><?= PageContent::isPublished($page) ? '' : ' ' . admin_te('navigation.destination_draft') ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field" data-nav-link-field="route">
        <?= admin_field_label('footer-link-route', admin_t('navigation.route_label'), admin_t('help.navigation.route')) ?>
        <select class="admin-select" id="footer-link-route" name="target_route">
          <option value=""><?= admin_te('navigation.choose_route') ?></option>
          <?php if ($storedRouteIsOff): ?>
            <option value="<?= $h($storedRoute) ?>" <?= $field('target_route') === $storedRoute ? 'selected' : '' ?>><?= admin_te('navigation.route_off_option') ?></option>
          <?php endif; ?>
          <?php foreach ($routes as $key => $route): ?>
            <option value="<?= $h($key) ?>" <?= $field('target_route') === $key ? 'selected' : '' ?>><?= $h(RouteRegistry::adminLabel((string) $key)) ?> (<?= $h((string) $route['url']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field" data-nav-link-field="external">
        <?= admin_field_label('footer-link-external', admin_t('navigation.external_label'), admin_t('help.navigation.external')) ?>
        <input type="text" id="footer-link-external" name="external_url" maxlength="2048" value="<?= $h($field('external_url')) ?>" placeholder="https://www.voorbeeld.nl" inputmode="url">
      </div>

      <div class="admin-field" data-nav-link-field="action">
        <?= admin_field_label('footer-link-action', admin_t('footer.action_label'), admin_t('help.footer.action')) ?>
        <select class="admin-select" id="footer-link-action" name="action_key">
          <?php foreach (LinkResolver::ALLOWED_ACTIONS as $action): ?>
            <option value="<?= $h($action) ?>" <?= $field('action_key') === $action ? 'selected' : '' ?>><?= admin_te('footer.action_' . $action) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field admin-field--inline" data-nav-link-field="page route external">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="open_in_new_tab" value="1"<?= $openInNewTab ? ' checked' : '' ?>>
          <?= admin_te('navigation.new_tab') ?>
        </label>
        <?= admin_help(admin_t('navigation.new_tab'), admin_t('help.navigation.new_tab')) ?>
      </div>
    </section>

    <section class="admin-card">
      <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
    </section>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <p class="admin-text-muted"><?= admin_te('footer.delete_link_explained') ?></p>
      <form method="post" action="/api/admin/delete-footer-link.php" class="admin-inline-form"<?= admin_confirm_attributes(
          admin_t('footer.delete_link_title'),
          admin_t('footer.delete_link_message', ['item' => $pageTitle]),
          admin_t('common.delete')
      ) ?>>
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
        <button type="submit" class="admin-btn-danger"><?= admin_te('footer.delete_link') ?></button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-item.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
