<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
// This route belongs to a module — see blog.php for what the guard does.
\App\Module\ModuleGuard::requirePublicRoute('blog');

require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * One blog post's public page (/blog/<slug>, see .htaccess).
 *
 * A single fixed structure — breadcrumb, category, title, date and author,
 * featured image, rich body, tags, previous/next, related posts — driven
 * entirely by one post's own content. Not a page builder: a post is a piece
 * of writing with a beginning and an end, and giving each one a block layout
 * would be a different feature (BLOG.md).
 *
 * A DRAFT, A POST SCHEDULED FOR NEXT WEEK AND AN UNKNOWN SLUG ARE ONE
 * ANSWER: App\Service\Blog\BlogContent::post() returns null for all three,
 * and this file renders the project's own 404 for it, so unpublished writing
 * cannot leak through this route and a scheduled post cannot be found early
 * by guessing its URL.
 *
 * The body is sanitised rich-text HTML, rendered as real markup — never
 * escaped back to plain text — with the same data-nl/data-en attribute pair
 * portfolio-detail.php and collectie.php use, so assets/js/core.js's language
 * switch gets exactly this HTML back on a toggle.
 */

use App\Service\Blog\BlogContent;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogUrls;
use App\Service\Media\BlockImage;
use App\Service\PageAssets;
use App\Service\Redirects\RedirectGate;

