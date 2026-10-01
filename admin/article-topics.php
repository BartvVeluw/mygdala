<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleSlug;
use App\Service\Articles\ArticleUrls;
use App\Service\Csrf;

/**
 * Beheer → Artikelonderwerpen (ARTICLES.md, "Onderwerpen"): one flat list,
 * ordered by hand; per topic a name, a description and an address in each
 * website language. Editing one topic happens on this screen (?edit=<id>),
 * in the language of the shell's language switch, like every localized
 * screen (admin/_localized_fields.php).
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('articles.manage');

$repository = new ArticleTopicRepository();
$topics = $repository->all();
ArticleLocalization::preloadTopics(array_map(static fn (array $topic): int => (int) $topic['id'], $topics));

$articleCounts = $repository->articleCounts();

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? $repository->find($editId) : null;
$editingLanguage = admin_localized_language();

$flash = $_SESSION['admin_article_topics_flash'] ?? null;
$errors = $_SESSION['admin_article_topics_errors'] ?? [];
unset($_SESSION['admin_article_topics_flash'], $_SESSION['admin_article_topics_errors']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('articles.topics_title') ?> <?= admin_te('articles.admin_suffix') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('articles.topics_title') ?></h1>
      <p class="admin-page-head__desc"><?= admin_te('articles.topics_intro') ?></p>
    </div>
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

  <?php if ($editing !== null): ?>
    <?php $topicId = (int) $editing['id']; ?>
    <?php admin_localized_bar($editingLanguage); ?>
    <section class="admin-card">
      <h2><?= $h(ArticleLocalization::topicName($topicId)) ?></h2>
      <form method="post" action="/api/admin/save-article-topic.php" class="admin-product-form admin-product-form--wide">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $topicId ?>">
        <?= admin_localized_input($editingLanguage) ?>
        <div class="admin-form-row admin-form-row--split">
          <label><?= admin_te('articles.topic_name') ?>
            <input type="text" name="name" maxlength="<?= ArticleLocalization::MAX_TOPIC_NAME_LENGTH ?>" value="<?= $h(ArticleLocalization::topicWord($topicId, ArticleLocalization::NAME, $editingLanguage)) ?>">
          </label>
          <label><?= admin_te('articles.slug') ?>
            <input type="text" name="slug" maxlength="<?= ArticleSlug::MAX_LENGTH ?>" value="<?= $h((string) ArticleLocalization::topicSlug($topicId, $editingLanguage)) ?>">
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('articles.topic_description') ?>
            <textarea name="description" rows="3" maxlength="<?= ArticleLocalization::MAX_TOPIC_DESCRIPTION_LENGTH ?>"><?= $h(ArticleLocalization::topicWord($topicId, ArticleLocalization::DESCRIPTION, $editingLanguage)) ?></textarea>
          </label>
        </div>
        <div class="admin-form-row">
          <label><?= admin_te('articles.topic_order') ?>
            <input type="number" name="sort_order" min="0" max="9999" value="<?= (int) $editing['sort_order'] ?>">
          </label>
        </div>
        <div>
          <button type="submit"><?= admin_te('common.save') ?></button>
          <a href="/admin/article-topics.php" class="admin-btn-text"><?= admin_te('articles.back_to_topics') ?></a>
        </div>
      </form>
    </section>
  <?php else: ?>
    <section class="admin-card">
      <h2><?= admin_te('articles.topic_new') ?></h2>
      <form method="post" action="/api/admin/save-article-topic.php" class="admin-product-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="id" value="0">
        <label><?= admin_te('articles.topic_name') ?>*
          <input type="text" name="name" maxlength="<?= ArticleLocalization::MAX_TOPIC_NAME_LENGTH ?>" required>
        </label>
        <input type="hidden" name="sort_order" value="<?= count($topics) * 10 ?>">
        <button type="submit"><?= admin_te('articles.topic_create') ?></button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($topics === []): ?>
    <p><?= admin_te('articles.topics_none') ?></p>
  <?php else: ?>
    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?= admin_te('articles.topic_name') ?></th>
            <th><?= admin_te('articles.address') ?></th>
            <th><?= admin_te('articles.topic_articles') ?></th>
            <th><?= admin_te('articles.topic_order') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topics as $topic): ?>
            <?php
              $topicId = (int) $topic['id'];
              $slug = ArticleLocalization::topicSlug($topicId, ArticleLocalization::defaultLanguage());
            ?>
            <tr>
              <td><a href="/admin/article-topics.php?edit=<?= $topicId ?>"><?= $h(ArticleLocalization::topicName($topicId)) ?></a></td>
              <td><?= $slug === null ? '—' : '<code>' . $h(ArticleUrls::topicPath($slug, 1, ArticleLocalization::defaultLanguage())) . '</code>' ?></td>
              <td><?= (int) ($articleCounts[$topicId] ?? 0) ?></td>
              <td><?= (int) $topic['sort_order'] ?></td>
              <td>
                <form method="post" action="/api/admin/delete-article-topic.php" class="admin-inline-form"<?= admin_confirm_attributes(admin_t('articles.topic_delete_title'), admin_t('articles.topic_delete_text'), admin_t('common.delete')) ?>>
                  <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $topicId ?>">
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
