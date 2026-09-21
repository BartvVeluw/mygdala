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

    /**
     * WHICH LANGUAGE VERSIONS OF THIS ARCHIVE EXIST, declared the way
     * blog-post.php declares a post's (App\Service\Routing\LanguageAlternates,
     * docs/multilingual/ROUTING.md). A category or a tag has its own address
     * per language, so the same path under another prefix is not its other
     * version: the switch links only the languages it has an address in and
     * shows the rest as unavailable, and hreflang names exactly those.
     *
     * The index is one fixed route that exists in every published language,
     * so each language's index is a version of it. It is declared all the
     * same: hreflang names only declared versions, and the sitemap
     * (App\Module\BlogModule) lists the index with these alternates.
     */
    if ($listing['mode'] === 'index') {
        $indexVersions = [];
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $indexLanguage) {
            $indexVersions[$indexLanguage] = BlogUrls::indexPath((int) $listing['page'], $indexLanguage);
        }
        \App\Service\Routing\LanguageAlternates::declareVersions($indexVersions);
    } elseif ($listing['mode'] === 'category') {
        \App\Service\Routing\LanguageAlternates::declareVersions(
            BlogContent::categoryAlternates((array) $listing['category'], (int) $listing['page'])
        );
    } elseif ($listing['mode'] === 'tag') {
        \App\Service\Routing\LanguageAlternates::declareVersions(
            BlogContent::tagAlternates((array) $listing['tag'], (int) $listing['page'])
        );
    }

    // What this page is called and what it says about itself, per mode, in
    // the language of the request. The listing's own heading and
    // introduction come from BlogLocalizedSettings; an archive overwrites
    // them with the category's or the tag's own words below, each with the
    // fallback already applied.
    $language = \App\Service\Routing\RequestLanguage::current();
    $blogTitle = BlogLocalizedSettings::title($language);
    $heading = $blogTitle;
    $intro = BlogLocalizedSettings::intro($language);
    $eyebrow = '';

    if ($listing['mode'] === 'category') {
        $category = (array) $listing['category'];
        $eyebrow = SiteText::pick(['nl' => 'Categorie', 'en' => 'Category']);
        $heading = BlogContent::categoryName($category);
        $intro = BlogContent::categoryDescription($category);
    } elseif ($listing['mode'] === 'tag') {
        $tag = (array) $listing['tag'];
        $eyebrow = SiteText::pick(['nl' => 'Tag', 'en' => 'Tag']);
        $heading = BlogContent::tagName($tag);
        $intro = '';
    }

    /** The page's own URL builder, so the pager and the canonical agree. */
    $pageUrl = static function (int $page) use ($listing): string {
        return match ($listing['mode']) {
            // Through BlogContent, so a paginated archive keeps the address
            // of the language it is being read in rather than falling back to
            // the neutral column (docs/multilingual/ROUTING.md).
            'category' => BlogContent::categoryUrl($listing['category'], $page),
            'tag' => BlogContent::tagUrl($listing['tag'], $page),
            default => BlogUrls::indexPath($page),
        };
    };
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($listing === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php if (BlogSettings::rssEnabled()): ?>
<?php /* The feed in the language this page is read in: its address and its name (App\Service\Blog\BlogFeed). */ ?>
<link rel="alternate" type="application/rss+xml" title="<?= $h(BlogLocalizedSettings::title(\App\Service\Routing\RequestLanguage::current())) ?>" href="<?= $h(BlogUrls::feedPath()) ?>">
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
        ? $blogTrail->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($blogTitle))
        : $blogTrail
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::link($blogTitle, BlogUrls::indexPath()))
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($heading));
    render_breadcrumb($blogTrail);
  ?>

  <section class="page-hero">
    <div class="container">
      <?php if ($eyebrow !== ''): ?>
        <p class="eyebrow"><?= $h($eyebrow) ?></p>
      <?php endif; ?>
      <h1><?= $h($heading) ?></h1>
      <?php if (trim($intro) !== ''): ?>
        <p class="lead" style="margin-top:1rem;"><?= $h($intro) ?></p>
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
        <nav class="blog-filters" aria-label="<?= SiteText::escaped(['nl' => 'Categorieën', 'en' => 'Categories']) ?>">
          <a href="<?= $h(BlogUrls::indexPath()) ?>" class="blog-filter<?= $listing['mode'] === 'index' ? ' is-active' : '' ?>"<?= $listing['mode'] === 'index' ? ' aria-current="page"' : '' ?>><?= SiteText::escaped(['nl' => 'Alles', 'en' => 'All']) ?></a>
          <?php foreach ($listing['categories'] as $category): ?>
            <?php $isActive = $listing['mode'] === 'category' && (int) $listing['category']['id'] === (int) $category['id']; ?>
            <?php $categoryName = BlogContent::categoryName($category); ?>
            <a href="<?= $h((string) $category['url']) ?>" class="blog-filter<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= $h($categoryName) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <?php if ($listing['posts'] === []): ?>
        <p class="lead"><?= SiteText::escaped(['nl' => 'Er staan hier nog geen berichten.', 'en' => 'There are no posts here yet.']) ?></p>
      <?php else: ?>
        <div class="blog-grid">
          <?php foreach ($listing['posts'] as $post): ?>
            <?php
              // Every word in the language of the request, the fallback
              // already applied (App\Service\Blog\BlogContent).
              $title = BlogContent::title($post);
              $excerpt = BlogContent::excerpt($post);
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
                    <img src="<?= $h((string) $image['image_path']) ?>" alt="<?= $h((string) $image['alt']) ?>" loading="lazy"<?= \App\Service\Media\BlockImage::dimensionAttributes($image) ?>>
                  </div>
                <?php endif; ?>
                <div class="blog-card__body">
                  <p class="blog-card__meta">
                    <?php if ($post['primary_category'] !== null): ?>
                      <?php $primary = BlogContent::categoryName($post['primary_category']); ?>
                      <span class="blog-card__category"><?= $h($primary) ?></span>
                    <?php endif; ?>
                    <?php if (BlogSettings::showDate() && BlogContent::publicationDate($post['published_at']) !== ''): ?>
                      <time datetime="<?= $h(BlogContent::publicationDateAttribute($post['published_at'])) ?>"><?= $h(BlogContent::publicationDate($post['published_at'])) ?></time>
                    <?php endif; ?>
                  </p>
                  <h2 class="blog-card__title"><?= $h($title) ?></h2>
                  <?php if ($excerpt !== ''): ?>
                    <p class="blog-card__excerpt"><?= $h($excerpt) ?></p>
                  <?php endif; ?>
                  <span class="blog-card__more"><?= SiteText::escaped(['nl' => 'Lees verder', 'en' => 'Read more']) ?></span>
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
        <nav class="blog-pager" aria-label="<?= SiteText::escaped(['nl' => 'Paginering', 'en' => 'Pagination']) ?>">
          <?php if ((int) $listing['page'] > 1): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl((int) $listing['page'] - 1)) ?>" rel="prev"><?= SiteText::escaped(['nl' => 'Vorige', 'en' => 'Previous']) ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>

          <p class="blog-pager__status"><?= $h(sprintf(SiteText::pick(['nl' => 'Pagina %d van %d', 'en' => 'Page %d of %d']), (int) $listing['page'], (int) $listing['pages'])) ?></p>

          <?php if ((int) $listing['page'] < (int) $listing['pages']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl((int) $listing['page'] + 1)) ?>" rel="next"><?= SiteText::escaped(['nl' => 'Volgende', 'en' => 'Next']) ?></a>
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