$post = BlogContent::post((string) ($_GET['slug'] ?? ''));

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($post === null) {
    // The one moment the Redirect Manager may speak on this route, and the
    // reason a renamed post's old URL keeps working: Apache routed the
    // request (the slug matched the Blog rewrite), so 404.php never sees it.
    // The lookup runs after the post lookup failed, so a redirect can never
    // shadow a post that does exist. See REDIRECTS.md.
    RedirectGate::handleOr404();

    http_response_code(404);
} else {
    $seoMetadata = BlogSeo::forPost($post);

    $titleNl = BlogContent::title($post, 'nl');
    $titleEn = BlogContent::title($post, 'en');
    $bodyNl = BlogContent::body($post, 'nl');
    $bodyEn = BlogContent::body($post, 'en');
    $author = BlogContent::author($post);
    $showDate = BlogSettings::showDate() && BlogContent::publicationDate($post['published_at']) !== '';
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($post === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php if (BlogSettings::rssEnabled()): ?>
<link rel="alternate" type="application/rss+xml" title="<?= $h(BlogSettings::title('nl')) ?>" href="<?= $h(BlogUrls::feedPath()) ?>">
<?php endif; ?>
<?php endif; ?>
<?php
if ($post !== null) {
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

<?php if ($post === null): ?>
  <?php render_page_not_found(); ?>
<?php else: ?>

  <article class="blog-post">

    <?php /* `narrow` because a post's header keeps to the reading column,
             and the trail has to line up with the title under it. */ ?>
    <?php render_breadcrumb(
        \App\Service\Breadcrumbs\BreadcrumbTrail::home()
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::link(BlogSettings::title('nl'), BlogSettings::title('en'), BlogUrls::indexPath()))
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($titleNl, $titleEn)),
        true
    ); ?>

    <section class="page-hero">
      <div class="container container--narrow">
        <?php if ($post['primary_category'] !== null): ?>
          <p class="eyebrow" data-nl="<?= $h(BlogContent::categoryName($post['primary_category'], 'nl')) ?>" data-en="<?= $h(BlogContent::categoryName($post['primary_category'], 'en')) ?>"><?= $h(BlogContent::categoryName($post['primary_category'], 'nl')) ?></p>
        <?php endif; ?>

        <h1 data-nl="<?= $h($titleNl) ?>" data-en="<?= $h($titleEn) ?>"><?= $h($titleNl) ?></h1>

        <?php if ($showDate || $author !== ''): ?>
          <p class="blog-post__meta">
            <?php if ($showDate): ?>
              <time datetime="<?= $h(BlogContent::publicationDateAttribute($post['published_at'])) ?>" data-nl="<?= $h(BlogContent::publicationDate($post['published_at'], 'nl')) ?>" data-en="<?= $h(BlogContent::publicationDate($post['published_at'], 'en')) ?>"><?= $h(BlogContent::publicationDate($post['published_at'], 'nl')) ?></time>
            <?php endif; ?>
            <?php if ($author !== ''): ?>
              <span class="blog-post__author" data-nl="door <?= $h($author) ?>" data-en="by <?= $h($author) ?>">door <?= $h($author) ?></span>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($post['has_image']): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <figure class="blog-post__media" data-reveal>
            <img src="<?= $h((string) $post['image']['image_path']) ?>" alt="<?= $h((string) $post['image']['alt_nl']) ?>" data-nl-alt="<?= $h((string) $post['image']['alt_nl']) ?>" data-en-alt="<?= $h((string) $post['image']['alt_en']) ?>" fetchpriority="high"<?= BlockImage::dimensionAttributes($post['image']) ?>>
          </figure>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($bodyNl !== ''): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <?php /* Already-sanitised HTML, printed as markup. $h() below is
                   for the data-nl/data-en ATTRIBUTE, a different escaping
                   context, so the language switch (which assigns via
                   innerHTML) gets exactly this HTML back. Identical
                   treatment to collectie.php's rich-text intro. */ ?>
          <div class="rich-content blog-post__body" data-reveal data-nl="<?= $h($bodyNl) ?>" data-en="<?= $h($bodyEn) ?>"><?= $bodyNl ?></div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($post['tags'] !== [] || $post['categories'] !== []): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <nav class="blog-taxonomy" aria-label="Categorieën en tags">
            <?php foreach ($post['categories'] as $category): ?>
              <a href="<?= $h((string) $category['url']) ?>" class="blog-chip blog-chip--category" data-nl="<?= $h(BlogContent::categoryName($category, 'nl')) ?>" data-en="<?= $h(BlogContent::categoryName($category, 'en')) ?>"><?= $h(BlogContent::categoryName($category, 'nl')) ?></a>
            <?php endforeach; ?>
            <?php foreach ($post['tags'] as $tag): ?>
              <a href="<?= $h((string) $tag['url']) ?>" class="blog-chip" data-nl="<?= $h(BlogContent::tagName($tag, 'nl')) ?>" data-en="<?= $h(BlogContent::tagName($tag, 'en')) ?>">#<?= $h(BlogContent::tagName($tag, 'nl')) ?></a>
            <?php endforeach; ?>
          </nav>
        </div>
      </section>
    <?php endif; ?>

  </article>

  <?php if ($post['previous'] !== null || $post['next'] !== null): ?>
    <section style="padding-top:0;">
      <div class="container container--narrow">
        <nav class="blog-neighbours" aria-label="Meer berichten">
          <?php if ($post['previous'] !== null): ?>
            <a class="blog-neighbour" href="<?= $h((string) $post['previous']['url']) ?>" rel="prev">
              <span class="blog-neighbour__label" data-nl="Vorige bericht" data-en="Previous post">Vorige bericht</span>
              <span class="blog-neighbour__title" data-nl="<?= $h(BlogContent::title($post['previous'], 'nl')) ?>" data-en="<?= $h(BlogContent::title($post['previous'], 'en')) ?>"><?= $h(BlogContent::title($post['previous'], 'nl')) ?></span>
            </a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <?php if ($post['next'] !== null): ?>
            <a class="blog-neighbour blog-neighbour--next" href="<?= $h((string) $post['next']['url']) ?>" rel="next">
              <span class="blog-neighbour__label" data-nl="Volgende bericht" data-en="Next post">Volgende bericht</span>
              <span class="blog-neighbour__title" data-nl="<?= $h(BlogContent::title($post['next'], 'nl')) ?>" data-en="<?= $h(BlogContent::title($post['next'], 'en')) ?>"><?= $h(BlogContent::title($post['next'], 'nl')) ?></span>
            </a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </nav>
      </div>
    </section>
  <?php endif; ?>

  <?php /* Related posts: the ones that share the most categories and tags
           with this one, newest first (App\Repository\BlogPostRepository::
           findRelated()). Deterministic and explainable — nothing here looks
           at what anybody read or clicked. */ ?>
  <?php if ($post['related'] !== []): ?>
    <section style="padding-top:0;">
      <div class="container">
        <h2 class="blog-related__heading" data-nl="Meer lezen" data-en="Read more">Meer lezen</h2>
        <div class="blog-grid blog-grid--related">
          <?php foreach ($post['related'] as $related): ?>
            <article class="blog-card" data-reveal>
              <a class="blog-card__link" href="<?= $h((string) $related['url']) ?>">
                <?php if ($related['has_image']): ?>
                  <div class="blog-card__media">
                    <img src="<?= $h((string) $related['image']['image_path']) ?>" alt="<?= $h((string) $related['image']['alt_nl']) ?>" data-nl-alt="<?= $h((string) $related['image']['alt_nl']) ?>" data-en-alt="<?= $h((string) $related['image']['alt_en']) ?>" loading="lazy"<?= BlockImage::dimensionAttributes($related['image']) ?>>
                  </div>
                <?php endif; ?>
                <div class="blog-card__body">
                  <?php if (BlogSettings::showDate() && BlogContent::publicationDate($related['published_at']) !== ''): ?>
                    <p class="blog-card__meta">
                      <time datetime="<?= $h(BlogContent::publicationDateAttribute($related['published_at'])) ?>" data-nl="<?= $h(BlogContent::publicationDate($related['published_at'], 'nl')) ?>" data-en="<?= $h(BlogContent::publicationDate($related['published_at'], 'en')) ?>"><?= $h(BlogContent::publicationDate($related['published_at'], 'nl')) ?></time>
                    </p>
                  <?php endif; ?>
                  <h3 class="blog-card__title" data-nl="<?= $h(BlogContent::title($related, 'nl')) ?>" data-en="<?= $h(BlogContent::title($related, 'en')) ?>"><?= $h(BlogContent::title($related, 'nl')) ?></h3>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <section style="padding-top:0;">
    <div class="container container--narrow">
      <a href="<?= $h(BlogUrls::indexPath()) ?>" class="btn btn--ghost" data-nl="Alle berichten" data-en="All posts">Alle berichten</a>
    </div>
  </section>

<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
