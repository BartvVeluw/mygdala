<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_tabs.php';
require_once __DIR__ . '/_publication_fields.php';
require_once __DIR__ . '/_admin_collapse.php';
require_once __DIR__ . '/_content_blocks.php';

use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Service\AdminAuth;
use App\Service\Articles\ArticleContent;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleLocalization;
use App\Service\Articles\ArticleSeo;
use App\Service\Articles\ArticleService;
use App\Service\Articles\ArticleSlug;
use App\Service\Articles\ArticleUrls;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;
use App\Service\Publishing\PublishingClock;

/**
 * One article's editor (ARTICLES.md): four tabs, one form for the article.
 *
 *   Algemeen    title, address and intro in ONE website language; the
 *               featured image, the byline and the topic (language-neutral)
 *   Inhoud      the article's content blocks: the ordinary block list of its
 *               content page (content_blocks_owner_panel(), as on a product,
 *               a project and a blog post). There is no other body
 *   Publicatie  the Publishing Engine's status and date, all four statuses
 *   SEO         SEO title and description in the language being edited,
 *               noindex, and a preview of the search result
 *
 * ONE FORM FOR THE ARTICLE to api/admin/update-article.php, so no tab can
 * blank another. The block list is outside it (its rows are forms), after
 * the form closes — the arrangement admin/blog-post.php uses. The same
 * primitives as the Blog where they are generic (publication fields, byline,
 * localized fields, tabs, save bar, owner block panel); nothing of the
 * Blog's own (content mode, categories, tags).
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('articles.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$article = is_int($idParam) && $idParam > 0 ? (new ArticleRepository())->find($idParam) : null;

if ($article === null) {
    http_response_code(404);
    exit(admin_t('articles.not_found'));
}

$articleId = (int) $article['id'];
$topics = (new ArticleTopicRepository())->all();
ArticleLocalization::preloadTopics(array_map(static fn (array $topic): int => (int) $topic['id'], $topics));

$errors = $_SESSION['admin_article_errors'] ?? [];
$old = $_SESSION['admin_article_old'] ?? null;
unset($_SESSION['admin_article_errors'], $_SESSION['admin_article_old']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$editingLanguage = admin_localized_language();

// A refused save's own input wins, but only the words of the language it
// was typed in; the neutral columns come back whatever the language.
$word = static function (string $field) use ($old, $articleId, $editingLanguage): string {
    if ($old !== null && ($old['language_code'] ?? null) === $editingLanguage && array_key_exists($field, $old)) {
        return (string) ($old[$field] ?? '');
    }

    return ArticleLocalization::word($articleId, $field, $editingLanguage);
};
$neutral = static function (string $key) use ($old, $article): string {
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($article[$key] ?? '');
};

$status = PublicationStatus::normalize($old !== null ? ($old['status'] ?? '') : $article['status']);
$publishedAtInput = $old !== null ? (string) ($old['published_at'] ?? '') : PublishingClock::forFormInput($article['published_at'] ?? null);
$noindexChecked = $old !== null ? !empty($old['noindex']) : (int) ($article['noindex'] ?? 0) === 1;
$topicValue = (int) $neutral('topic_id');
$featuredMedia = MediaService::find((int) ($article['featured_media_id'] ?? 0));

$isListed = PublicationVisibility::isListed($article['status'], $article['published_at']);
$isReachable = PublicationVisibility::isReachable($article['status'], $article['published_at']);
$isPending = PublicationVisibility::isPending($article['status'], $article['published_at']);
$livePath = $isReachable ? ArticleContent::path($articleId, $editingLanguage) : null;
$isDefaultLanguage = $editingLanguage === ArticleLocalization::defaultLanguage();

$name = ArticleLocalization::name($articleId);
$seoTitle = trim(ArticleLocalization::word($articleId, ArticleLocalization::META_TITLE, $editingLanguage));
$seoDescription = trim(ArticleLocalization::word($articleId, ArticleLocalization::META_DESCRIPTION, $editingLanguage));
$titleForPreview = ArticleLocalization::word($articleId, ArticleLocalization::TITLE, $editingLanguage);
$previewTitle = $seoTitle !== '' ? $seoTitle : ($titleForPreview . ' | ' . \App\Service\Language\SiteText::pick(ArticleSeo::LISTING_TITLE, $editingLanguage));
$previewDescription = $seoDescription !== '' ? $seoDescription : ArticleLocalization::word($articleId, ArticleLocalization::EXCERPT, $editingLanguage);
$slugNow = ArticleLocalization::slug($articleId, $editingLanguage);

$canManageBlocks = \App\Service\ContentOwners\ContentBlockAccess::canManageKind(ArticleContentOwner::KIND);
$missing = $status === PublicationStatus::DRAFT ? ArticleService::publishErrors($articleId) : [];

$forcedTab = ($_GET['tab'] ?? '') === 'inhoud' ? 'inhoud' : ($errors !== [] ? 'algemeen' : null);
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($name !== '' ? $name : '#' . $articleId) ?> <?= admin_te('articles.admin_suffix') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/articles.php"><?= admin_te('articles.back') ?></a></p>
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= $h($name !== '' ? $name : '#' . $articleId) ?></h1>
      <p class="admin-page-head__desc">
        <?php if ($isListed): ?>
          <?= admin_te('publishing.state.listed') ?>
        <?php elseif ($isReachable): ?>
          <?= admin_te('publishing.state.archived') ?>
        <?php elseif ($isPending): ?>
          <?= $h(admin_t('publishing.state.pending', ['date' => PublishingClock::forAdmin($article['published_at'])])) ?>
        <?php else: ?>
          <?= admin_te('articles.state_draft') ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($livePath !== null): ?>
      <a href="<?= $h($livePath) ?>" class="admin-btn-secondary" target="_blank" rel="noopener"><?= admin_te('articles.view') ?> &#8594;</a>
    <?php endif; ?>
  </header>

  <?php if (isset($_GET['created'])): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('articles.created') ?></p>
  <?php endif; ?>
  <?php if (isset($_GET['updated'])): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('articles.saved') ?></p>
  <?php endif; ?>
  <?= admin_publication_flash() ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php admin_localized_bar($editingLanguage); ?>

  <?php admin_tabs_start('article-editor', [
      'algemeen' => admin_t('tabs.general'),
      'inhoud' => admin_t('tabs.content'),
      'publicatie' => admin_t('tabs.publication'),
      'seo' => admin_t('tabs.seo'),
  ], [
      'scope' => (string) $articleId,
      'label' => admin_t('articles.tabs_label'),
      'force' => $forcedTab,
  ]); ?>

  <form method="post" action="/api/admin/update-article.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $articleId ?>">
    <?= admin_localized_input($editingLanguage) ?>

    <?php admin_tab_panel('algemeen'); ?>
    <section class="admin-card">
      <h2><?= admin_te('articles.words') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('common.title') ?><?= $isDefaultLanguage ? '*' : '' ?>
          <input type="text" name="title" maxlength="<?= ArticleLocalization::MAX_TITLE_LENGTH ?>"<?= $isDefaultLanguage ? ' required' : '' ?> value="<?= $h($word(ArticleLocalization::TITLE)) ?>">
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('articles.slug') ?><?= $isDefaultLanguage ? '*' : '' ?>
          <input type="text" name="slug" maxlength="<?= ArticleSlug::MAX_LENGTH ?>"<?= $isDefaultLanguage ? ' required' : '' ?> value="<?= $h($word(ArticleLocalization::SLUG)) ?>">
        </label>
      </div>
      <p class="admin-text-muted">
        <?php if ($slugNow === null): ?>
          <?= admin_te($isDefaultLanguage ? 'articles.slug_help_default' : 'articles.slug_help_translation') ?>
        <?php else: ?>
          <?= admin_te('articles.address') ?> <code><?= $h(ArticleUrls::articlePath($slugNow, $editingLanguage)) ?></code>. <?= admin_te('articles.slug_redirect_help') ?>
        <?php endif; ?>
      </p>
      <div class="admin-form-row">
        <label><?= admin_te('articles.excerpt') ?>
          <textarea name="excerpt" rows="3" maxlength="<?= ArticleLocalization::MAX_EXCERPT_LENGTH ?>"><?= $h($word(ArticleLocalization::EXCERPT)) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('articles.excerpt_help') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('articles.featured_image') ?></h2>
      <?php media_picker_field(
          'featured_media_id',
          $featuredMedia,
          admin_t('articles.featured_image'),
          admin_t('articles.featured_image_help'),
          true
      ); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('articles.byline_topic') ?></h2>
      <?= admin_publication_byline($neutral('author_name'), ArticleService::MAX_AUTHOR_LENGTH, admin_t('articles.byline'), admin_t('articles.byline_placeholder')) ?>
      <p class="admin-text-muted"><?= admin_te('articles.byline_help') ?></p>
      <div class="admin-form-row">
        <label><?= admin_te('articles.topic') ?>
          <select name="topic_id">
            <option value="0"><?= admin_te('articles.no_topic') ?></option>
            <?php foreach ($topics as $topic): ?>
              <option value="<?= (int) $topic['id'] ?>" <?= $topicValue === (int) $topic['id'] ? 'selected' : '' ?>><?= $h(ArticleLocalization::topicName((int) $topic['id'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php if ($topics === []): ?>
        <p class="admin-text-muted"><?= admin_te('articles.no_topics_yet') ?> <a href="/admin/article-topics.php"><?= admin_te('articles.manage_topics') ?></a></p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('articles.save') ?></button>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('publicatie'); ?>
    <section class="admin-card">
      <h2><?= admin_te('tabs.publication') ?></h2>
      <?= admin_publication_fields([
          'statuses' => PublicationStatus::ALL,
          'status' => $status,
          'published_at' => $publishedAtInput,
      ]) ?>
      <p class="admin-text-muted"><?= admin_te('publishing.help') ?></p>
      <?php if ($missing !== []): ?>
        <div class="admin-alert admin-alert--note">
          <p><?= admin_te('articles.publish_needs') ?></p>
          <ul class="admin-error-list">
            <?php foreach ($missing as $reason): ?>
              <li><?= $h($reason) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </section>
    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('articles.save') ?></button>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('seo'); ?>
    <section class="admin-card">
      <h2><?= admin_te('tabs.seo') ?></h2>
      <div class="admin-form-row">
        <label><?= admin_te('page.meta_title') ?>
          <input type="text" name="meta_title" maxlength="<?= ArticleLocalization::MAX_META_TITLE_LENGTH ?>" value="<?= $h($word(ArticleLocalization::META_TITLE)) ?>">
        </label>
      </div>
      <div class="admin-form-row">
        <label><?= admin_te('page.meta_description') ?>
          <textarea name="meta_description" rows="3" maxlength="<?= ArticleLocalization::MAX_META_DESCRIPTION_LENGTH ?>"><?= $h($word(ArticleLocalization::META_DESCRIPTION)) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_te('articles.seo_help') ?></p>

      <h3 class="admin-seo-lang__title"><?= admin_te('articles.seo_preview') ?></h3>
      <div class="admin-seo-preview">
        <div class="admin-seo-preview__url"><?= $h($slugNow === null ? '—' : ArticleUrls::article($slugNow, $editingLanguage)) ?></div>
        <div class="admin-seo-preview__title"><?= $h($previewTitle) ?></div>
        <div class="admin-seo-preview__description"><?= $h($previewDescription) ?></div>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('articles.visibility') ?></h3>
      <input type="hidden" name="noindex" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" class="admin-checkbox" name="noindex" value="1" <?= $noindexChecked ? 'checked' : '' ?>>
        <?= admin_te('articles.noindex') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('articles.noindex_help') ?></p>
    </section>
    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('articles.save') ?></button>
    </section>
    <?php admin_tab_panel_end(); ?>
  </form>

  <?php /* Inhoud: outside the article's form, because the block list is forms of its own. */ ?>
  <?php admin_tab_panel('inhoud'); ?>
  <?php if ($canManageBlocks): ?>
    <?php content_blocks_owner_panel(ArticleContentOwner::KIND, $articleId, $csrfToken); ?>
  <?php endif; ?>
  <?php admin_tab_panel_end(); ?>

  <?php admin_tabs_end(); ?>

  <?php if ($canManageBlocks): ?>
    <?php content_blocks_owner_modals(ArticleContentOwner::KIND, $articleId, $csrfToken); ?>
  <?php else: ?>
    <?= admin_confirm_dialog() ?>
  <?php endif; ?>
</main>

<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<?php admin_tabs_script(); ?>
<?php admin_collapse_script(); ?>
<?php content_blocks_scripts(); ?>
</body>
</html>
