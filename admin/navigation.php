<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_link_destination.php';

use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\NavigationLocalization;
use App\Service\NavigationPresentation;
use App\Service\NavigationTree;
use App\Service\RouteRegistry;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * Header & navigatie: everything an editor manages at the top of every page
 * of the website, on one screen — the menu (links, with submenu items on
 * two more levels at most: NavigationRepository::MAX_DEPTH) and the header
 * buttons. Both are nav_items rows; the presentation column tells them apart
 * (App\Service\NavigationPresentation, HEADER-FOOTER.md). The logo is Instellingen's, the colours are
 * Vormgeving's, and the header's structure is Core's.
 *
 * TWO LISTS, EACH WITH ITS OWN ORDER. The menu and the buttons appear in
 * different places in the header, so they are ordered separately
 * (NavigationRepository): ↑/↓ on every row, which work with a keyboard, on a
 * phone and without JavaScript (api/admin/move-nav-item.php), and drag and
 * drop for a mouse (admin/assets/admin.js).
 *
 * THE MENU IS ONE TREE (HEADER-FOOTER.md, "Verplaatsen"). A menu row may be
 * dragged anywhere in it: before or after another row on any level, or onto
 * a menu link to join its submenu. Each drop is one request
 * (api/admin/place-nav-item.php, NavigationRepository::place()); the screen
 * then reloads, so what it shows is always what is stored. Every row carries
 * its level, the height of its own submenu and whether it is a heading, read
 * from App\Service\NavigationTree, so the script only offers a drop the
 * server accepts — and the server judges again anyway. The buttons are a
 * flat list of their own and keep the simple drag within it
 * (reorder-nav-items.php). The drag handle is aria-hidden: ↑/↓ and the
 * editor's "Bovenliggend item" are the accessible ways, and a handle that
 * pretends to be a button but does nothing with Enter is not.
 *
 * WHAT A ROW SAYS. The destination in words ("Pagina: Contact", not
 * "link_type=page"), whether the item is hidden, and — when its target cannot
 * be reached right now, say a page back on Concept or a route of a
 * switched-off module — that it is not on the website and will come back by
 * itself. LinkResolver is asked the same question the public header asks, so
 * the screen can never disagree with the site. The words and that question
 * live in admin/_link_destination.php, shared with the Footer screen.
 *
 * Deleting asks first in the CMS's own dialog (admin_confirm_attributes(),
 * ADMIN-UI.md). An item with submenu items may be deleted too; the dialog
 * says its submenu items move up one level into its place
 * (NavigationRepository::delete()).
 *
 * A ROW NOTHING CAN REACH — under a parent that is gone, or in a loop written
 * into the database by hand — is not in the tree and not on the website. It
 * is listed below the menu with a warning, never walked into, so a broken
 * table cannot hang this screen; its editor offers the top level to repair it.
 *
 * NOT HERE: editing an item (admin/navigation-item.php) and everything in
 * the footer, which has one screen of its own (admin/footer.php).
 */

$repository = new NavigationRepository();
$allItems = $repository->findAllForAdmin();
$tree = new NavigationTree($allItems);
NavigationLocalization::preload(array_map(static fn (array $item): int => (int) $item['id'], $allItems));

$menuByParent = [];
$buttons = [];
foreach ($allItems as $item) {
    if (NavigationPresentation::isButton($item) && $item['parent_id'] === null) {
        $buttons[] = $item;
        continue;
    }
    $parentId = $item['parent_id'] === null ? 0 : (int) $item['parent_id'];
    $menuByParent[$parentId][] = $item;
}
$topLevel = $menuByParent[0] ?? [];

$pagesById = [];
foreach ((new PageRepository())->findAllForAdmin() as $page) {
    $pagesById[(int) $page['id']] = $page;
}
\App\Service\PageLocalization::preload(array_keys($pagesById));
$routes = RouteRegistry::all();

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$deleted = (string) ($_GET['deleted'] ?? '');
$moved = ($_GET['moved'] ?? '') !== '';
$unreachable = array_map(static fn (int $id): array => $tree->row($id), $tree->unreachableIds());
$searchSaved = ($_GET['saved'] ?? '') === '1';
$searchEnabled = \App\Service\Search\SearchService::isEnabled();
$navError = $_SESSION['admin_nav_error'] ?? null;
unset($_SESSION['admin_nav_error']);

