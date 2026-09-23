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
use App\Service\RouteRegistry;

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

/**
 * Header & navigatie: everything an editor manages at the top of every page
 * of the website, on one screen — the menu (links, with at most one level of
 * submenu items) and the header buttons. Both are nav_items rows; the
 * presentation column tells them apart (App\Service\NavigationPresentation,
 * HEADER-FOOTER.md). The logo is Instellingen's, the colours are
 * Vormgeving's, and the header's structure is Core's.
 *
 * TWO LISTS, EACH WITH ITS OWN ORDER. The menu and the buttons appear in
 * different places in the header, so they are ordered separately
 * (NavigationRepository): ↑/↓ on every row, which work with a keyboard, on a
 * phone and without JavaScript (api/admin/move-nav-item.php), and the old
 * drag-and-drop for a mouse (reorder-nav-items.php, admin/assets/admin.js).
 * The drag handle is aria-hidden: ↑/↓ are the accessible way, and a handle
 * that pretends to be a button but does nothing with Enter is not.
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
 * ADMIN-UI.md); an item with submenu items offers no delete at all and says
 * why, instead of a button the endpoint would refuse.
 *
 * NOT HERE: editing an item (admin/navigation-item.php) and everything in
 * the footer, which has one screen of its own (admin/footer.php).
 */

$repository = new NavigationRepository();
$allItems = $repository->findAllForAdmin();
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
$navError = $_SESSION['admin_nav_error'] ?? null;
unset($_SESSION['admin_nav_error']);

/**
 * One row of either list. $position/$count drive ↑/↓; $childCount decides
 * whether the row may be deleted and whether it offers "+ Submenu-item".
 *
 * @param array<string, mixed> $item
 * @param array<int, array<string, mixed>> $pagesById
 * @param array<string, array<string, mixed>> $routes
 */
function navigation_row(array $item, int $position, int $count, int $childCount, array $pagesById, array $routes, string $csrfToken): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = (int) $item['id'];
    $isHidden = !(bool) $item['is_visible'];
    $isChild = $item['parent_id'] !== null;
    $isButton = NavigationPresentation::isButton($item);
    $label = NavigationLocalization::name($id);
    $isReachable = admin_link_is_reachable($item);
    ?>
    <div class="admin-section-row admin-nav-item-row<?= $isChild ? ' admin-nav-item-row--child' : '' ?><?= $isHidden ? ' is-hidden-section' : '' ?>" id="nav-item-<?= $id ?>" data-nav-item-id="<?= $id ?>">
      <span class="admin-drag-handle" draggable="true" aria-hidden="true">&#8801;</span>
      <div class="admin-section-row__body">
        <p class="admin-section-row__name">
          <?= $h($label) ?>
          <?php if ($isHidden): ?>
            <span class="admin-badge admin-badge--muted"><?= admin_te('common.hidden') ?></span>
          <?php elseif (!$isReachable): ?>
            <span class="admin-badge admin-badge--warning"><?= admin_te('navigation.badge_not_on_site') ?></span>
          <?php endif; ?>
          <?php if ($isButton): ?>
            <span class="admin-badge admin-badge--info"><?= admin_te('navigation.variant_' . NavigationPresentation::variantOf($item)) ?></span>
          <?php endif; ?>
        </p>
        <p class="admin-section-row__note"><?= $h(admin_link_destination_summary($item, $pagesById, $routes)) ?></p>
        <?php if (!$isHidden && !$isReachable): ?>
          <p class="admin-section-row__note"><?= admin_te('navigation.not_on_site_note') ?></p>
        <?php endif; ?>
        <?php if ($childCount > 0): ?>
          <p class="admin-section-row__note"><?= admin_te('navigation.delete_children_first') ?></p>
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
        <?php if (!$isChild && !$isButton): ?>
          <a href="/admin/navigation-item.php?parent_id=<?= $id ?>" class="admin-btn-text"><?= admin_te('navigation.add_child') ?></a>
        <?php endif; ?>
        <form method="post" action="/api/admin/toggle-nav-item.php" class="admin-inline-form">
          <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="is_visible" value="<?= $isHidden ? '1' : '0' ?>">
          <button type="submit" class="admin-btn-secondary admin-section-row__button"><?= $isHidden ? admin_te('common.show') : admin_te('common.hide') ?></button>
        </form>
        <?php if ($childCount === 0): ?>
          <form method="post" action="/api/admin/delete-nav-item.php" class="admin-inline-form admin-section-row__delete"<?= admin_confirm_attributes(
              admin_t($isButton ? 'navigation.delete_button_title' : 'navigation.delete_link_title'),
              admin_t($isButton ? 'navigation.delete_button_message' : 'navigation.delete_link_message', ['item' => $label]),
              admin_t('common.delete')
          ) ?>>
            <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="admin-btn-danger admin-section-row__button"><?= admin_te('common.delete') ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php
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
    <div class="admin-page-sections" data-nav-zone data-parent-id="" data-presentation="link" data-reorder-url="/api/admin/reorder-nav-items.php" data-csrf-token="<?= $h($csrfToken) ?>">
      <?php foreach ($topLevel as $position => $item): ?>
        <?php
          $itemId = (int) $item['id'];
          $children = $menuByParent[$itemId] ?? [];
          navigation_row($item, $position, count($topLevel), count($children), $pagesById, $routes, $csrfToken);
        ?>
        <?php if ($children !== []): ?>
        <div class="admin-nav-children" data-nav-zone data-parent-id="<?= $itemId ?>" data-presentation="link" data-reorder-url="/api/admin/reorder-nav-items.php" data-csrf-token="<?= $h($csrfToken) ?>">
          <?php foreach ($children as $childPosition => $child): ?>
            <?php navigation_row($child, $childPosition, count($children), 0, $pagesById, $routes, $csrfToken); ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
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
        <?php navigation_row($button, $position, count($buttons), 0, $pagesById, $routes, $csrfToken); ?>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?= admin_confirm_dialog() ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>"></script>
</body>
</html>
