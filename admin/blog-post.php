<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_media_picker.php';
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
    exit('Blogbericht niet gevonden.');
}

$repository = new BlogPostRepository();
$post = $repository->find($idParam);

if ($post === null) {
    http_response_code(404);
    exit('Blogbericht niet gevonden.');
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
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h((string) $post['title']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.snow.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/vendor/quill/quill.min.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/blog.php">&larr; Terug naar blogberichten</a></p>
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= $h((string) $post['title']) ?></h1>
      <p class="admin-page-head__desc">
        <?php if ($isPublic): ?>
          Dit bericht staat online.
        <?php elseif ($isPending): ?>
          Ingepland: dit bericht verschijnt vanzelf op <?= $h(BlogClock::forAdmin($post['published_at'])) ?>.
        <?php else: ?>
          Concept: dit bericht is nergens publiek zichtbaar.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($isPublic): ?>
      <a href="<?= $h(BlogUrls::postPath((string) $post['slug'])) ?>" class="admin-btn-secondary" target="_blank" rel="noopener">Bekijk bericht &#8594;</a>
    <?php endif; ?>
  </header>

  <?php if ($created): ?>
    <p class="admin-alert admin-alert--success">Bericht aangemaakt als concept. Schrijf het hieronder en publiceer het via het tabblad Publicatie.</p>
  <?php endif; ?>
  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Bericht opgeslagen.</p>
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
      'inhoud' => 'Inhoud',
      'publicatie' => 'Publicatie',
      'seo' => 'SEO',
  ], [
      'scope' => (string) $postId,
      'label' => 'Onderdelen van dit bericht',
      'force' => $forcedTab,
  ]); ?>

  <form method="post" action="/api/admin/update-blog-post.php">
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $postId ?>">

    <?php admin_tab_panel('inhoud'); ?>
    <section class="admin-card">
      <h2>Tekst</h2>
      <p class="admin-text-muted">Nederlands is de inhoud; laat je een Engels veld leeg, dan toont de site daar de Nederlandse tekst.</p>

      <div class="admin-form-row admin-form-row--split">
        <label>Titel (NL)*
          <input type="text" name="title" maxlength="<?= BlogPostService::MAX_TITLE_LENGTH ?>" required value="<?= $h($fieldValue('title')) ?>">
        </label>
        <label>Titel (EN)
          <input type="text" name="title_en" maxlength="<?= BlogPostService::MAX_TITLE_LENGTH ?>" value="<?= $h($fieldValue('title_en')) ?>" placeholder="Leeg = Nederlandse titel">
        </label>
      </div>

      <div class="admin-form-row admin-form-row--split">
        <label>Samenvatting (NL)
          <textarea name="excerpt" rows="3" maxlength="<?= BlogPostService::MAX_EXCERPT_LENGTH ?>"><?= $h($fieldValue('excerpt')) ?></textarea>
        </label>
        <label>Samenvatting (EN)
          <textarea name="excerpt_en" rows="3" maxlength="<?= BlogPostService::MAX_EXCERPT_LENGTH ?>" placeholder="Leeg = Nederlandse tekst"><?= $h($fieldValue('excerpt_en')) ?></textarea>
        </label>
      </div>
      <p class="admin-text-muted">De samenvatting staat in het overzicht en in de RSS-feed. Laat je 'm leeg, dan wordt automatisch het begin van de tekst gebruikt.</p>
    </section>

    <section class="admin-card">
      <h2>Bericht</h2>
      <?php renderRichTextField('body', 'Tekst (NL)', $fieldValue('body'), 'full', 'admin-richtext-editor--lg'); ?>
      <?php renderRichTextField('body_en', 'Tekst (EN)', $fieldValue('body_en'), 'full', 'admin-richtext-editor--lg'); ?>
    </section>

    <section class="admin-card">
      <h2>Uitgelichte afbeelding</h2>
      <?php media_picker_field(
          'featured_media_id',
          $featuredMedia,
          'Uitgelichte afbeelding',
          'Staat bovenaan het bericht en op de kaart in het overzicht. De alt-tekst en de afmetingen komen uit de mediabibliotheek.',
          true
      ); ?>
    </section>

    <section class="admin-card">
      <h2>Categorieën en tags</h2>
      <?php if ($categories === []): ?>
        <p class="admin-text-muted">Er zijn nog geen categorieën. Maak ze aan bij <a href="/admin/blog-categories.php">Blogcategorieën</a>.</p>
      <?php else: ?>
        <?php /* The same fieldset + checkbox markup the user form uses for
                 permissions — one shape for "tick as many as apply". */ ?>
        <fieldset class="admin-permission-group">
          <legend>Categorieën</legend>
          <?php foreach ($categories as $category): ?>
            <label class="admin-checkbox-label admin-permission-option">
              <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $checkedCategoryIds, true) ? 'checked' : '' ?>>
              <span>
                <strong><?= $h((string) $category['name']) ?></strong>
                <?php if ((int) $category['is_active'] !== 1): ?>
                  <span class="admin-text-muted">Inactief &mdash; het archief van deze categorie is niet publiek bereikbaar.</span>
                <?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <p class="admin-text-muted">Een bericht mag in meerdere categorieën staan. De eerste in de volgorde van Blogcategorieën is degene die op de kaart getoond wordt.</p>
      <?php endif; ?>

      <div class="admin-form-row">
        <label>Tags
          <input type="text" name="tags" value="<?= $h($tagValue) ?>" placeholder="graveren, hout, cadeau">
        </label>
      </div>
      <p class="admin-text-muted">Gescheiden door komma's, maximaal <?= BlogPostService::MAX_TAGS_PER_POST ?>. Een tag die nog niet bestaat wordt aangemaakt; een die al bestaat wordt hergebruikt, ook als je 'm net iets anders schrijft.</p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit">Bericht opslaan</button>
      <p class="admin-text-muted">Slaat alle drie de tabbladen op &mdash; het is één formulier.</p>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('publicatie'); ?>
    <section class="admin-card">
      <h2>Publicatie</h2>

      <div class="admin-form-row admin-form-row--split">
        <label>Status
          <select name="status">
            <?php foreach (BlogPostStatus::LABELS as $statusKey => $statusLabel): ?>
              <option value="<?= $h($statusKey) ?>" <?= $status === $statusKey ? 'selected' : '' ?>><?= $h($statusLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Publicatiedatum en -tijd
          <input type="datetime-local" name="published_at" value="<?= $h($publishedAtInput) ?>">
        </label>
      </div>
      <p class="admin-text-muted">
        <strong>Concept</strong> is nooit zichtbaar. <strong>Gepubliceerd</strong> zonder datum betekent: nu.
        <strong>Ingepland</strong> verschijnt vanzelf zodra de datum bereikt is &mdash; daar draait niets voor op de achtergrond,
        de server kijkt gewoon naar de klok bij elk bezoek.
      </p>

      <div class="admin-form-row">
        <label>Auteur
          <input type="text" name="author_name" maxlength="<?= BlogPostService::MAX_AUTHOR_LENGTH ?>" value="<?= $h($fieldValue('author_name')) ?>" placeholder="Laat leeg voor geen auteursregel">
        </label>
      </div>
      <p class="admin-text-muted">De naam die onder het bericht staat. <?= BlogSettings::showAuthor() ? 'Auteursregels staan aan bij Bloginstellingen.' : 'Let op: auteursregels staan uit bij Bloginstellingen, dus deze naam wordt nu nergens getoond.' ?></p>

      <div class="admin-form-row">
        <label>URL (slug)*
          <input type="text" name="slug" maxlength="<?= \App\Service\Blog\BlogSlug::MAX_LENGTH ?>" required value="<?= $h($fieldValue('slug')) ?>">
        </label>
      </div>
      <p class="admin-text-muted">
        Live op <a href="<?= $h(BlogUrls::postPath((string) $post['slug'])) ?>" target="_blank" rel="noopener"><?= $h(BlogUrls::postPath((string) $post['slug'])) ?></a>.
        Wijzig je de slug van een bericht dat al online staat, dan blijft de oude URL werken via een automatische redirect.
      </p>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit">Bericht opslaan</button>
    </section>
    <?php admin_tab_panel_end(); ?>

    <?php admin_tab_panel('seo'); ?>
    <section class="admin-card">
      <h2>SEO</h2>
      <p class="admin-text-muted">Laat de SEO-titel leeg om automatisch "<em>Titel</em> | <?= $h(BlogSettings::title('nl')) ?> &mdash; <?= $h(\App\Service\SiteSettings::get('site_name')) ?>" te gebruiken.</p>

      <div class="admin-product-form admin-product-form--wide">
        <div class="admin-seo-grid">
          <div class="admin-seo-lang">
            <h3 class="admin-seo-lang__title">Nederlands</h3>
            <div class="admin-form-row">
              <label>SEO-titel (NL)
                <input type="text" name="meta_title" maxlength="<?= BlogPostService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title')) ?>">
              </label>
            </div>
            <div class="admin-form-row">
              <label>Meta description (NL)
                <textarea name="meta_description" rows="3" maxlength="<?= BlogPostService::MAX_META_DESCRIPTION_LENGTH ?>"><?= $h($fieldValue('meta_description')) ?></textarea>
              </label>
            </div>
          </div>
          <div class="admin-seo-lang">
            <h3 class="admin-seo-lang__title">English</h3>
            <div class="admin-form-row">
              <label>SEO-titel (EN)
                <input type="text" name="meta_title_en" maxlength="<?= BlogPostService::MAX_META_TITLE_LENGTH ?>" value="<?= $h($fieldValue('meta_title_en')) ?>" placeholder="Leeg = Nederlandse titel">
              </label>
            </div>
            <div class="admin-form-row">
              <label>Meta description (EN)
                <textarea name="meta_description_en" rows="3" maxlength="<?= BlogPostService::MAX_META_DESCRIPTION_LENGTH ?>" placeholder="Leeg = Nederlandse tekst"><?= $h($fieldValue('meta_description_en')) ?></textarea>
              </label>
            </div>
          </div>
        </div>
      </div>

      <h3 class="admin-seo-lang__title">Voorbeeld in Google</h3>
      <p class="admin-text-muted">Met de titel en tekst die nu zijn opgeslagen. Laat je de meta description leeg, dan wordt de samenvatting gebruikt.</p>
      <div class="admin-seo-preview">
        <div class="admin-seo-preview__url"><?= $h((string) ($seoPreview->canonical ?? BlogUrls::post((string) $post['slug']))) ?></div>
        <div class="admin-seo-preview__title"><?= $h($seoPreview->titleNl) ?></div>
        <div class="admin-seo-preview__description">
          <?php if ($seoPreview->hasDescription()): ?>
            <?= $h($seoPreview->descriptionNl) ?>
          <?php else: ?>
            <em>Geen meta description en geen samenvatting &mdash; Google kiest dan zelf een stukje tekst.</em>
          <?php endif; ?>
        </div>
      </div>

      <h3 class="admin-seo-lang__title">Zichtbaarheid</h3>
      <?php /* Hidden companion field: an unticked checkbox sends nothing, so
               without it "niet indexeren" could be switched on but never off.
               PHP keeps the last value for a repeated name, so ticking wins. */ ?>
      <input type="hidden" name="noindex" value="0">
      <label class="admin-checkbox-label">
        <input type="checkbox" name="noindex" value="1" <?= $noindexChecked ? 'checked' : '' ?>>
        Dit bericht niet laten indexeren door zoekmachines
      </label>
      <p class="admin-text-muted">Het bericht blijft gewoon bereikbaar en verdwijnt alleen uit de sitemap en uit de zoekresultaten. In de RSS-feed blijft het staan: wie zich op de blog heeft geabonneerd heeft om alle berichten gevraagd.</p>

      <h3 class="admin-seo-lang__title">Deel-afbeelding</h3>
      <?php media_picker_field(
          'og_media_id',
          $socialMedia,
          'Eigen deel-afbeelding (optioneel)',
          'De preview wanneer iemand dit bericht deelt. Laat leeg om de uitgelichte afbeelding te gebruiken, en anders de standaard uit Instellingen. Liggend, bij voorkeur 1200 x 630 pixels.',
          true
      ); ?>
      <?php if ($socialMedia === null && $featuredMedia !== null): ?>
        <p class="admin-text-muted">Nu in gebruik: de uitgelichte afbeelding (<code><?= $h($featuredMedia->displayName()) ?></code>).</p>
      <?php endif; ?>
    </section>

    <section class="admin-card admin-card--actions">
      <button type="submit">Bericht opslaan</button>
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
</body>
</html>
