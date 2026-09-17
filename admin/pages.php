<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageContent;
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
$visiblePages = $pages === null
    ? null
    : array_values(array_filter(
        $pages,
        static fn (array $page): bool => PageContent::matchesAdminSearch($page, $searchQuery)
    ));

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

    <?php if ($visiblePages === []): ?>
      <p class="admin-text-muted"><?= admin_te('pages.search_empty', ['query' => $searchQuery]) ?></p>
    <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= admin_te('common.title') ?></th>
            <th><?= admin_te('common.url') ?></th>
            <th><?= admin_te('common.status') ?></th>
            <th><?= admin_te('common.type') ?></th>
            <th></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($visiblePages as $page): ?>
            <?php
              $pageId = (int) $page['id'];
              $isPublished = PageContent::isPublished($page);
              $isProtected = PageContent::isProtected($page);
              $hasFixedUrl = PageContent::isRouteBound($page);
              $publicUrl = PageContent::publicUrl($page);
            ?>
            <tr>
              <td><a href="/admin/page.php?id=<?= $pageId ?>"><?= $h(\App\Service\PageLocalization::name($pageId)) ?></a></td>
              <td><code><?= $h($publicUrl) ?></code></td>
              <td><span class="admin-badge admin-badge--<?= $isPublished ? 'published' : 'draft' ?>"><?= admin_te('page.status_' . ((string) $page['status'])) ?></span></td>
              <td>
                <?php if ($isProtected): ?>
                  <span class="admin-badge admin-badge--info" title="<?= admin_te('pages.protected_hint') ?>"><?= admin_te('pages.protected') ?></span>
                <?php elseif ($hasFixedUrl): ?>
                  <span class="admin-badge admin-badge--muted" title="<?= admin_te('pages.fixed_url_hint') ?>"><?= admin_te('pages.fixed_url') ?></span>
                <?php else: ?>
                  <span class="admin-badge admin-badge--muted"><?= admin_te('pages.content_page') ?></span>
                <?php endif; ?>
              </td>
              <td><a href="/admin/page.php?id=<?= $pageId ?>" class="admin-section-row__edit"><?= admin_te('common.edit') ?> &#8594;</a></td>
              <td>
                <?php if ($isProtected): ?>
                  <span class="admin-text-muted">&mdash;</span>
                <?php else: ?>
                  <form method="post" action="/api/admin/delete-page.php" class="admin-inline-form" onsubmit="return confirm(<?= $h(json_encode(admin_t('pages.delete_confirm'), JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $pageId ?>">
                    <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
