<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageAdminGroup;
use App\Service\PageContent;
use App\Service\PagePath;
use App\Service\PageTree;
use App\Repository\PageRepository;

/**
 * The Pages overview: every CMS-managed page in one list, with a "+ Nieuwe
 * pagina" button. Every page lives here on equal terms. At most two of them
 * are protected — the Homepage (the site root) and, on an installation that
 * still has one, the Shop page (it carries the storefront) — and a few are
 * served at a fixed URL, which locks their address but nothing else. Every
 * other page is created, published, edited and deleted entirely from the
 * admin.
 *
 * The head is the title and the button, nothing more: what this screen is
 * for is said once, in the info panel, which follows the help switch in the
 * shell. A row's status is a word in a coloured badge — amber for Concept,
 * green for Gepubliceerd (admin.css, "Page status") — so the colour confirms
 * the word rather than replacing it.
 *
 * Clicking a page opens admin/page.php: its settings (Title, Slug, Status,
 * SEO title, Meta description) followed by the page builder — one screen,
 * the same for an old page and a brand-new one.
 *
 * The search field filters the rows already loaded, in PHP, through
 * PageContent::matchesAdminSearch(). A GET with ?q=, like the Media Library's
 * and the blog's: it works without JavaScript, survives a reload and asks the
 * database nothing new. The info panel above it is the shared one
 * (admin/_admin_ui.php) and follows the help switch in the shell.
 *
 * A TREE IN TWO GROUPS (Pagina's 2.0, docs/pages/NESTING.md). The list is
 * App\Service\PageTree's order: a page directly above the pages under it,
 * indented one step per level. It is split in two: "Websitepagina's", open,
 * and "Service & juridisch" — terms, privacy, shipping and returns — folded
 * shut with its count, one click away rather than hidden
 * (App\Service\PageAdminGroup). A whole tree is always in its root's group.
 *
 * Folding is admin/assets/page-tree.js: a button with aria-expanded on every
 * page that has pages under it and on each group, remembered per browser in
 * localStorage — never a server write, since it is a view preference and
 * nothing else. Without the script every row is simply visible.
 *
 * A SEARCH KEEPS THE CONTEXT. A page that matches is shown with every page
 * above it, marked as context, and while a search is active nothing is
 * folded: a match can never be hidden because its parent was closed. There
 * is no pagination, so the tree is always whole.
 *
 * ONE ROW, ONE HEIGHT (Pages & Destinations 3.0, ADMIN-UI.md "Pagina's"). A
 * row is four cells in a table with a FIXED layout: the name with its address
 * on a second line, the status, the type, and the actions. The column widths
 * come from the stylesheet, never from what a row holds, so opening a parent
 * cannot widen a column, wrap the actions of every other row or push
 * Verwijderen out of view — which is what the automatic layout of the old
 * table did (docs/pages/NESTING.md §8). The address is always printed, never
 * only on hover; a long one is cut off with an ellipsis and stays whole in the
 * markup for a screen reader and in its title. The actions are one line:
 * Bewerken, Bekijken (or Voorbeeld for a concept), Subpagina toevoegen, and a
 * "…" menu for what is used rarely and cannot be undone. That menu is a
 * <details>, so it opens without the script too, and Verwijderen in it asks
 * first in the CMS's own dialog (admin_confirm_dialog()). A page that cannot
 * be deleted says why in the same menu instead of in a hover-only title.
 *
 * The rows keep their table semantics on every screen size: on a phone the
 * stylesheet stacks each row into a card, and the ARIA table roles below keep
 * a screen reader reading a table there, which a CSS display change would
 * otherwise take away.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

try {
    $pages = (new PageRepository())->findAllForAdmin();
    // Every row prints the page's name; one query for all of them.
    \App\Service\PageLocalization::preload(array_map(static fn (array $p): int => (int) $p['id'], $pages));
} catch (\Throwable $e) {
    error_log('[admin/pages.php] ' . $e->getMessage());
    $pages = null;
}

$created = isset($_GET['created']);
$deleted = isset($_GET['deleted']);

$errors = $_SESSION['admin_page_errors'] ?? [];
unset($_SESSION['admin_page_errors']);

$searchQuery = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';

/**
 * Per group, the rows to print, in tree order. With a search, a row stays
 * when its page matches or when a page below it does — the latter as context.
 *
 * @var array<string, list<array{page: array<string, mixed>, depth: int, has_children: bool, parent_id: int|null, context: bool}>>|null $groups
 */
