<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// A module route: with Articles off this is the site's own 404, nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('articles');

require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * One article (/artikelen/<slug>, /en/articles/<slug>; ARTICLES.md).
 *
 * The head of the piece — topic, title, intro as a lead, byline and date as
 * a quiet meta line, the featured image — and then its content blocks
 * through the ordinary block engine, at their own widths and with their
 * own Extra vormgeving. No sidebar, no tags, no previous/next, no feed: an
 * article is not a blog post.
 *
 * A draft, a future scheduled article, a language without a version and an
 * unknown slug are one answer: the site's 404 (ArticleContent::article()).
 * An archived article answers at its address with noindex (ArticleSeo).
 */

use App\Service\Articles\ArticleContent;
use App\Service\Articles\ArticleContentOwner;
use App\Service\Articles\ArticleSeo;
use App\Service\Articles\ArticleUrls;
use App\Service\ContentOwners\ContentPages;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Redirects\RedirectGate;
use App\Service\Routing\RequestLanguage;

$language = RequestLanguage::current();
$article = ArticleContent::article((string) ($_GET['slug'] ?? ''), $language);

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($article === null) {
    // A renamed article's old address: the Redirect Manager speaks only
    // after the article lookup failed, so it can never shadow one.
    RedirectGate::handleOr404();
    http_response_code(404);
} else {
    $seoMetadata = ArticleSeo::forArticle($article, $language);
    \App\Service\Routing\LanguageAlternates::declareVersions(ArticleContent::alternates((int) $article['id']));
    $blocksKey = ContentPages::contentKey(ArticleContentOwner::KIND, (int) $article['id']);
    $listingTitle = SiteText::pick(ArticleSeo::LISTING_TITLE, $language);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($article === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php endif; ?>
<?php
if ($article !== null) {
    PageAssets::requireStyle('assets/css/articles/articles.css');
    // The stylesheets and scripts of the blocks this article really has.
    \App\Service\SectionRegistry::collectPageAssets($blocksKey);
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

<?php if ($article === null): ?>
  <?php render_page_not_found(); ?>
<?php else: ?>

  <article class="article">
    <?php
      $trail = \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::link($listingTitle, ArticleUrls::indexPath(1, $language)));
      if ($article['topic'] !== null && $article['topic']['url'] !== null && (string) $article['topic']['name'] !== '') {
          $trail = $trail->to(\App\Service\Breadcrumbs\BreadcrumbItem::link((string) $article['topic']['name'], (string) $article['topic']['url']));
      }
      render_breadcrumb($trail->to(\App\Service\Breadcrumbs\BreadcrumbItem::current((string) $article['title'])), true);
    ?>

    <section class="page-hero article-head">
      <div class="container container--narrow">
        <?php if ($article['topic'] !== null && (string) $article['topic']['name'] !== ''): ?>
          <?php if ($article['topic']['url'] !== null): ?>
            <p class="eyebrow"><a href="<?= $h((string) $article['topic']['url']) ?>"><?= $h((string) $article['topic']['name']) ?></a></p>
          <?php else: ?>
            <p class="eyebrow"><?= $h((string) $article['topic']['name']) ?></p>
          <?php endif; ?>
        <?php endif; ?>
        <h1><?= $h((string) $article['title']) ?></h1>
        <?php if ((string) $article['excerpt'] !== ''): ?>
          <p class="article-head__lead"><?= $h((string) $article['excerpt']) ?></p>
        <?php endif; ?>
        <?php if ($article['author'] !== '' || $article['date'] !== ''): ?>
          <p class="article-meta">
            <?php if ($article['author'] !== ''): ?>
              <span><?= SiteText::escaped(['nl' => 'Door', 'en' => 'By']) ?> <?= $h((string) $article['author']) ?></span>
            <?php endif; ?>
            <?php if ($article['date'] !== ''): ?>
              <time datetime="<?= $h((string) $article['date_attribute']) ?>"><?= $h((string) $article['date']) ?></time>
            <?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($article['image'] !== null): ?>
      <section style="padding-top:0;">
        <div class="container container--narrow">
          <figure class="article-figure">
            <img src="<?= $h($article['image']['src']) ?>" alt="<?= $h($article['image']['alt']) ?>" fetchpriority="high"<?= $article['image']['width'] !== null ? ' width="' . (int) $article['image']['width'] . '" height="' . (int) $article['image']['height'] . '"' : '' ?>>
          </figure>
        </div>
      </section>
    <?php endif; ?>

<?php \App\Service\SectionRegistry::renderPage($blocksKey); ?>

    <section>
      <div class="container container--narrow article-footer">
        <a href="<?= $h(ArticleUrls::indexPath(1, $language)) ?>" class="btn btn--ghost"><?= SiteText::escaped(['nl' => 'Alle artikelen', 'en' => 'All articles']) ?></a>
      </div>
    </section>
  </article>

<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
