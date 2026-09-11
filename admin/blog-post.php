<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_media_picker.php';
require_once __DIR__ . '/_language_fields.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_richtext_field.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_admin_tabs.php';

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Service\AdminAuth;
use App\Service\Blog\BlogClock;
use App\Service\Blog\BlogPostService;
use App\Service\Blog\BlogPostStatus;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogUrls;
use App\Service\Csrf;
use App\Service\Media\MediaService;

/**
 * One blog post's editor: three tabs over ONE form.
 *
 *   Inhoud      title, excerpt and body in both languages, the featured
 *               image, the categories and the tags
 *   Publicatie  status, publication moment, author, and the post's URL
 *   SEO         SEO title, meta description, indexability, the social image
 *               and a preview of the search result
 *
 * ONE FORM ACROSS THREE PANELS, deliberately — the same arrangement
 * admin/page.php uses for Pagina and SEO. api/admin/update-blog-post.php
 * reads the whole post from one request, so splitting the tabs into three
 * forms would turn every save into a partial POST that blanks whatever the
 * editor was not looking at. Every panel therefore ends in the same "Bericht
 * opslaan", and either one saves all three.
 *
 * The tab strip itself is navigation only (admin/_admin_tabs.php): with
 * JavaScript off the three panels are simply a long page, and the save bar
 * (admin/_save_bar.php) watches the same form it always would.
 *
 * The body is the shared rich-text field (admin/_richtext_field.php) — a
 * plain textarea that Quill enhances — and the server sanitises what arrives
 * whichever of the two produced it. V1 gives a post a rich-text body rather
 * than a content-block layout on purpose (BLOG.md).
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('blog.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit(admin_t('screen.blogbericht_gevonden'));
}

$repository = new BlogPostRepository();
$post = $repository->find($idParam);

if ($post === null) {
    http_response_code(404);
    exit(admin_t('screen.blogbericht_gevonden'));
}

$postId = (int) $post['id'];

$categories = (new BlogCategoryRepository())->all();
$selectedCategoryIds = $repository->categoryIdsFor($postId);
$tagLine = BlogPostService::tagLine($repository->tagsForPosts([$postId])[$postId] ?? []);

$errors = $_SESSION['admin_blog_post_errors'] ?? [];
$old = $_SESSION['admin_blog_post_old'] ?? null;
unset($_SESSION['admin_blog_post_errors'], $_SESSION['admin_blog_post_old']);

$created = isset($_GET['created']);
$updated = isset($_GET['updated']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** Value precedence: a rejected save's own input, then what is stored. */
$fieldValue = static function (string $key) use ($old, $post): string {
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) ($old[$key] ?? '');
    }

    return (string) ($post[$key] ?? '');
};

$status = $old !== null ? BlogPostStatus::normalize($old['status'] ?? '') : BlogPostStatus::normalize($post['status']);
$publishedAtInput = $old !== null
    ? (string) ($old['published_at'] ?? '')
    : BlogClock::forFormInput($post['published_at'] ?? null);
$noindexChecked = $old !== null ? !empty($old['noindex']) : (int) ($post['noindex'] ?? 0) === 1;
$tagValue = $old !== null ? (string) ($old['tags'] ?? '') : $tagLine;
$checkedCategoryIds = $old !== null
    ? array_map('intval', (array) ($old['categories'] ?? []))
    : $selectedCategoryIds;

$featuredMedia = MediaService::find((int) ($post['featured_media_id'] ?? 0));
$socialMedia = MediaService::find((int) ($post['og_media_id'] ?? 0));

// The search-result preview shows what this post's head will really contain,
// resolved by the same App\Service\Blog\BlogSeo the public page uses — never
// a second guess at the fallback rules. Built from the STORED row, so it
// shows what is live rather than what is half-typed.
$seoPreview = BlogSeo::forPost($post);

$isPublic = BlogPostStatus::isPublic($post);
$isPending = BlogPostStatus::isPending($post);

