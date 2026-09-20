<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With the Blog switched off the file is
// still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as an
// unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('blog');

require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * The Blog's listing, in three modes behind three URLs (.htaccess):
 *
 *   /blog                    every published post, newest first
 *   /blog/categorie/<slug>   the same, narrowed to one category
 *   /blog/tag/<slug>         the same, narrowed to one tag
 *
 * ONE TEMPLATE FOR THE THREE, for the same reason collectie.php reuses the
 * shop's grid rather than building a second one: an archive IS the listing
 * under a different heading, and a card that looked different depending on
 * how a visitor arrived at it would be a second implementation to keep in
 * step. What differs between the modes is the heading, the intro and the
 * metadata — three small conditionals, not three files.
 *
 * PAGINATION IS SERVER-SIDE AND REAL. The page number is a query parameter,
 * App\Service\Blog\BlogContent asks for exactly one page of rows, and the
 * links between pages are ordinary <a> elements. Nothing here loads every
 * post and hides most of them, and nothing here needs JavaScript: this page
 * has no script of its own at all.
 *
 * An unknown or inactive category, and an unknown tag, are deliberately
 * indistinguishable from a URL that never existed — BlogContent::listing()
 * returns null for all of them and this file renders the project's own 404,
 * exactly like pagina.php does for an unknown slug. An archive that exists
 * but is empty is NOT that case: it renders its heading and says so.
 *
 * Uses root-relative URLs throughout: this template is served from nested
 * paths (/blog/categorie/<slug>), where "assets/..." would 404.
 */

use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogLocalizedSettings;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogUrls;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Redirects\RedirectGate;

$requestedPage = filter_input(INPUT_GET, BlogUrls::PAGE_PARAM, FILTER_VALIDATE_INT);

