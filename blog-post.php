<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
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
use App\Service\Blog\BlogLocalizedSettings;
use App\Service\Blog\BlogSeo;
use App\Service\Blog\BlogSettings;
use App\Service\Blog\BlogUrls;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\SiteText;
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

    /**
     * WHICH LANGUAGE VERSIONS OF THIS POST EXIST, so the <head> can advertise
     * exactly those as hreflang alternates and the language switch can offer
     * exactly those (App\Service\Routing\LanguageAlternates,
     * docs/multilingual/ROUTING.md). A language this post has no address in
     * is neither advertised nor offered — it has no URL at all.
     */
    \App\Service\Routing\LanguageAlternates::declareVersions(
        \App\Service\Blog\BlogContent::postAlternates($post)
    );

    // One LocalizedValue per field, printed through SiteText: the visible
    // half is the DEFAULT language's (Multilingual 2.0 phase 5 wave B), so a
    // post on an English-default site opens in English.
    $title = BlogContent::titleValue($post);
    $body = BlogContent::bodyValue($post);
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
<link rel="alternate" type="application/rss+xml" title="<?= $h(BlogLocalizedSettings::title(LanguageRegistry::DUTCH)) ?>" href="<?= $h(BlogUrls::feedPath()) ?>">
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
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::link(BlogLocalizedSettings::title(LanguageRegistry::DUTCH), BlogLocalizedSettings::title(LanguageRegistry::ENGLISH), BlogUrls::indexPath()))
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($title->in(LanguageRegistry::DUTCH), $title->in(LanguageRegistry::ENGLISH))),
        true
    ); ?>

    <section class="page-hero">
      <div class="container container--narrow">
        <?php if ($post['primary_category'] !== null): ?>
          <?php $primary = BlogContent::categoryNameValue($post['primary_category']); ?>
          <p class="eyebrow"<?= SiteText::attrsOf($primary) ?>><?= $h(SiteText::visibleOf($primary)) ?></p>
        <?php endif; ?>

        <h1<?= SiteText::attrsOf($title) ?>><?= $h(SiteText::visibleOf($title)) ?></h1>

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

    <?php if (SiteText::visibleOf($body) !== ''): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <?php /* Already-sanitised HTML in every language
                   (App\Service\Blog\BlogLocalization::bodyValue(), which runs
                   RichTextSanitizer per language before the fallback),
                   printed as markup. SiteText::htmlAttrsOf() writes the
                   language pair with the data-lang-html marker that lets
                   assets/js/core.js's applyLang() re-render it with innerHTML
                   instead of the plain-text textContent it uses by default —
                   and writes nothing at all when every language shows the same
                   markup. Identical treatment to the Detailsectie's body. */ ?>
          <div class="rich-content blog-post__body" data-reveal<?= SiteText::htmlAttrsOf($body) ?>><?= SiteText::visibleOf($body) ?></div>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($post['tags'] !== [] || $post['categories'] !== []): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <nav class="blog-taxonomy" aria-label="Categorieën en tags">
            <?php foreach ($post['categories'] as $category): ?>
              <?php $chip = BlogContent::categoryNameValue($category); ?>
              <a href="<?= $h((string) $category['url']) ?>" class="blog-chip blog-chip--category"<?= SiteText::attrsOf($chip) ?>><?= $h(SiteText::visibleOf($chip)) ?></a>
            <?php endforeach; ?>
            <?php foreach ($post['tags'] as $tag): ?>
              <?php /* The hash is markup, not part of the name, so it stays
                       outside the swapped element: core.js writes a tag's own
                       words with textContent into the inner span and the "#"
                       is never part of any language's text. */ ?>
              <?php $chip = BlogContent::tagNameValue($tag); ?>
              <a href="<?= $h((string) $tag['url']) ?>" class="blog-chip">#<span<?= SiteText::attrsOf($chip) ?>><?= $h(SiteText::visibleOf($chip)) ?></span></a>
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
              <?php $neighbour = BlogContent::titleValue($post['previous']); ?>
              <span class="blog-neighbour__title"<?= SiteText::attrsOf($neighbour) ?>><?= $h(SiteText::visibleOf($neighbour)) ?></span>
            </a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <?php if ($post['next'] !== null): ?>
            <a class="blog-neighbour blog-neighbour--next" href="<?= $h((string) $post['next']['url']) ?>" rel="next">
              <span class="blog-neighbour__label" data-nl="Volgende bericht" data-en="Next post">Volgende bericht</span>
              <?php $neighbour = BlogContent::titleValue($post['next']); ?>
              <span class="blog-neighbour__title"<?= SiteText::attrsOf($neighbour) ?>><?= $h(SiteText::visibleOf($neighbour)) ?></span>
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
                  <?php $relatedTitle = BlogContent::titleValue($related); ?>
                  <h3 class="blog-card__title"<?= SiteText::attrsOf($relatedTitle) ?>><?= $h(SiteText::visibleOf($relatedTitle)) ?></h3>
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