/** A rejected save puts its messages above the fields on Inhoud. */
$forcedTab = $errors !== [] ? 'inhoud' : null;
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $post['title']) ?> <?= admin_te('blog.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/blog.php"><?= admin_t('blog.terug_blogberichten') ?></a></p>
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= $h((string) $post['title']) ?></h1>
      <p class="admin-page-head__desc">
        <?php if ($isPublic): ?>
          <?= admin_te('blog.post_is_online') ?>
        <?php elseif ($isPending): ?>
          <?= admin_t('blog.scheduled_for', ['v1' => $h(BlogClock::forAdmin($post['published_at']))]) ?>
        <?php else: ?>
          <?= admin_te('blog.post_is_draft') ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($isPublic): ?>
      <a href="<?= $h(BlogUrls::postPath((string) $post['slug'])) ?>" class="admin-btn-secondary" target="_blank" rel="noopener">Bekijk bericht &#8594;</a>
    <?php endif; ?>
  </header>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('blog.bericht_aangemaakt_concept_schrijf') ?></p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('blog.bericht_opgeslagen') ?></p>
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

  <?php admin_tabs_start('blog-post-editor', [
      'inhoud' => admin_t('tabs.content'),
      'publicatie' => admin_t('tabs.publication'),
      'seo' => admin_t('tabs.seo'),
  ], [
      'scope' => (string) $postId,
      'label' => admin_t('blog.tabs_label'),
      'force' => $forcedTab,
  ]); ?>

  <form method="post" action="/api/admin/update-blog-post.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $postId ?>">

    <?php admin_tab_panel('inhoud'); ?>
    <section class="admin-card">
      <h2><?= admin_te('blog.tekst') ?></h2>
      <p class="admin-text-muted"><?= admin_te('blog.nederlands_inhoud_laat_engels') ?></p>

      <?php admin_lang_bar(); ?>
      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('common.title') ?>*
          <input type="text" name="title" maxlength="<?= BlogPostService::MAX_TITLE_LENGTH ?>" <?= admin_lang_required('nl') ?> value="<?= $h($fieldValue('title')) ?>">
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('common.title') ?>
          <input type="text" name="title_en" maxlength="<?= BlogPostService::MAX_TITLE_LENGTH ?>" value="<?= $h($fieldValue('title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <?php admin_lang_pane_start('nl'); ?>
        <label><?= admin_te('blog.samenvatting') ?>
          <textarea name="excerpt" rows="3" maxlength="<?= BlogPostService::MAX_EXCERPT_LENGTH ?>"><?= $h($fieldValue('excerpt')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
        <label><?= admin_te('blog.samenvatting_2') ?>
          <textarea name="excerpt_en" rows="3" maxlength="<?= BlogPostService::MAX_EXCERPT_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($fieldValue('excerpt_en')) ?></textarea>
        </label>
        <?php admin_lang_pane_end(); ?>
      </div>
      <p class="admin-text-muted"><?= admin_te('blog.samenvatting_staat_overzicht_rss') ?></p>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('blog.bericht') ?></h2>
      <?php admin_lang_pane_start('nl'); ?>
        <?php renderRichTextField('body', 'Tekst', $fieldValue('body'), 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>
      <?php admin_lang_pane_start('en'); ?>
        <?php renderRichTextField('body_en', 'Tekst', $fieldValue('body_en'), 'full', 'admin-richtext-editor--lg'); ?>
      <?php admin_lang_pane_end(); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('blog.uitgelichte_afbeelding') ?></h2>
      <?php media_picker_field(
          'featured_media_id',
          $featuredMedia,
          'Uitgelichte afbeelding',
          'Staat bovenaan het bericht en op de kaart in het overzicht. De alt-tekst en de afmetingen komen uit de mediabibliotheek.',
          true
      ); ?>
    </section>

    <section class="admin-card">
      <h2><?= admin_te('blog.categorie_n_tags') ?></h2>
      <?php if ($categories === []): ?>
        <p class="admin-text-muted"><?= admin_t('blog.er_categorie_n_maak') ?></p>
      <?php else: ?>
        <?php /* The same fieldset + checkbox markup the user form uses for
                 permissions — one shape for "tick as many as apply". */ ?>
        <fieldset class="admin-permission-group">
          <legend><?= admin_te('blog.categorie_n') ?></legend>
          <?php foreach ($categories as $category): ?>
            <label class="admin-checkbox-label admin-permission-option">
              <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $checkedCategoryIds, true) ? 'checked' : '' ?>>
              <span>
                <strong><?= $h((string) $category['name']) ?></strong>
                <?php if ((int) $category['is_active'] !== 1): ?>
                  <span class="admin-text-muted"><?= admin_t('blog.category_inactive') ?></span>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <p class="admin-text-muted"><?= admin_te('blog.bericht_mag_meerdere_categorie') ?></p>
      <?php endif; ?>

      <div class="admin-form-row">
        <label><?= admin_te('blog.tags') ?>
          <input type="text" name="tags" value="<?= $h($tagValue) ?>" placeholder="graveren, hout, cadeau">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('blog.gescheiden_door_komma_s', ['v1' => BlogPostService::MAX_TAGS_PER_POST]) ?></p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('blog.bericht_opslaan') ?></button>
      <p class="admin-text-muted"><?= admin_t('blog.slaat_alle_drie_tabbladen') ?></p>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('publicatie'); ?>
    <section class="admin-card">
      <h2><?= admin_te('blog.publicatie') ?></h2>

      <div class="admin-form-row admin-form-row--split">
        <label><?= admin_te('common.status') ?>
          <select name="status">
            <?php foreach (array_keys(BlogPostStatus::LABELS) as $statusKey): ?><?php $statusLabel = BlogPostStatus::label($statusKey); ?>
              <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= $h($statusLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= admin_te('blog.publicatiedatum_tijd') ?>
          <input type="datetime-local" name="published_at" value="<?= $h($publishedAtInput) ?>">
        </label>
      </div>
      <p class="admin-text-muted">
        <strong><?= admin_t('blog.concept_nooit_zichtbaar_gepubliceerd') ?>
      </p>

      <div class="admin-form-row">
        <label><?= admin_te('blog.auteur') ?>
          <input type="text" name="author_name" maxlength="<?= BlogPostService::MAX_AUTHOR_LENGTH ?>" value="<?= $h($fieldValue('author_name')) ?>" placeholder="Laat leeg voor geen auteursregel">
        </label>
      </div>
      <p class="admin-text-muted"><?= admin_t('blog.naam_onder_bericht_staat', ['v1' => BlogSettings::showAuthor() ? admin_t('blog.author_lines_on') : admin_t('blog.author_lines_off')]) ?></p>

      <div class="admin-form-row">
        <label><?= admin_te('blog.url_slug') ?>*
          <input type="text" name="slug" maxlength="<?= \App\Service\Blog\BlogSlug::MAX_LENGTH ?>" required value="<?= $h($fieldValue('slug')) ?>">
        </label>
      </div>
      <p class="admin-text-muted">
        <?= admin_te('blog.live') ?> <a href="<?= $h(BlogUrls::postPath((string) $post['slug'])) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::postPath((string) $post['slug'])) ?></a><?= admin_te('blog.wijzig_slug_bericht_al') ?>
      </p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('blog.bericht_opslaan_2') ?></button>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('seo'); ?>
    <section class="admin-card">
      <h2><?= admin_te('blog.seo') ?></h2>
      <p class="admin-text-muted"><?= admin_t('blog.seo_title_fallback', ['blog' => $h(BlogSettings::title('nl')), 'site' => $h(\App\Service\SiteSettings::get('site_name'))]) ?></p>

      <div class="admin-product-form admin-product-form--wide">
        <?php admin_lang_pane_start('nl'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title" maxlength="<?= BlogPostService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title')) ?>">
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description" rows="3" maxlength="<?= BlogPostService::MAX_META_DESCRIPTION_LENGTH ?>"><?= $h($fieldValue('meta_description')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
        <?php admin_lang_pane_start('en'); ?>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_title') ?>
              <input type="text" name="meta_title_en" maxlength="<?= BlogPostService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title_en')) ?>"<?= admin_lang_placeholder_attr('en') ?>>
            </label>
          </div>
          <div class="admin-form-row">
            <label><?= admin_te('page.meta_description') ?>
              <textarea name="meta_description_en" rows="3" maxlength="<?= BlogPostService::MAX_META_DESCRIPTION_LENGTH ?>"<?= admin_lang_placeholder_attr('en') ?>><?= $h($fieldValue('meta_description_en')) ?></textarea>
            </label>
          </div>
        <?php admin_lang_pane_end(); ?>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('blog.voorbeeld_google') ?></h3>
      <p class="admin-text-muted"><?= admin_te('blog.titel_tekst_nu_opgeslagen') ?></p>
      <div class="admin-seo-preview">
        <div class="admin-seo-preview__url"><?= $h((string) ($seoPreview->canonical ?? BlogUrls::post((string) $post['slug']))) ?></div>
        <div class="admin-seo-preview__title"><?= $h($seoPreview->titleNl) ?></div>
        <div class="admin-seo-preview__description">
          <?php if ($seoPreview->hasDescription()): ?>
            <?= $h($seoPreview->descriptionNl) ?>
          <?php else: ?>
            <em><?= admin_t('blog.no_description_no_summary') ?></em>
          <?php endif; ?>
        </div>
      </div>

      <h3 class="admin-seo-lang__title"><?= admin_te('blog.zichtbaarheid') ?></h3>
      <?php /* Hidden companion field: an unticked checkbox sends nothing, so
               without it "niet indexeren" could be switched on but never off.
               PHP keeps the last value for a repeated name, so ticking wins. */ ?>
      <input type="hidden" name="noindex" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="noindex" value="1" <?= $noindexChecked ? 'checked' : '' ?>>
        <?= admin_te('blog.bericht_laten_indexeren_door') ?>
      </label>
      <p class="admin-text-muted"><?= admin_te('blog.bericht_blijft_gewoon_bereikbaar') ?></p>

      <h3 class="admin-seo-lang__title"><?= admin_te('blog.deel_afbeelding') ?></h3>
      <?php media_picker_field(
          'og_media_id',
          $socialMedia,
          'Eigen deel-afbeelding (optioneel)',
          'De preview wanneer iemand dit bericht deelt. Laat leeg om de uitgelichte afbeelding te gebruiken, en anders de standaard uit Instellingen. Liggend, bij voorkeur 1200 x 630 pixels.',
          true
      ); ?>
      <?php if ($socialMedia === null && $featuredMedia !== null): ?>
        <p class="admin-text-muted"><?= admin_t('blog.nu_gebruik_uitgelichte_afbeelding', ['v1' => $h($featuredMedia->displayName())]) ?></p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit"><?= admin_te('blog.bericht_opslaan_3') ?></button>
    </section>
    <?php admin_tab_panel_end(); ?>
  </form>

  <?php admin_tabs_end(); ?>
</main>

<?php save_bar(); ?>
<?php media_picker_modal(); ?>
<?php save_bar_script(); ?>
<?php media_picker_script(); ?>
<?php admin_tabs_script(); ?>
<?php admin_lang_script(); ?>
</body>
</html>