$listing = BlogContent::listing([
    'category' => (string) ($_GET['category'] ?? ''),
    'tag' => (string) ($_GET['tag'] ?? ''),
    'page' => is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1,
]);

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($listing === null) {
    // About to become a 404, which is the one moment the Redirect Manager may
    // speak: Apache routed this request (the URL matched a Blog rewrite), so
    // its ErrorDocument never fires and 404.php never sees it. The lookup
    // runs AFTER the archive lookup failed, so a redirect can never shadow a
    // category or tag that does exist. Same integration point pagina.php
    // uses — see REDIRECTS.md.
    RedirectGate::handleOr404();

    http_response_code(404);
} else {
    $seoMetadata = match ($listing['mode']) {
        'category' => BlogSeo::forCategory((array) $listing['category'], (int) $listing['page']),
        'tag' => BlogSeo::forTag((array) $listing['tag'], (int) $listing['page']),
        default => BlogSeo::forIndex((int) $listing['page']),
    };

    // What this page is called and what it says about itself, per mode.
    // The listing's own heading and introduction: one pair, each half already
    // resolved per website language (BlogLocalizedSettings). An archive
    // overwrites them with the category's or the tag's own words below.
    $blogTitle = BlogLocalizedSettings::titleValue();
    $blogIntro = BlogLocalizedSettings::introValue();
    $headingNl = $blogTitle->in(LanguageRegistry::DUTCH);
    $headingEn = $blogTitle->in(LanguageRegistry::ENGLISH);
    $introNl = $blogIntro->in(LanguageRegistry::DUTCH);
    $introEn = $blogIntro->in(LanguageRegistry::ENGLISH);
    $eyebrowNl = '';
    $eyebrowEn = '';

    if ($listing['mode'] === 'category') {
        $category = (array) $listing['category'];
        $eyebrowNl = 'Categorie';
        $eyebrowEn = 'Category';
        // An archive's heading and introduction are the category's own words,
        // per website language since Multilingual 2.0 phase 5 wave B. The
        // template still prints the V1 pair; each half already carries the
        // fallback.
        $heading = BlogContent::categoryNameValue($category);
        $headingNl = $heading->in(LanguageRegistry::DUTCH);
        $headingEn = $heading->in(LanguageRegistry::ENGLISH);
        $intro = BlogContent::categoryDescriptionValue($category);
        $introNl = $intro->in(LanguageRegistry::DUTCH);
        $introEn = $intro->in(LanguageRegistry::ENGLISH);
    } elseif ($listing['mode'] === 'tag') {
        $tag = (array) $listing['tag'];
        $eyebrowNl = 'Tag';
        $eyebrowEn = 'Tag';
        $heading = BlogContent::tagNameValue($tag);
        $headingNl = $heading->in(LanguageRegistry::DUTCH);
        $headingEn = $heading->in(LanguageRegistry::ENGLISH);
        $introNl = '';
        $introEn = '';
    }

    /** The page's own URL builder, so the pager and the canonical agree. */
    $pageUrl = static function (int $page) use ($listing): string {
        return match ($listing['mode']) {
            'category' => BlogUrls::categoryPath((string) $listing['category']['slug'], $page),
            'tag' => BlogUrls::tagPath((string) $listing['tag']['slug'], $page),
            default => BlogUrls::indexPath($page),
        };
    };
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($listing === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php if (BlogSettings::rssEnabled()): ?>
<link rel="alternate" type="application/rss+xml" title="<?= $h(BlogLocalizedSettings::title(LanguageRegistry::DUTCH)) ?>" href="<?= $h(BlogUrls::feedPath()) ?>">
<?php endif; ?>
<?php endif; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core and
// the site shell first, and this route adds the one stylesheet the Blog owns.
// It is asked for HERE rather than in the site shell, so no page outside
// /blog ever downloads it.
if ($listing !== null) {
    PageAssets::requireStyle('assets/css/blog/blog.css');
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'blog';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

<?php if ($listing === null): ?>
  <?php render_page_not_found(); ?>
<?php else: ?>

  <?php
    /**
     * The Blog names its own levels — its title is the one the owner typed
     * (BlogLocalizedSettings), not a fixed word — and hands them to the
     * site's one renderer. Core never learns that a blog exists; see
     * MODULES.md.
     */
    $blogTrail = \App\Service\Breadcrumbs\BreadcrumbTrail::home();
    $blogTrail = $listing['mode'] === 'index'
        ? $blogTrail->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($blogTitle->in(LanguageRegistry::DUTCH), $blogTitle->in(LanguageRegistry::ENGLISH)))
        : $blogTrail
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::link($blogTitle->in(LanguageRegistry::DUTCH), $blogTitle->in(LanguageRegistry::ENGLISH), BlogUrls::indexPath()))
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($headingNl, $headingEn));
    render_breadcrumb($blogTrail);
  ?>

  <section class="page-hero">
    <div class="container">
      <?php if ($eyebrowNl !== ''): ?>
        <p class="eyebrow" data-nl="<?= $h($eyebrowNl) ?>" data-en="<?= $h($eyebrowEn) ?>"><?= $h($eyebrowNl) ?></p>
      <?php endif; ?>
      <h1 data-nl="<?= $h($headingNl) ?>" data-en="<?= $h($headingEn) ?>"><?= $h($headingNl) ?></h1>
      <?php if (trim($introNl) !== ''): ?>
        <p class="lead" style="margin-top:1rem;" data-nl="<?= $h($introNl) ?>" data-en="<?= $h($introEn) ?>"><?= $h($introNl) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container">

      <?php /* The category filter row. Only categories that really hold a
               published post are listed (BlogContent::publicCategories()), so
               a visitor is never sent to an empty archive. It is a row of
               links, not a control: no JavaScript, and every state has its
               own URL. */ ?>
      <?php if ($listing['categories'] !== []): ?>
        <nav class="blog-filters" aria-label="Categorieën">
          <a href="<?= $h(BlogUrls::indexPath()) ?>" class="blog-filter<?= $listing['mode'] === 'index' ? ' is-active' : '' ?>"<?= $listing['mode'] === 'index' ? ' aria-current="page"' : '' ?> data-nl="Alles" data-en="All">Alles</a>
          <?php foreach ($listing['categories'] as $category): ?>
            <?php $isActive = $listing['mode'] === 'category' && (int) $listing['category']['id'] === (int) $category['id']; ?>
            <?php $categoryName = BlogContent::categoryNameValue($category); ?>
            <a href="<?= $h((string) $category['url']) ?>" class="blog-filter<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?><?= SiteText::attrsOf($categoryName) ?>><?= $h(SiteText::visibleOf($categoryName)) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <?php if ($listing['posts'] === []): ?>
        <p class="lead" data-nl="Er staan hier nog geen berichten." data-en="There are no posts here yet.">Er staan hier nog geen berichten.</p>
      <?php else: ?>
        <div class="blog-grid">
          <?php foreach ($listing['posts'] as $post): ?>
            <?php
              // One LocalizedValue per field, printed through SiteText: the
              // visible half is the DEFAULT language's, so a card on an
              // English-default site opens in English (Multilingual 2.0
              // phase 5 wave B).
              $title = BlogContent::titleValue($post);
              $excerpt = BlogContent::excerptValue($post);
              $image = $post['image'];
            ?>
            <article class="blog-card" data-reveal>
              <a class="blog-card__link" href="<?= $h((string) $post['url']) ?>">
                <?php if ($post['has_image']): ?>
                  <div class="blog-card__media">
                    <?php /* Dimensions come from the Media Library when it
                             knows them, so the browser can reserve the space;
                             unknown means the attributes are simply left off
                             (MEDIA.md). The alt text is the item's own. */ ?>
                    <img src="<?= $h((string) $image['image_path']) ?>" alt="<?= $h((string) $image['alt_nl']) ?>" data-nl-alt="<?= $h((string) $image['alt_nl']) ?>" data-en-alt="<?= $h((string) $image['alt_en']) ?>" loading="lazy"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?>>
                  </div>
                <?php endif; ?>
                <div class="blog-card__body">
                  <p class="blog-card__meta">
                    <?php if ($post['primary_category'] !== null): ?>
                      <?php $primary = BlogContent::categoryNameValue($post['primary_category']); ?>
                      <span class="blog-card__category"<?= SiteText::attrsOf($primary) ?>><?= $h(SiteText::visibleOf($primary)) ?></span>
                    <?php endif; ?>
                    <?php if (BlogSettings::showDate() && BlogContent::publicationDate($post['published_at']) !== ''): ?>
                      <time datetime="<?= $h(BlogContent::publicationDateAttribute($post['published_at'])) ?>" data-nl="<?= $h(BlogContent::publicationDate($post['published_at'], 'nl')) ?>" data-en="<?= $h(BlogContent::publicationDate($post['published_at'], 'en')) ?>"><?= $h(BlogContent::publicationDate($post['published_at'], 'nl')) ?></time>
                    <?php endif; ?>
                  </p>
                  <h2 class="blog-card__title"<?= SiteText::attrsOf($title) ?>><?= $h(SiteText::visibleOf($title)) ?></h2>
                  <?php if (SiteText::visibleOf($excerpt) !== ''): ?>
                    <p class="blog-card__excerpt"<?= SiteText::attrsOf($excerpt) ?>><?= $h(SiteText::visibleOf($excerpt)) ?></p>
                  <?php endif; ?>
                  <span class="blog-card__more" data-nl="Lees verder" data-en="Read more">Lees verder</span>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php /* The pager. Previous/next plus "pagina X van Y" rather than a
               numbered strip: this blog is measured in tens of posts, and a
               strip that has to collapse itself is more machinery than the
               problem deserves. Both links are ordinary URLs a crawler can
               follow. */ ?>
      <?php if ((int) $listing['pages'] > 1): ?>
        <nav class="blog-pager" aria-label="Paginering">
          <?php if ((int) $listing['page'] > 1): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl((int) $listing['page'] - 1)) ?>" rel="prev" data-nl="Vorige" data-en="Previous">Vorige</a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>

          <p class="blog-pager__status" data-nl="Pagina <?= (int) $listing['page'] ?> van <?= (int) $listing['pages'] ?>" data-en="Page <?= (int) $listing['page'] ?> of <?= (int) $listing['pages'] ?>">Pagina <?= (int) $listing['page'] ?> van <?= (int) $listing['pages'] ?></p>

          <?php if ((int) $listing['page'] < (int) $listing['pages']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl((int) $listing['page'] + 1)) ?>" rel="next" data-nl="Volgende" data-en="Next">Volgende</a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

    </div>
  </section>

<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
