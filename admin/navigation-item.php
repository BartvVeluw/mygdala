<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_save_bar.php';

use App\Repository\NavigationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\LinkResolver;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
use App\Service\PageContent;
use App\Service\RouteRegistry;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * The editor of one header item: a menu link, a submenu item or a header
 * button. One screen for all three because they are one model
 * (App\Service\NavigationPresentation); what differs is which cards appear.
 *
 *   Tekst       for a page link first "Gebruik titel van bestemming": the menu
 *               shows the page's own title in every language and follows a
 *               rename; a new item starts with it on. Otherwise the label, in
 *               ONE website language at a time (admin/_localized_fields.php):
 *               the language chosen in the CMS shell, as stored, required
 *               only in the default language. A NEW item is written in the
 *               default language, and says so.
 *   Bestemming  where it goes, in words: a page of this site, a fixed part of
 *               the site (RouteRegistry), another address, or — for a
 *               top-level menu link only — nowhere, as the heading of a
 *               submenu. The technical names link_type/target_route never
 *               reach the screen.
 *   Weergave    top-level only: a link in the menu or a button in the header,
 *               and the button's style from a closed list. Hidden for a link
 *               with submenu items, which cannot become a button.
 *
 * Without JavaScript every destination field is on screen and the endpoint
 * uses only the one that belongs to the chosen kind
 * (api/admin/_nav_item_input.php). admin/assets/navigation-item.js only shows
 * the relevant field.
 *
 * A DESTINATION THAT IS NOT AVAILABLE RIGHT NOW stays selected and is named
 * as such: a page back on Concept is offered with "(concept)", a route of a
 * switched-off module is offered as "an unavailable part", and a warning says
 * the item is not on the website until that changes. Saving keeps it; it is
 * never silently replaced by the first route in the list.
 *
 * One form, so the save bar (admin/_save_bar.php) guards it, and a refused
 * save starts out unsaved. Deleting asks first in the CMS's own dialog.
 */

$repository = new NavigationRepository();

$isNew = !array_key_exists('id', $_GET);
$item = null;

if (!$isNew) {
    $idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($idParam === false || $idParam === null || $idParam < 1) {
        http_response_code(404);
        exit(admin_t('screen.menu_item_gevonden'));
    }
    $item = $repository->findById($idParam);
    if ($item === null) {
        http_response_code(404);
        exit(admin_t('screen.menu_item_gevonden'));
    }
}

// A new child item may be pre-selected via ?parent_id=, a new button via
// ?presentation=button. There is no parent field to choose from at all: an
// item is created where its "+ Submenu-item" was clicked, on level 3 at most
// (NavigationRepository::canBeParent()), and never moves afterwards.
$presetParentId = null;
$presetPresentation = NavigationPresentation::LINK;
if ($isNew) {
    $parentIdParam = filter_input(INPUT_GET, 'parent_id', FILTER_VALIDATE_INT);
    if ($parentIdParam !== false && $parentIdParam !== null && $parentIdParam > 0 && $repository->canBeParent($parentIdParam)) {
        $presetParentId = $parentIdParam;
    }
    if ($presetParentId === null && ($_GET['presentation'] ?? '') === NavigationPresentation::BUTTON) {
        $presetPresentation = NavigationPresentation::BUTTON;
    }
}

$isChild = $isNew ? ($presetParentId !== null) : ($item['parent_id'] !== null);
$parentLabel = null;
if ($isChild) {
    $parentRow = $repository->findById($isNew ? (int) $presetParentId : (int) $item['parent_id']);
    $parentLabel = $parentRow !== null ? NavigationLocalization::adminName($parentRow) : null;
}
$childCount = $isNew ? 0 : $repository->countChildren((int) $item['id']);

$item ??= [
    'id' => null,
    'link_type' => 'page',
    'target_page_id' => null,
    'target_route' => null,
    'external_url' => null,
    'open_in_new_tab' => 0,
    'presentation' => $presetPresentation,
    'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
    'parent_id' => $presetParentId,
    'is_visible' => 1,
];

$errors = $_SESSION['admin_nav_item_errors'] ?? [];
$old = $_SESSION['admin_nav_item_old'] ?? null;
unset($_SESSION['admin_nav_item_errors'], $_SESSION['admin_nav_item_old']);