/**
 * One row of either list. $position/$count drive ↑/↓; $childCount decides
 * what deleting it says and whether it gets the button that folds
 * its submenu away (admin/assets/navigation-tree.js: a view of this screen
 * only, nothing is stored on the server); $canHaveChildren whether it offers
 * "+ Submenu-item" (a menu link above the deepest level).
 *
 * @param array<string, mixed> $item
 * @param array<int, array<string, mixed>> $pagesById
 * @param array<string, array<string, mixed>> $routes
 */
function navigation_row(array $item, int $position, int $count, int $childCount, bool $canHaveChildren, array $pagesById, array $routes, string $csrfToken, ?NavigationTree $tree = null): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = (int) $item['id'];
    $isHidden = !(bool) $item['is_visible'];
    $isChild = $item['parent_id'] !== null;
    $isButton = NavigationPresentation::isButton($item);
    // An item that follows its page has no words of its own: the row is
    // called what the menu shows, the page's name.
    $follows = NavigationLocalization::followsDestination($item);
    $label = NavigationLocalization::adminName($item);
    $isReachable = admin_link_is_reachable($item);
    // A menu row in the tree tells the drag script where it stands
    // (NavigationTree); a button row and a row nothing reaches do not.
    $level = $tree?->levelOf($id);
    $treeAttributes = $level === null ? '' : ' data-nav-level="' . $level . '"'
        . ' data-nav-height="' . $tree->heightOf($id) . '"'
        . ((string) $item['link_type'] === 'none' ? ' data-nav-heading' : '');
    ?>
    <div class="admin-section-row admin-nav-item-row<?= $isChild ? ' admin-nav-item-row--child' : '' ?><?= $isHidden ? ' is-hidden-section' : '' ?>" id="nav-item-<?= $id ?>" data-nav-item-id="<?= $id ?>"<?= $treeAttributes ?>>
      <?php if ($level !== null || $isButton): ?>
        <span class="admin-drag-handle" draggable="true" aria-hidden="true" title="<?= admin_te('navigation.drag_label', ['item' => $label]) ?>">&#8801;</span>
      <?php endif; ?>
      <?php if ($childCount > 0): ?>
        <button type="button" class="admin-nav-tree__toggle" aria-expanded="true" aria-controls="nav-children-<?= $id ?>" data-nav-tree-toggle="<?= $id ?>">
          <span class="admin-tree-caret" aria-hidden="true"></span>
          <span class="admin-visually-hidden"><?= admin_te('navigation.tree_children', ['item' => $label]) ?></span>
        </button>
      <?php endif; ?>
      <div class="admin-section-row__body">
        <p class="admin-section-row__name">
          <?= $h($label) ?>
          <?php if ($follows): ?>
            <span class="admin-badge admin-badge--muted"><?= admin_te('navigation.badge_follows_title') ?></span>
          <?php endif; ?>
          <?php if ($isHidden): ?>
            <span class="admin-badge admin-badge--muted"><?= admin_te('common.hidden') ?></span>
          <?php elseif (!$isReachable): ?>
            <span class="admin-badge admin-badge--warning"><?= admin_te('navigation.badge_not_on_site') ?></span>
          <?php endif; ?>
          <?php if ($isButton): ?>
            <span class="admin-badge admin-badge--info"><?= admin_te('navigation.variant_' . NavigationPresentation::variantOf($item)) ?></span>
          <?php endif; ?>
          <?php if ($childCount > 0): ?>
            <span class="admin-badge admin-badge--muted"><?= $childCount === 1 ? admin_te('navigation.child_count_one') : admin_te('navigation.child_count', ['count' => (string) $childCount]) ?></span>
          <?php endif; ?>
        </p>
        <p class="admin-section-row__note"><?= $h(admin_link_destination_summary($item, $pagesById, $routes)) ?></p>
        <?php if (!$isHidden && !$isReachable): ?>
          <p class="admin-section-row__note"><?= admin_te('navigation.not_on_site_note') ?></p>
        <?php endif; ?>
      </div>
      <div class="admin-section-row__actions">
        <a href="/admin/navigation-item.php?id=<?= $id ?>" class="admin-section-row__edit" aria-label="<?= admin_te('navigation.edit_label', ['item' => $label]) ?>"><?= admin_te('common.edit') ?> &#8594;</a>
        <form method="post" action="/api/admin/move-nav-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="direction" value="up">
          <button type="submit" class="admin-btn-ghost admin-nav-item-row__move" aria-label="<?= admin_te('navigation.move_up_label', ['item' => $label]) ?>"<?= $position === 0 ? ' disabled' : '' ?>><span aria-hidden="true">&uarr;</span></button>
        </form>
        <form method="post" action="/api/admin/move-nav-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="direction" value="down">
          <button type="submit" class="admin-btn-ghost admin-nav-item-row__move" aria-label="<?= admin_te('navigation.move_down_label', ['item' => $label]) ?>"<?= $position === $count - 1 ? ' disabled' : '' ?>><span aria-hidden="true">&darr;</span></button>
        </form>
        <?php if ($canHaveChildren): ?>
          <a href="/admin/navigation-item.php?parent_id=<?= $id ?>" class="admin-btn-text"><?= admin_te('navigation.add_child') ?></a>
        <?php endif; ?>
        <form method="post" action="/api/admin/toggle-nav-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="is_visible" value="<?= $isHidden ? '1' : '0' ?>">
          <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te('common.show') : admin_te('common.hide') ?></button>
        </form>
        <form method="post" action="/api/admin/delete-nav-item.php" class="admin-inline-form admin-section-row__delete"<?= admin_confirm_attributes(
            admin_t($isButton ? 'navigation.delete_button_title' : 'navigation.delete_link_title'),
            admin_t($isButton ? 'navigation.delete_button_message' : ($childCount > 0 ? 'navigation.delete_link_message_children' : 'navigation.delete_link_message'), ['item' => $label]),
            admin_t('common.delete')
        ) ?>>
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te('common.delete') ?></button>
        </form>
      </div>
    </div>
    <?php
}

