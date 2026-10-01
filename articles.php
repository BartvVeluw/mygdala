<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// A module route: with Articles off this is the site's own 404, nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('articles');

require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * The Articles listing (ARTICLES.md), behind two URL shapes
 * (App\Module\ArticlesModule::publicRoutes()):
 *
 *   /artikelen                    every listed article with a version in this language
 *   /artikelen/onderwerp/<slug>   the same, of one topic
 *
 * Listed only (the Publishing Engine's rule): no draft, nothing waiting for
 * its moment, nothing archived. Server-side pagination with real links, no
 * JavaScript. An unknown topic or a page past the end is the site's 404,
 * after the Redirect Manager had its chance (a renamed topic).
 */

use App\Service\Articles\ArticleContent;
use App\Service\Articles\ArticleSeo;
use App\Service\Articles\ArticleUrls;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Redirects\RedirectGate;
use App\Service\Routing\RequestLanguage;

$language = RequestLanguage::current();
$requestedPage = filter_input(INPUT_GET, ArticleUrls::PAGE_PARAM, FILTER_VALIDATE_INT);
$listing = ArticleContent::listing([
    'topic' => (string) ($_GET['topic'] ?? ''),
    'page' => is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1,
], $language);

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($listing === null) {
    RedirectGate::handleOr404();
    http_response_code(404);
} else {
    $page = (int) $listing['page'];
    $listingTitle = SiteText::pick(ArticleSeo::LISTING_TITLE, $language);

    if ($listing['mode'] === 'topic') {
        $topic = (array) $listing['topic'];
        $seoMetadata = ArticleSeo::forTopic($topic, $page, $language);
        \App\Service\Routing\LanguageAlternates::declareVersions(ArticleContent::topicAlternates((int) $topic['id'], $page));
        $heading = (string) $topic['name'];
        $intro = (string) $topic['description'];
        $pageUrl = static fn (int $number): string => ArticleUrls::topicPath((string) \App\Service\Articles\ArticleLocalization::topicSlug((int) $topic['id'], $language), $number, $language);
    } else {
        $seoMetadata = ArticleSeo::forListing($page, $language);
        $versions = [];
        foreach (\App\Service\Language\SiteLanguages::activeCodes() as $code) {
            $versions[$code] = ArticleUrls::indexPath($page, $code);
        }
        \App\Service\Routing\LanguageAlternates::declareVersions($versions);
        $heading = $listingTitle;
        $intro = '';
        $pageUrl = static fn (int $number): string => ArticleUrls::indexPath($number, $language);
    }
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($listing === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php endif; ?>
<?php
if ($listing !== null) {
    PageAssets::requireStyle('assets/css/articles/articles.css');
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'articles';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

<?php if ($listing === null): ?>
  <?php render_page_not_found(); ?>
<?php else: ?>

  <?php
    $trail = \App\Service\Breadcrumbs\BreadcrumbTrail::home();
    $trail = $listing['mode'] === 'index'
        ? $trail->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($listingTitle))
        : $trail->to(\App\Service\Breadcrumbs\BreadcrumbItem::link($listingTitle, ArticleUrls::indexPath(1, $language)))
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($heading));
    render_breadcrumb($trail, true);
  ?>

  <section class="page-hero">
    <div class="container container--narrow">
      <?php if ($listing['mode'] === 'topic'): ?>
        <p class="eyebrow"><?= SiteText::escaped(['nl' => 'Onderwerp', 'en' => 'Topic']) ?></p>
      <?php endif; ?>
      <h1><?= $h($heading) ?></h1>
      <?php if (trim($intro) !== ''): ?>
        <p class="lead" style="margin-top:1rem;"><?= $h($intro) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container container--narrow">
      <?php if ($listing['topics'] !== []): ?>
        <nav class="article-topics" aria-label="<?= SiteText::escaped(['nl' => 'Onderwerpen', 'en' => 'Topics']) ?>">
          <a href="<?= $h(ArticleUrls::indexPath(1, $language)) ?>" class="article-topic<?= $listing['mode'] === 'index' ? ' is-active' : '' ?>"<?= $listing['mode'] === 'index' ? ' aria-current="page"' : '' ?>><?= SiteText::escaped(['nl' => 'Alles', 'en' => 'All']) ?></a>
          <?php foreach ($listing['topics'] as $topicLink): ?>
            <?php $isActive = $listing['mode'] === 'topic' && (int) $listing['topic']['id'] === (int) $topicLink['id']; ?>
            <a href="<?= $h((string) $topicLink['url']) ?>" class="article-topic<?= $isActive ? ' is-active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= $h((string) $topicLink['name']) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>

      <?php if ($listing['articles'] === []): ?>
        <p class="lead"><?= SiteText::escaped(['nl' => 'Er staan hier nog geen artikelen.', 'en' => 'There are no articles here yet.']) ?></p>
      <?php else: ?>
        <div class="article-list">
          <?php foreach ($listing['articles'] as $article): ?>
            <?php $image = $article['image']; ?>
            <article class="article-row<?= $image === null ? ' article-row--text-only' : '' ?>" data-reveal>
              <a class="article-row__link" href="<?= $h((string) $article['url']) ?>">
                <?php if ($image !== null): ?>
                  <div class="article-row__media">
                    <img src="<?= $h($image['src']) ?>" alt="<?= $h($image['alt']) ?>" loading="lazy"<?= $image['srcset'] !== '' ? ' srcset="' . $h($image['srcset']) . '" sizes="(max-width: 700px) 100vw, 320px"' : '' ?><?= $image['width'] !== null ? ' width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '"' : '' ?>>
                  </div>
                <?php endif; ?>
                <div class="article-row__body">
                  <?php if ($article['topic'] !== null && (string) $article['topic']['name'] !== ''): ?>
                    <p class="article-eyebrow"><?= $h((string) $article['topic']['name']) ?></p>
                  <?php endif; ?>
                  <h2 class="article-row__title"><?= $h((string) $article['title']) ?></h2>
                  <?php if ((string) $article['excerpt'] !== ''): ?>
                    <p class="article-row__excerpt"><?= $h((string) $article['excerpt']) ?></p>
                  <?php endif; ?>
                  <?php if ($article['author'] !== '' || $article['date'] !== ''): ?>
                    <p class="article-meta">
                      <?php if ($article['author'] !== ''): ?><span><?= $h((string) $article['author']) ?></span><?php endif; ?>
                      <?php if ($article['date'] !== ''): ?><time datetime="<?= $h((string) $article['date_attribute']) ?>"><?= $h((string) $article['date']) ?></time><?php endif; ?>
                    </p>
                  <?php endif; ?>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ((int) $listing['pages'] > 1): ?>
        <nav class="article-pager" aria-label="<?= SiteText::escaped(['nl' => 'Paginering', 'en' => 'Pagination']) ?>">
          <?php if ($page > 1): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl($page - 1)) ?>" rel="prev"><?= SiteText::escaped(['nl' => 'Vorige', 'en' => 'Previous']) ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <p class="article-pager__status"><?= $h(sprintf(SiteText::pick(['nl' => 'Pagina %d van %d', 'en' => 'Page %d of %d']), $page, (int) $listing['pages'])) ?></p>
          <?php if ($page < (int) $listing['pages']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl($page + 1)) ?>" rel="next"><?= SiteText::escaped(['nl' => 'Volgende', 'en' => 'Next']) ?></a>
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