$saved = !$isNew && isset($_GET['saved']) && $old === null;

// The label's language: the shell's choice on an existing item, the default
// language on a new one (api/admin/create-nav-item.php writes it there).
$editLanguage = $isNew ? admin_localized_default() : admin_localized_language();
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;
$label = $oldInThisLanguage
    ? (string) ($old['label'] ?? '')
    : ($isNew ? '' : NavigationLocalization::raw((int) $item['id'], $editLanguage));

$field = static function (string $key) use ($old, $item): string {
    if (is_array($old) && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($item[$key] ?? '');
};

$linkType = $field('link_type');

/**
 * "Gebruik titel van bestemming" (Pages & Destinations 3.0, HEADER-FOOTER.md
 * "Tekst van een menu-item"): on for a new item, which starts as a page link;
 * on for a stored page link without words of its own in the default language;
 * off for every item that has them — so every item that existed before reads
 * exactly as it did. The switch only means something for a page, so the
 * script shows it only there; the endpoint ignores it for any other kind.
 */
$labelFollows = is_array($old)
    ? !empty($old['label_follows'])
    : ($isNew || NavigationLocalization::followsDestination($item));
$labelFollowsNow = $labelFollows && $linkType === 'page';
$presentation = NavigationPresentation::of(['presentation' => $field('presentation')]);
$variant = NavigationPresentation::variantOf(['button_variant' => $field('button_variant')]);
$openInNewTab = is_array($old) ? !empty($old['open_in_new_tab']) : (int) $item['open_in_new_tab'] === 1;
$isVisible = is_array($old) ? !empty($old['is_visible']) : (int) $item['is_visible'] === 1;
$isButton = $presentation === NavigationPresentation::BUTTON;

// The presentation choice exists only where it can be used: a top-level item
// without submenu items.
$canChoosePresentation = !$isChild && $childCount === 0;

/**
 * The pages an item may point at: published pages only, plus this item's own
 * current target when that page has since been set back to Concept — so
 * editing an item never silently drops a target the editor cannot see. A
 * draft page is otherwise deliberately not offered: LinkResolver hides a link
 * to it on the public site regardless. In the Pages overview's order, a page
 * under its parent and indented (App\Service\PageOptions, the one order of
 * every page list in the CMS).
 */
$currentTargetPageId = (int) ($item['target_page_id'] ?? 0);
$linkablePages = \App\Service\PageOptions::tree(
    static fn (array $p): bool => PageContent::isPublished($p) && !\App\Service\ModuleSystemPages::isPlaceholder($p),
    [$currentTargetPageId]
);

// Only routes that exist right now; a switched-off module contributes none.
// A stored route that is not among them is offered as its own option below,
// so saving the form keeps it (api/admin/_nav_item_input.php).
$routes = RouteRegistry::all();
$storedRoute = (string) ($item['target_route'] ?? '');
$storedRouteIsOff = !$isNew && (string) $item['link_type'] === 'route' && $storedRoute !== '' && !isset($routes[$storedRoute]);

// Is the SAVED destination reachable, as the public header sees it? Only
// asked of what is stored, never of a refused save's input.
$unavailable = !$isNew
    && $old === null
    && (string) $item['link_type'] !== 'none'
    && LinkResolver::resolve($item) === null;

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($isNew) {
    $pageTitle = $isChild
        ? admin_t('navigation.new_child')
        : ($isButton ? admin_t('navigation.new_button') : admin_t('navigation.new_link'));
} else {
    // An item that follows its page is called by that page's name.
    $pageTitle = NavigationLocalization::adminName($item);
    if ($pageTitle === '') {
        $pageTitle = admin_t($isButton ? 'navigation.edit_button' : 'navigation.edit_link');
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($pageTitle) ?> — <?= admin_te('navigation.screen_title') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/navigation.php"><?= admin_te('navigation.back') ?></a></p>
  <h1><?= $h($pageTitle) ?></h1>
  <?php if ($isChild && $parentLabel !== null): ?>
    <p class="admin-text-muted"><?= admin_te('navigation.child_of', ['parent' => $parentLabel]) ?></p>
  <?php endif; ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?php if ($unavailable): ?>
    <p class="admin-alert admin-alert--warning"><?= admin_te($isButton ? 'navigation.unavailable_button' : 'navigation.unavailable_link') ?></p>
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

  <form method="post" action="<?= $isNew ? '/api/admin/create-nav-item.php' : '/api/admin/update-nav-item.php' ?>" class="admin-product-form" data-nav-item-form<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?php if (!$isNew): ?>
      <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
    <?php endif; ?>
    <?php if ($isNew && $isChild): ?>
      <input type="hidden" name="parent_id" value="<?= (int) $presetParentId ?>">
    <?php endif; ?>

    <section class="admin-card">
      <div class="admin-field admin-field--inline">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="is_visible" value="1" <?= $isVisible ? 'checked' : '' ?>>
          <?= admin_te('navigation.visible') ?>
        </label>
        <?= admin_help(admin_t('navigation.visible'), admin_t('help.navigation.visible')) ?>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('navigation.text_heading') ?></h2>
      <?php /* The page's title in every language, following a rename, or
               words of the item's own (NavigationLocalization::labelFor()). */ ?>
      <div class="admin-field admin-field--inline" data-nav-link-field="page">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="label_follows" value="1" data-nav-label-follows<?= $labelFollows ? ' checked' : '' ?>>
          <?= admin_te('navigation.label_follows') ?>
        </label>
        <?= admin_help(admin_t('navigation.label_follows'), admin_t('help.navigation.label_follows')) ?>
      </div>
      <p class="admin-text-muted" data-nav-label-preview aria-live="polite"<?= $labelFollowsNow ? '' : ' hidden' ?>>
        <?= admin_te('navigation.label_preview') ?>
        <strong data-nav-label-preview-title data-none="<?= admin_te('navigation.label_preview_none') ?>"><?= $h($field('target_page_id') !== '' ? \App\Service\PageLocalization::title((int) $field('target_page_id'), $editLanguage) : admin_t('navigation.label_preview_none')) ?></strong>
      </p>
      <div data-nav-label-own<?= $labelFollowsNow ? ' hidden' : '' ?>>
        <?php admin_localized_bar($editLanguage); ?>
        <?php if ($isNew): ?>
          <?php admin_localized_new_item_note(admin_localized_language()); ?>
        <?php else: ?>
          <?= admin_localized_input($editLanguage) ?>
        <?php endif; ?>
        <div class="admin-field">
          <?= admin_field_label('nav-label', admin_t('navigation.text_label'), admin_t('help.navigation.text'), admin_localized_required($editLanguage) !== '') ?>
          <?php /* Required in the default language — unless the item follows
                   its page, which the script switches as the editor does. */ ?>
          <input type="text" id="nav-label" name="label" maxlength="<?= NavigationLocalization::LABEL_MAX_LENGTH ?>"<?= $labelFollowsNow ? '' : admin_localized_required($editLanguage) ?><?= admin_localized_required($editLanguage) !== '' ? ' data-nav-label-required' : '' ?> value="<?= $h($label) ?>"<?= admin_localized_placeholder_attr($editLanguage) ?>>
        </div>
      </div>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('navigation.destination_heading') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('nav-link-type', admin_t('navigation.destination_kind'), admin_t('help.navigation.destination')) ?>
        <select class="admin-select" id="nav-link-type" name="link_type" data-nav-link-type>
          <option value="page" <?= $linkType === 'page' ? 'selected' : '' ?>><?= admin_te('navigation.kind_page') ?></option>
          <option value="route" <?= $linkType === 'route' ? 'selected' : '' ?>><?= admin_te('navigation.kind_route') ?></option>
          <option value="external" <?= $linkType === 'external' ? 'selected' : '' ?>><?= admin_te('navigation.kind_external') ?></option>
          <?php if (!$isChild): ?>
            <option value="none" data-nav-link-only <?= $linkType === 'none' ? 'selected' : '' ?>><?= admin_te('navigation.kind_none') ?></option>
          <?php endif; ?>
        </select>
      </div>

      <div class="admin-field" data-nav-link-field="page">
        <?= admin_field_label('nav-target-page', admin_t('navigation.page_label')) ?>
        <select class="admin-select" id="nav-target-page" name="target_page_id">
          <option value=""><?= admin_te('navigation.choose_page') ?></option>
          <?php foreach ($linkablePages as $option): ?>
            <?php /* A page above an offered one that is not offered itself
                     keeps the tree readable and cannot be chosen. */ ?>
            <?php /* data-title: what the menu shows for this page in the
                     language being edited, while the item follows its page. */ ?>
            <option value="<?= $option['id'] ?>"<?= $option['context'] ? ' disabled' : '' ?> <?= !$option['context'] && $field('target_page_id') === (string) $option['id'] ? 'selected' : '' ?> data-title="<?= $h(\App\Service\PageLocalization::title($option['id'], $editLanguage)) ?>"><?= $h($option['label']) ?><?= $option['context'] || PageContent::isPublished($option['page']) ? '' : ' ' . admin_te('navigation.destination_draft') ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="admin-field" data-nav-link-field="route">
        <?= admin_field_label('nav-target-route', admin_t('navigation.route_label'), admin_t('help.navigation.route')) ?>
        <select class="admin-select" id="nav-target-route" name="target_route">
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
        <?= admin_field_label('nav-external-url', admin_t('navigation.external_label'), admin_t('help.navigation.external')) ?>
        <input type="text" id="nav-external-url" name="external_url" maxlength="2048" value="<?= $h($field('external_url')) ?>" placeholder="https://www.voorbeeld.nl" inputmode="url">
      </div>

      <div class="admin-field admin-field--inline" data-nav-link-field="page route external">
        <label class="admin-checkbox-label">
          <input type="checkbox" class="admin-switch" role="switch" name="open_in_new_tab" value="1" <?= $openInNewTab ? 'checked' : '' ?>>
          <?= admin_te('navigation.new_tab') ?>
        </label>
        <?= admin_help(admin_t('navigation.new_tab'), admin_t('help.navigation.new_tab')) ?>
      </div>
    </section>

    <?php if ($canChoosePresentation): ?>
    <section class="admin-card">
      <h2><?= admin_te('navigation.appearance_heading') ?></h2>

      <div class="admin-field">
        <?= admin_field_label('nav-presentation', admin_t('navigation.presentation_label'), admin_t('help.navigation.presentation')) ?>
        <select class="admin-select" id="nav-presentation" name="presentation" data-nav-presentation>
          <option value="link" <?= !$isButton ? 'selected' : '' ?>><?= admin_te('navigation.presentation_link') ?></option>
          <option value="button" <?= $isButton ? 'selected' : '' ?>><?= admin_te('navigation.presentation_button') ?></option>
        </select>
      </div>

      <div class="admin-field" data-nav-button-field>
        <?= admin_field_label('nav-button-variant', admin_t('navigation.variant_label'), admin_t('help.navigation.variant')) ?>
        <select class="admin-select" id="nav-button-variant" name="button_variant">
          <?php foreach (NavigationPresentation::variants() as $option): ?>
            <option value="<?= $h($option) ?>" <?= $variant === $option ? 'selected' : '' ?>><?= admin_te('navigation.variant_' . $option . '_option') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </section>
    <?php elseif (!$isChild): ?>
    <section class="admin-card">
      <h2><?= admin_te('navigation.appearance_heading') ?></h2>
      <p class="admin-text-muted"><?= admin_te('navigation.presentation_fixed_children') ?></p>
    </section>
    <?php endif; ?>

    <section class="admin-card">
      <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
    </section>
  </form>

  <?php if (!$isNew): ?>
    <section class="admin-card">
      <h2><?= admin_te('common.delete') ?></h2>
      <?php if ($childCount > 0): ?>
        <p class="admin-text-muted"><?= admin_te('navigation.delete_children_first') ?></p>
      <?php else: ?>
        <p class="admin-text-muted"><?= admin_te($isButton ? 'navigation.delete_button_explained' : 'navigation.delete_link_explained') ?></p>
        <form method="post" action="/api/admin/delete-nav-item.php" class="admin-inline-form"<?= admin_confirm_attributes(
            admin_t($isButton ? 'navigation.delete_button_title' : 'navigation.delete_link_title'),
            admin_t($isButton ? 'navigation.delete_button_message' : 'navigation.delete_link_message', ['item' => $pageTitle]),
            admin_t('common.delete')
        ) ?>>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
          <button type="submit" class="admin-btn-danger"><?= admin_te($isButton ? 'navigation.delete_button' : 'navigation.delete_link') ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php save_bar(); ?>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-item.js') ?>" defer></script>
<?php save_bar_script(); ?>
</body>
</html>
