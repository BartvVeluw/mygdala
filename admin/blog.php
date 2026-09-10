<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogUrls;
use App\Service\Csrf;

/**
 * Beheer → Blogberichten: every post, whatever its status, with the three
 * filters an editor actually uses and a title search.
 *
 * WHY THE FILTERS ARE LINKS. Status, category and search are all query
 * parameters on this URL, so every view is bookmarkable, the back button
 * works, and the screen needs no JavaScript at all — the same reason the
 * public listing paginates with real links. The search box is the one form,
 * and it submits with GET.
 *
 * NOT A DATA-TABLE FRAMEWORK. This is the admin table markup every other
 * overview in this CMS uses (admin/forms.php, admin/pages.php), with one
 * query for the rows and one for their categories. There is no sorting
 * engine, no column chooser and no client-side pagination: a blog is
 * measured in tens of posts, and the filters answer the questions that
 * actually get asked.
 *
 * THE SCHEDULED COLUMN IS THE POINT of showing the date at all: an editor
 * needs to see that a post is waiting rather than out, which is
 * BlogPostStatus::isPending() — not a second rule, the same one the public
 * side reads through.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('blog.view');

$canManage = AdminAuth::can('blog.manage');

$statusFilter = (string) ($_GET['status'] ?? '');
$statusFilter = BlogPostStatus::isValid($statusFilter) ? $statusFilter : '';
$categoryFilter = (int) ($_GET['category'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

try {
    $repository = new BlogPostRepository();
    $posts = $repository->findForAdmin([
        'status' => $statusFilter,
        'category_id' => $categoryFilter,
        'search' => $search,
    ]);
    $postIds = array_map(static fn (array $post): int => (int) $post['id'], $posts);
    $categoriesByPost = $repository->categoriesForPosts($postIds);
    $counts = $repository->countsByStatus();
    $categories = (new BlogCategoryRepository())->all();
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/blog.php] ' . $e->getMessage());
    $posts = [];
    $categoriesByPost = [];
    $counts = [];
    $categories = [];
    $loadFailed = true;
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['admin_blog_flash'] ?? null;
$errors = $_SESSION['admin_blog_errors'] ?? [];
unset($_SESSION['admin_blog_flash'], $_SESSION['admin_blog_errors']);

$total = array_sum($counts);

/** One filter link, keeping whatever the other filters are set to. */
$filterUrl = static function (array $overrides) use ($statusFilter, $categoryFilter, $search): string {
    $query = array_filter([
        'status' => $overrides['status'] ?? $statusFilter,
        'category' => (string) ($overrides['category'] ?? ($categoryFilter ?: '')),
        'q' => $overrides['q'] ?? $search,
    ], static fn ($value): bool => (string) $value !== '');

    return '/admin/blog.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

$statusTabs = ['' => 'Alle'] + BlogPostStatus::LABELS;
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blogberichten — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title">Blogberichten</h1>
      <p class="admin-page-head__desc">Alles wat je schrijft. Een concept is nergens zichtbaar, een ingepland bericht verschijnt vanzelf zodra zijn publicatiedatum is bereikt.</p>
    </div>
    <a href="<?= $h(BlogUrls::indexPath()) ?>" class="admin-btn-secondary" target="_blank" rel="noopener">Bekijk de blog &#8594;</a>
  </header>

  <?php if ($flash !== null): ?>
    <p class="admin-alert admin-alert--success"><?= $h((string) $flash) ?></p>
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

  <?php if ($loadFailed): ?>
    <p class="admin-alert admin-alert--error">Blogberichten konden niet worden geladen.</p>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <section class="admin-card">
      <h2>Nieuw bericht</h2>
      <p class="admin-text-muted">Je geeft het bericht eerst een titel; de tekst, de afbeelding en de publicatie regel je daarna in de editor.</p>
      <form method="post" action="/api/admin/create-blog-post.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <label>Titel van het bericht*
          <input type="text" name="title" maxlength="200" required placeholder="Bijvoorbeeld: Hoe wij een ontwerp graveren">
        </label>
        <button type="submit">Bericht aanmaken</button>
      </form>
    </section>
  <?php endif; ?>

  <section class="admin-card">
    <h2>Filteren</h2>
    <nav class="admin-filter-tabs" aria-label="Filter op status">
      <?php foreach ($statusTabs as $key => $label): ?>
        <?php $count = $key === '' ? $total : ($counts[$key] ?? 0); ?>
        <a href="<?= $h($filterUrl(['status' => (string) $key])) ?>" class="admin-filter-tab<?= $statusFilter === (string) $key ? ' is-active' : '' ?>"<?= $statusFilter === (string) $key ? ' aria-current="page"' : '' ?>><?= $h((string) $label) ?> (<?= (int) $count ?>)</a>
      <?php endforeach; ?>
    </nav>

    <form method="get" action="/admin/blog.php" class="admin-product-form admin-product-form--wide">
      <?php /* The other filters ride along as hidden fields, so searching
               inside a status or a category keeps you there. */ ?>
      <?php if ($statusFilter !== ''): ?>
        <input type="hidden" name="status" value="<?= $h($statusFilter) ?>">
      <?php endif; ?>
      <div class="admin-form-row admin-form-row--split">
        <label>Zoeken op titel
          <input type="search" name="q" value="<?= $h($search) ?>" placeholder="Zoek in NL- en EN-titels">
        </label>
        <label>Categorie
          <select name="category">
            <option value="">Alle categorieën</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= $h((string) $category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div>
        <button type="submit">Filteren</button>
        <?php if ($statusFilter !== '' || $categoryFilter > 0 || $search !== ''): ?>
          <a href="/admin/blog.php" class="admin-btn-text">Filters wissen</a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <?php if (!$loadFailed && $posts === []): ?>
    <p><?= $total > 0 ? 'Geen berichten die aan deze filters voldoen.' : 'Er zijn nog geen blogberichten.' ?></p>
  <?php elseif ($posts !== []): ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Titel</th>
          <th>Status</th>
          <th>Publicatie</th>
          <th>Categorie</th>
          <th>Laatst gewijzigd</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($posts as $post): ?>
          <?php
            $postId = (int) $post['id'];
            $status = BlogPostStatus::normalize($post['status']);
            $isPending = BlogPostStatus::isPending($post);
            $isPublic = BlogPostStatus::isPublic($post);
            $postCategories = $categoriesByPost[$postId] ?? [];
            $badge = match (true) {
                $status === BlogPostStatus::DRAFT => 'muted',
                $isPending => 'pending',
                default => 'info',
            };
          ?>
          <tr>
            <td>
              <a href="/admin/blog-post.php?id=<?= $postId ?>"><?= $h((string) $post['title']) ?></a>
              <?php if ($isPublic): ?>
                <br><a class="admin-text-muted" href="<?= $h(BlogUrls::postPath((string) $post['slug'])) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::postPath((string) $post['slug'])) ?></a>
              <?php endif; ?>
            </td>
            <td><span class="admin-badge admin-badge--<?= $badge ?>"><?= $h(BlogPostStatus::label($status)) ?></span></td>
            <td>
              <?php if (BlogClock::forAdmin($post['published_at']) === ''): ?>
                <span class="admin-text-muted">—</span>
              <?php else: ?>
                <?= $h(BlogClock::forAdmin($post['published_at'])) ?>
                <?php if ($isPending): ?>
                  <br><span class="admin-text-muted">nog niet zichtbaar</span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($postCategories === []): ?>
                <span class="admin-text-muted">—</span>
              <?php else: ?>
                <?= $h(implode(', ', array_map(static fn (array $category): string => (string) $category['name'], $postCategories))) ?>
              <?php endif; ?>
            </td>
            <td><?= $h(BlogClock::forAdmin($post['updated_at'])) ?></td>
            <td>
              <?php if ($canManage): ?>
                <?php /* Confirmed before it happens, the convention every
                         destructive action in this CMS follows. Deleting a
                         post never deletes its featured image: that belongs
                         to the Media Library. */ ?>
                <form method="post" action="/api/admin/delete-blog-post.php" class="admin-inline-form" onsubmit="return confirm('Dit blogbericht definitief verwijderen? De afbeeldingen blijven in de mediabibliotheek staan.');">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $postId ?>">
                  <button type="submit" class="admin-btn-text admin-btn-text--danger">Verwijderen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
