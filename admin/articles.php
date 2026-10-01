<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleContent;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleUrls;
use App\Service\Csrf;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\PublishingClock;

/**
 * Beheer → Artikelen (ARTICLES.md): every article whatever its state, a
 * status filter, a topic filter and a title search, all query parameters
 * (bookmarkable, no JavaScript) — the shape of admin/blog.php, the admin
 * table markup every overview uses. One query for the rows, one for their
 * words. Creating starts here with a title, as everywhere in this CMS.
 *
 * The admin sees every row; the public rules (listed/reachable) only colour
 * the badge and the "nog niet zichtbaar" line.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('articles.manage');

$statusFilter = (string) ($_GET['status'] ?? '');
$statusFilter = PublicationStatus::isValid($statusFilter) ? PublicationStatus::normalize($statusFilter) : '';
$topicFilter = (int) ($_GET['topic'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

try {
    $repository = new ArticleRepository();
    $filters = ['status' => $statusFilter, 'topic_id' => $topicFilter];
    if ($search !== '') {
        $filters['title_ids'] = ArticleLocalization::articles()->ownersMatching(ArticleLocalization::TITLE, $search);
    }

    $articles = $repository->findForAdmin($filters);
    ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $articles));
    $counts = $repository->countsByStatus();
    $topics = (new ArticleTopicRepository())->all();
    ArticleLocalization::preloadTopics(array_map(static fn (array $topic): int => (int) $topic['id'], $topics));
    $loadFailed = false;
} catch (\Throwable $e) {
    error_log('[admin/articles.php] ' . $e->getMessage());
    $articles = [];
    $counts = [];
    $topics = [];
    $loadFailed = true;
}

$topicNames = [];
foreach ($topics as $topic) {
    $topicNames[(int) $topic['id']] = ArticleLocalization::topicName((int) $topic['id']);
}

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$flash = $_SESSION['admin_articles_flash'] ?? null;
$errors = $_SESSION['admin_articles_errors'] ?? [];
unset($_SESSION['admin_articles_flash'], $_SESSION['admin_articles_errors']);

$total = array_sum($counts);

$filterUrl = static function (array $overrides) use ($statusFilter, $topicFilter, $search): string {
    $query = array_filter([
        'status' => $overrides['status'] ?? $statusFilter,
        'topic' => (string) ($overrides['topic'] ?? ($topicFilter ?: '')),
        'q' => $overrides['q'] ?? $search,
    ], static fn ($value): bool => (string) $value !== '');

    return '/admin/articles.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

$statusTabs = ['' => admin_t('articles.filter_all')];
foreach (PublicationStatus::ALL as $statusKey) {
    $statusTabs[$statusKey] = admin_t(PublicationStatus::labelKey($statusKey));
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('articles.title_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('articles.title') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('articles.intro') ?></p>
    </div>
    <a href="<?= $h(ArticleUrls::indexPath()) ?>" class="admin-btn-secondary" target="_blank" rel="noopener"><?= admin_te('articles.view_listing') ?> &#8594;</a>
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
    <p class="admin-alert admin-alert--error"><?= admin_te('articles.load_failed') ?></p>
  <?php endif; ?>

  <section class="admin-card">
    <h2><?= admin_te('articles.new') ?></h2>
    <p class="admin-text-muted"><?= admin_te('articles.new_help') ?></p>
    <form method="post" action="/api/admin/create-article.php" class="admin-product-form">
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <label><?= admin_te('articles.new_title') ?>*
        <input type="text" name="title" maxlength="<?= ArticleLocalization::MAX_TITLE_LENGTH ?>" required>
      </label>
      <button type="submit"><?= admin_te('articles.create') ?></button>
    </form>
  </section>

  <section class="admin-card">
    <h2><?= admin_te('articles.filter') ?></h2>
    <nav class="admin-filter-tabs" aria-label="<?= admin_te('articles.filter_status') ?>">
      <?php foreach ($statusTabs as $key => $label): ?>
        <?php $count = $key === '' ? $total : ($counts[$key] ?? 0); ?>
        <a href="<?= $h($filterUrl(['status' => (string) $key])) ?>" class="admin-filter-tab<?= $statusFilter === (string) $key ? ' is-active' : '' ?>"<?= $statusFilter === (string) $key ? ' aria-current="page"' : '' ?>><?= $h((string) $label) ?> (<?= (int) $count ?>)</a>
      <?php endforeach; ?>
    </nav>

    <form method="get" action="/admin/articles.php" class="admin-product-form admin-product-form--wide">
      <?php if ($statusFilter !== ''): ?>
        <input type="hidden" name="status" value="<?= $h($statusFilter) ?>">
      <?php endif; ?>
      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('articles.search') ?>
          <input type="search" name="q" value="<?= $h($search) ?>">
        </label>
        <?php if ($topics !== []): ?>
        <label><?= admin_te('articles.topic') ?>
          <select name="topic">
            <option value=""><?= admin_te('articles.all_topics') ?></option>
            <?php foreach ($topicNames as $topicId => $topicName): ?>
              <option value="<?= (int) $topicId ?>" <?= $topicFilter === (int) $topicId ? 'selected' : '' ?>><?= $h($topicName) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php endif; ?>
      </div>
      <div>
        <button type="submit"><?= admin_te('articles.filter') ?></button>
        <?php if ($statusFilter !== '' || $topicFilter > 0 || $search !== ''): ?>
          <a href="/admin/articles.php" class="admin-btn-text"><?= admin_te('articles.clear_filters') ?></a>
        <?php endif; ?>
      </div>
    </form>
  </section>

  <?php if (!$loadFailed && $articles === []): ?>
    <p><?= $total > 0 ? admin_te('articles.none_for_filter') : admin_te('articles.none') ?></p>
  <?php elseif ($articles !== []): ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th><?= admin_te('common.title') ?></th>
          <th><?= admin_te('common.status') ?></th>
          <th><?= admin_te('articles.published_at') ?></th>
          <th><?= admin_te('articles.topic') ?></th>
          <th><?= admin_te('articles.updated_at') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($articles as $article): ?>
          <?php
            $articleId = (int) $article['id'];
            $status = PublicationStatus::normalize($article['status']);
            $isPending = PublicationVisibility::isPending($article['status'], $article['published_at']);
            $isReachable = PublicationVisibility::isReachable($article['status'], $article['published_at']);
            $livePath = $isReachable ? ArticleContent::path($articleId, ArticleLocalization::defaultLanguage()) : null;
            $badge = match (true) {
                $status === PublicationStatus::DRAFT, $status === PublicationStatus::ARCHIVED => 'muted',
                $isPending => 'pending',
                default => 'info',
            };
            $name = ArticleLocalization::name($articleId);
          ?>
          <tr>
            <td>
              <a href="/admin/article.php?id=<?= $articleId ?>"><?= $h($name !== '' ? $name : '#' . $articleId) ?></a>
              <?php if ($livePath !== null): ?>
                <br><a class="admin-text-muted" href="<?= $h($livePath) ?>" target="_blank" rel="noopener"><?= $h($livePath) ?></a>
              <?php endif; ?>
            </td>
            <td><span class="admin-badge admin-badge--<?= $badge ?>"><?= admin_te(PublicationStatus::labelKey($status)) ?></span></td>
            <td>
              <?php if (PublishingClock::forAdmin($article['published_at']) === ''): ?>
                <span class="admin-text-muted">—</span>
              <?php else: ?>
                <?= $h(PublishingClock::forAdmin($article['published_at'])) ?>
                <?php if ($isPending): ?>
                  <br><span class="admin-text-muted"><?= admin_te('articles.not_visible_yet') ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= isset($topicNames[(int) ($article['topic_id'] ?? 0)]) ? $h($topicNames[(int) $article['topic_id']]) : '<span class="admin-text-muted">—</span>' ?></td>
            <td><?= $h(PublishingClock::forAdmin($article['updated_at'])) ?></td>
            <td>
              <form method="post" action="/api/admin/delete-article.php" class="admin-inline-form"<?= admin_confirm_attributes(admin_t('articles.delete_confirm_title'), admin_t('articles.delete_confirm_text'), admin_t('common.delete')) ?>>
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <input type="hidden" name="id" value="<?= $articleId ?>">
                <button type="submit" class="admin-btn-text admin-btn-text--danger"><?= admin_te('common.delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <?= admin_confirm_dialog() ?>
</main>
</body>
</html>