$groups = null;
$matchCount = 0;

if ($pages !== null) {
    $pagesById = [];
    foreach ($pages as $page) {
        $pagesById[(int) $page['id']] = $page;
    }

    $matches = [];
    foreach ($pagesById as $pageId => $page) {
        if (PageContent::matchesAdminSearch($page, $searchQuery)) {
            $matches[$pageId] = true;
        }
    }
    $matchCount = count($matches);

    $shown = $matches;
    if ($searchQuery !== '') {
        foreach (array_keys($matches) as $pageId) {
            foreach (PagePath::ancestorIds($pageId) ?? [] as $ancestorId) {
                $shown[$ancestorId] = true;
            }
        }
    }

    $groups = array_fill_keys(PageAdminGroup::ALL, []);
    foreach (PageTree::ordered() as $row) {
        if (!isset($shown[$row['id']], $pagesById[$row['id']])) {
            continue;
        }

        $groups[PagePath::effectiveGroup($row['id'])][] = $row + [
            'page' => $pagesById[$row['id']],
            'context' => !isset($matches[$row['id']]),
        ];
    }
}

$groupLabels = [
    PageAdminGroup::WEBSITE => admin_t('pages.group_website'),
    PageAdminGroup::SERVICE => admin_t('pages.group_service'),
];

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('pages.title') ?> <?= admin_te('pages.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/page-tree.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('pages.title') ?></h1>
    </div>
    <a href="/admin/page-new.php" class="admin-btn-primary">+ <?= admin_te('pages.new') ?></a>
  </header>

  <?= admin_info_panel(admin_t('help.pages.overview')) ?>

  <?php /* A module's page that is missing because another page already held
           its word (App\Service\ModuleSystemPages::conflicts()). Nothing
           renames that page; the owner decides. */ ?>
  <?php foreach (\App\Service\ModuleSystemPages::conflicts() as $moduleConflict): ?>
    <p class="admin-alert admin-alert--warning" role="status"><?= admin_te('pages.module_page_conflict', [
        'module' => $moduleConflict['module_label'],
        'word' => $moduleConflict['content_key'],
        'id' => (string) $moduleConflict['page_id'],
    ]) ?></p>
  <?php endforeach; ?>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('pages.created') ?></p>
  <?php endif; ?>
  <?php if ($deleted): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('pages.deleted') ?></p>
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

  <?php if ($pages === null): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('pages.load_failed') ?></p>
  <?php elseif ($pages === []): ?>
    <p><?= admin_te('pages.empty') ?> <a href="/admin/page-new.php"><?= admin_te('pages.empty_link') ?></a>.</p>
  <?php else: ?>
    <form method="get" action="/admin/pages.php" class="admin-toolbar" role="search">
      <label class="admin-search">
        <span class="admin-visually-hidden"><?= admin_te('pages.search_label') ?></span>
        <input type="search" name="q" value="<?= $h($searchQuery) ?>" placeholder="<?= admin_te('pages.search_placeholder') ?>">
      </label>
      <button type="submit" class="admin-btn-secondary"><?= admin_te('common.search') ?></button>
      <?php if ($searchQuery !== ''): ?>
        <a href="/admin/pages.php" class="admin-btn-ghost"><?= admin_te('pages.search_clear') ?></a>
      <?php endif; ?>
    </form>

    <?php if ($matchCount === 0): ?>
      <p class="admin-text-muted"><?= admin_te('pages.search_empty', ['query' => $searchQuery]) ?></p>
    <?php else: ?>
    <div class="admin-page-tree" data-page-tree<?= $searchQuery !== '' ? ' data-page-tree-search' : '' ?>>
    <?php foreach ($groups as $groupKey => $rows): ?>
      <?php if ($rows === [] && ($groupKey !== PageAdminGroup::WEBSITE || $searchQuery !== '')) { continue; } ?>
      <?php
        $groupId = 'page-group-' . $groupKey;
        // The count is the group's pages, not the context rows a search adds.
        $groupCount = count(array_filter($rows, static fn (array $row): bool => !$row['context']));
      ?>
      <section class="admin-page-group" data-page-group="<?= $h($groupKey) ?>" data-page-group-default="<?= $groupKey === PageAdminGroup::SERVICE ? 'closed' : 'open' ?>">
        <h2 class="admin-page-group__head">
          <button type="button" class="admin-page-group__toggle" aria-expanded="true" aria-controls="<?= $h($groupId) ?>" data-page-group-toggle>
            <span class="admin-tree-caret" aria-hidden="true"></span>
            <span><?= $h($groupLabels[$groupKey]) ?></span>
            <span class="admin-page-group__count">(<?= $groupCount ?>)</span>
          </button>
        </h2>
        <div class="admin-table-wrap" id="<?= $h($groupId) ?>">
          <?php if ($rows === []): ?>
            <p class="admin-text-muted admin-page-group__empty"><?= admin_te('pages.group_empty') ?></p>
          <?php else: ?>
          <?php /* The explicit table roles are deliberate: on a phone the
                   stylesheet stacks every row into a card (display: grid),
                   and a browser drops a table's semantics when its display
                   changes unless the roles say it is one. */ ?>
          <table class="admin-table admin-page-tree__table" role="table">
            <colgroup>
              <col class="admin-page-tree__col-title">
              <col class="admin-page-tree__col-status">
              <col class="admin-page-tree__col-type">
              <col class="admin-page-tree__col-actions">
            </colgroup>
            <thead role="rowgroup">
              <tr role="row">
                <th role="columnheader" scope="col"><?= admin_te('common.title') ?></th>
                <th role="columnheader" scope="col"><?= admin_te('common.status') ?></th>
                <th role="columnheader" scope="col"><?= admin_te('common.type') ?></th>
                <th role="columnheader" scope="col"><span class="admin-visually-hidden"><?= admin_te('pages.actions') ?></span></th>
              </tr>
            </thead>
            <tbody role="rowgroup">
              <?php foreach ($rows as $row): ?>
                <?php
                  $page = $row['page'];
                  $pageId = (int) $page['id'];
                  $pageName = \App\Service\PageLocalization::name($pageId);
                  $isPublished = PageContent::isPublished($page);
                  $isProtected = PageContent::isProtected($page);
                  $hasFixedUrl = PageContent::isRouteBound($page);
                  $publicUrl = PageContent::publicUrl($page);
                  $depth = min((int) $row['depth'], 7);
                  // A module's page (Shop, Portfolio): whose it is, and
                  // whether that module is on. Its address is the module's
                  // route; with the module off it answers nothing.
                  $modulePage = \App\Service\ModuleSystemPages::forPage($page);
                  if ($modulePage !== null) {
                      $publicUrl = \App\Service\Routing\LocalizedUrl::path($modulePage['route_path']);
                  }
                  // With its module off, a module's page answers nothing, and
                  // neither does any page under it (/shop/…, /portfolio/…).
                  $moduleOff = ($modulePage !== null && !$modulePage['enabled'])
                      || \App\Service\ModuleSystemPages::inDisabledModuleSubtree($pageId);
                  // A fixed URL has no slug to build on, but the Shop's and the
                  // Portfolio's pages keep pages under them (docs/pages/NESTING.md).
                  $canHaveChildren = !$hasFixedUrl || \App\Service\ModuleSystemPages::childPrefix($page) !== null;
                  // The rows this row folds: its direct children in this list.
                  $childRowIds = [];
                  foreach ($rows as $other) {
                      if ($other['parent_id'] === $pageId) {
                          $childRowIds[] = 'page-row-' . (int) $other['id'];
                      }
                  }
                  // Why Verwijderen is not offered, said in the menu rather
                  // than in a title nobody can reach with a keyboard. A page
                  // with pages under it is not deleted from under them, and a
                  // module's page not at all (PageService::delete()).
                  $deleteRefusal = match (true) {
                      $modulePage !== null => admin_t('pages.module_page_hint', ['module' => $modulePage['module_label']]),
                      $isProtected => admin_t('pages.protected_hint'),
                      $row['has_children'] => admin_t('pages.delete_has_children'),
                      default => null,
                  };
                  $parentName = $row['parent_id'] !== null ? \App\Service\PageLocalization::name((int) $row['parent_id']) : '';
                ?>
                <tr id="page-row-<?= $pageId ?>" data-page-row="<?= $pageId ?>" data-page-parent="<?= (int) ($row['parent_id'] ?? 0) ?>" data-page-depth="<?= $depth ?>" role="row"<?= $row['context'] ? ' class="admin-page-tree__context"' : '' ?>>
                  <td role="cell" class="admin-page-tree__cell-title">
                    <div class="admin-page-tree__title">
                      <?php if ($depth > 0): ?>
                        <span class="admin-page-tree__indent" aria-hidden="true"></span>
                      <?php endif; ?>
                      <?php if ($childRowIds !== []): ?>
                        <button type="button" class="admin-page-tree__toggle" aria-expanded="true" aria-controls="<?= $h(implode(' ', $childRowIds)) ?>" data-page-tree-toggle="<?= $pageId ?>">
                          <span class="admin-tree-caret" aria-hidden="true"></span>
                          <span class="admin-visually-hidden"><?= admin_te('pages.tree_children', ['page' => $pageName]) ?></span>
                        </button>
                      <?php else: ?>
                        <span class="admin-page-tree__spacer" aria-hidden="true"></span>
                      <?php endif; ?>
                      <div class="admin-page-tree__text">
                        <span class="admin-page-tree__line">
                          <a class="admin-page-tree__name" href="/admin/page.php?id=<?= $pageId ?>" title="<?= $h($pageName) ?>"><?= $h($pageName) ?></a>
                          <?php if ($parentName !== ''): ?>
                            <span class="admin-visually-hidden">, <?= admin_te('pages.tree_child_of', ['parent' => $parentName]) ?></span>
                          <?php endif; ?>
                          <?php if ($row['context']): ?>
                            <span class="admin-badge admin-badge--muted"><?= admin_te('pages.search_context') ?></span>
                          <?php endif; ?>
                        </span>
                        <span class="admin-page-tree__line admin-page-tree__line--url">
                          <code class="admin-page-tree__url" title="<?= $h($publicUrl) ?>"><?= $h($publicUrl) ?></code>
                          <?php if ($moduleOff): ?>
                            <span class="admin-badge admin-badge--warning"><?= admin_te('pages.module_off') ?></span>
                          <?php endif; ?>
                        </span>
                      </div>
                    </div>
                  </td>
                  <td role="cell" class="admin-page-tree__cell-status"><span class="admin-badge admin-badge--<?= $isPublished ? 'published' : 'draft' ?>"><?= admin_te('page.status_' . ((string) $page['status'])) ?></span></td>
                  <td role="cell" class="admin-page-tree__cell-type">
                    <?php if ($modulePage !== null): ?>
                      <span class="admin-badge admin-badge--info"><?= admin_te('pages.module_page', ['module' => $modulePage['module_label']]) ?></span>
                    <?php elseif ($isProtected): ?>
                      <span class="admin-badge admin-badge--info"><?= admin_te('pages.protected') ?></span>
                    <?php elseif ($hasFixedUrl): ?>
                      <span class="admin-badge admin-badge--muted"><?= admin_te('pages.fixed_url') ?></span>
                    <?php else: ?>
                      <span class="admin-badge admin-badge--muted"><?= admin_te('pages.content_page') ?></span>
                    <?php endif; ?>
                  </td>
                  <td role="cell" class="admin-page-tree__cell-actions">
                    <div class="admin-page-tree__actions">
                      <a href="/admin/page.php?id=<?= $pageId ?>" class="admin-section-row__edit admin-page-tree__edit"><?= admin_te('common.edit') ?><span class="admin-visually-hidden">: <?= $h($pageName) ?></span> <span aria-hidden="true">&#8594;</span></a>
                      <?php /* Where visitors see it; a draft has no public
                               address yet, so it opens the editors-only
                               preview, as the editor's own button does. With
                               the module off the address answers nothing, and
                               the slot stays empty so the line keeps its
                               place. */ ?>
                      <?php if ($moduleOff): ?>
                        <span class="admin-page-tree__action admin-page-tree__action--view" aria-hidden="true"></span>
                      <?php elseif ($isPublished): ?>
                        <a class="admin-page-tree__action admin-page-tree__action--view" href="<?= $h($publicUrl) ?>" target="_blank" rel="noopener"><?= admin_te('pages.open') ?><span class="admin-visually-hidden">: <?= $h($pageName) ?></span></a>
                      <?php else: ?>
                        <a class="admin-page-tree__action admin-page-tree__action--view" href="/admin/page-preview.php?id=<?= $pageId ?>" target="_blank" rel="noopener"><?= admin_te('pages.preview') ?><span class="admin-visually-hidden">: <?= $h($pageName) ?></span></a>
                      <?php endif; ?>
                      <?php if ($canHaveChildren): ?>
                        <?php /* Two labels: the whole one, and "+ Subpagina" that a
                                 narrower screen shows instead. The short one is
                                 hidden from a screen reader, which always hears
                                 the whole name. */ ?>
                        <a class="admin-page-tree__action admin-page-tree__action--child" href="/admin/page-new.php?parent=<?= $pageId ?>"><span class="admin-page-tree__label-long"><?= admin_te('pages.add_child') ?><span class="admin-visually-hidden"> <?= admin_te('pages.add_child_under', ['page' => $pageName]) ?></span></span><span class="admin-page-tree__label-short" aria-hidden="true">+ <?= admin_te('pages.add_child_short') ?></span></a>
                      <?php else: ?>
                        <span class="admin-page-tree__action admin-page-tree__action--child" aria-hidden="true"></span>
                      <?php endif; ?>
                      <details class="admin-row-menu" data-row-menu>
                        <summary class="admin-row-menu__toggle" aria-label="<?= admin_te('pages.more_actions', ['page' => $pageName]) ?>"><span aria-hidden="true">&hellip;</span></summary>
                        <div class="admin-row-menu__panel">
                          <?php if ($deleteRefusal === null): ?>
                            <form method="post" action="/api/admin/delete-page.php" class="admin-inline-form admin-row-menu__form"<?= admin_confirm_attributes(
                                admin_t('pages.delete_confirm_title'),
                                admin_t('pages.delete_confirm_named', ['page' => $pageName]),
                                admin_t('common.delete')
                            ) ?>>
                              <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                              <input type="hidden" name="id" value="<?= $pageId ?>">
                              <button type="submit" class="admin-row-menu__item admin-row-menu__item--danger"><?= admin_te('common.delete') ?></button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="admin-row-menu__item" disabled aria-describedby="page-row-<?= $pageId ?>-delete-why"><?= admin_te('common.delete') ?></button>
                            <p class="admin-row-menu__note" id="page-row-<?= $pageId ?>-delete-why"><?= $h($deleteRefusal) ?></p>
                          <?php endif; ?>
                        </div>
                      </details>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  <?= admin_confirm_dialog() ?>
</main>
</body>
</html>