/**
 * The menu's rows on one level, each followed by its own submenu as a nested
 * zone with its own order (drag, ↑/↓). Every row that exists is shown, on
 * whatever level it is, so the screen never hides a stored item; only rows
 * above NavigationRepository::MAX_DEPTH offer "+ Submenu-item".
 *
 * @param list<array<string, mixed>> $rows
 * @param array<int, list<array<string, mixed>>> $menuByParent
 * @param array<int, array<string, mixed>> $pagesById
 * @param array<string, array<string, mixed>> $routes
 */
function navigation_menu_rows(array $rows, int $level, array $menuByParent, array $pagesById, array $routes, string $csrfToken, NavigationTree $tree): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    foreach ($rows as $position => $item) {
        $itemId = (int) $item['id'];
        $children = $menuByParent[$itemId] ?? [];
        $canHaveChildren = !NavigationPresentation::isButton($item) && $level < NavigationRepository::MAX_DEPTH;
        navigation_row($item, $position, count($rows), count($children), $canHaveChildren, $pagesById, $routes, $csrfToken, $tree);
        if ($children === []) {
            continue;
        }
        ?>
        <div class="admin-nav-children" id="nav-children-<?= $itemId ?>" data-parent-id="<?= $itemId ?>">
          <?php navigation_menu_rows($children, $level + 1, $menuByParent, $pagesById, $routes, $csrfToken, $tree); ?>
        </div>
        <?php
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('navigation.screen_title') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('navigation.screen_title') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('navigation.screen_desc') ?></p>
    </div>
  </header>

  <?= admin_info_panel(admin_t('help.navigation.overview')) ?>

  <?php if ($navError !== null): ?>
    <p class="admin-alert admin-alert--error"><?= $h((string) $navError) ?></p>
  <?php endif; ?>
  <?php if ($moved): ?>
    <p class="admin-alert admin-alert--success" role="status"><?= admin_te('navigation.moved') ?></p>
  <?php endif; ?>
  <?php if ($deleted === NavigationPresentation::BUTTON): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('navigation.button_deleted') ?></p>
  <?php elseif ($deleted !== ''): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('navigation.menu_item_verwijderd') ?></p>
  <?php endif; ?>

  <section class="admin-card" aria-labelledby="navigation-menu-heading">
    <div class="admin-card__heading">
      <h2 id="navigation-menu-heading"><?= admin_te('navigation.menu_heading') ?></h2>
      <a href="/admin/navigation-item.php" class="admin-btn-primary"><?= admin_te('navigation.add_link') ?></a>
    </div>
    <p class="admin-text-muted"><?= admin_te('navigation.menu_intro') ?></p>

    <?php if ($topLevel === []): ?>
      <p class="admin-text-muted"><?= admin_te('navigation.menu_empty') ?></p>
    <?php endif; ?>
    <p class="admin-alert admin-alert--error" role="alert" data-nav-tree-error hidden></p>
    <?php /* The whole menu is one drop area (admin/assets/admin.js,
             initNavTree()); MAX_DEPTH is the server's, handed over. */ ?>
    <div class="admin-page-sections admin-nav-tree" data-nav-tree data-max-depth="<?= NavigationRepository::MAX_DEPTH ?>" data-place-url="/api/admin/place-nav-item.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php navigation_menu_rows($topLevel, 1, $menuByParent, $pagesById, $routes, $csrfToken, $tree); ?>
    </div>

    <?php if ($unreachable !== []): ?>
      <div class="admin-nav-unreachable">
        <p class="admin-alert admin-alert--warning"><?= admin_te('navigation.unreachable_note') ?></p>
        <div class="admin-page-sections">
          <?php foreach ($unreachable as $item): ?>
            <?php navigation_row($item, 0, 1, 0, false, $pagesById, $routes, $csrfToken); ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-card" aria-labelledby="navigation-buttons-heading">
    <div class="admin-card__heading">
      <h2 id="navigation-buttons-heading"><?= admin_te('navigation.buttons_heading') ?></h2>
      <a href="/admin/navigation-item.php?presentation=button" class="admin-btn-primary"><?= admin_te('navigation.add_button') ?></a>
    </div>
    <p class="admin-text-muted"><?= admin_te('navigation.buttons_intro') ?></p>

    <?php if ($buttons === []): ?>
      <p class="admin-text-muted"><?= admin_te('navigation.buttons_empty') ?></p>
    <?php endif; ?>
    <div class="admin-page-sections" data-nav-zone data-parent-id="" data-presentation="button" data-reorder-url="/api/admin/reorder-nav-items.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php foreach ($buttons as $position => $button): ?>
        <?php navigation_row($button, $position, count($buttons), 0, false, $pagesById, $routes, $csrfToken); ?>
      <?php endforeach; ?>
    </div>
  </section>

  <?php /* The site search in the header (SEARCH.md): one switch, off until
           an administrator turns it on. api/admin/update-navigation-settings.php */ ?>
  <section class="admin-card" id="navigation-search" aria-labelledby="navigation-search-heading">
    <div class="admin-card__heading">
      <h2 id="navigation-search-heading"><?= admin_te('navigation.search_heading') ?></h2>
    </div>
    <p class="admin-text-muted"><?= admin_te('navigation.search_intro') ?></p>
    <?php if ($searchSaved): ?>
      <p class="admin-alert admin-alert--success" role="status"><?= admin_te($searchEnabled ? 'navigation.search_saved_on' : 'navigation.search_saved_off') ?></p>
    <?php endif; ?>
    <form method="post" action="/api/admin/update-navigation-settings.php" class="admin-product-form admin-navigation-search" data-no-dirty-track>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-switch" role="switch" name="<?= $h(\App\Service\Search\SearchService::SETTING) ?>" value="1" aria-describedby="navigation-search-help"<?= $searchEnabled ? ' checked' : '' ?>>
        <?= admin_te('navigation.search_toggle') ?>
      </label>
      <p class="admin-text-muted" id="navigation-search-help"><?= admin_te('navigation.search_help') ?></p>
      <div class="admin-card--actions">
        <button type="submit" class="admin-btn-primary"><?= admin_te('common.save') ?></button>
      </div>
    </form>
  </section>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-tree.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/navigation-drag.js') ?>" defer></script>
</body>
</html>
