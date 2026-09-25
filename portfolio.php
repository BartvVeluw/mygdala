<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to the Portfolio module. With it switched off the file is
// still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as an
// unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('portfolio');

// The overview's old address (/portfolio.php, and /<lang>/portfolio.php)
// moved to the module root for good: a permanent redirect to /portfolio, the
// query string kept, before anything is read or printed. A POST is answered
// here as it always was. See App\Service\PortfolioUrls.
$movedTo = \App\Service\PortfolioUrls::legacyOverviewRedirectUrl(
    (string) ($_SERVER['REQUEST_URI'] ?? ''),
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);
if ($movedTo !== null) {
    header('Location: ' . $movedTo, true, \App\Service\Redirects\Redirect::STATUS_PERMANENT);
    exit;
}

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

// Resolved BEFORE a single byte of HTML: http_response_code() below is only
// honoured while no output has been sent yet (same reason pagina.php looks
// its page up at the very top).
//
// TWO WAYS TO BE THE OVERVIEW, decided by whether a CMS page with content key
// "portfolio" exists (App\Service\PortfolioUrls::overviewPage()):
//
//   - a page exists (an installation that has had the Portfolio page for
//     years): that page is the overview, with its own blocks, title, SEO and
//     publication. Set to Concept it answers 404, as it always did: the
//     editor chose that.
//   - no page at all (a new installation, or Portfolio switched on later):
//     the module's own overview — every visible project in the gallery, the
//     filter bar, the lightbox — the way /blog is the Blog's own and /shop.php
//     showed the Shop's. Nothing is created for it, so nothing can be created
//     twice.
$page = \App\Service\PortfolioUrls::overviewPage();
$builtin = $page === null;
if ($page !== null && !\App\Service\PageContent::isPublished($page)) {
    $page = null;
    http_response_code(404);
}

if ($builtin) {
    // A route without a CMS page builds its metadata here, through the one
    // App\Service\SeoMetadata, like shop.php's own overview: indexable, listed
    // in the sitemap by App\Module\PortfolioModule, and its own canonical in
    // the request's language, with a version in every active one.
    $seoMetadata = \App\Service\SeoMetadata::create(
        title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(\App\Service\PortfolioUrls::OVERVIEW_LABEL)),
        canonical: \App\Service\Routing\LocalizedUrl::absolute(\App\Service\PortfolioUrls::OVERVIEW_PATH),
    );
    \App\Service\Routing\LanguageAlternates::declareVersions(\App\Service\PortfolioUrls::overviewVersions());
    $overviewGallery = \App\Service\PortfolioGalleryContent::builtinOverviewGallery();
    require_once __DIR__ . '/partials/section-item-gallery.php';
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($builtin): ?>
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php elseif ($page === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/page-head.php'; ?>
<?php endif; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
// The module's own overview asks for the gallery block's files, which it
// draws through the same partial.
if ($builtin) {
    $galleryBlock = \App\Service\Blocks\BlockDefinitions::get('item_gallery');
    foreach ($galleryBlock?->styles() ?? [] as $style) {
        \App\Service\PageAssets::requireStyle($style);
    }
    foreach ($galleryBlock?->scripts() ?? [] as $script) {
        \App\Service\PageAssets::requireScript($script);
    }
} else {
    \App\Service\SectionRegistry::collectPageAssets('portfolio');
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'portfolio';
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <?php if ($builtin): ?>
    <?php render_breadcrumb(\App\Service\Breadcrumbs\BreadcrumbTrail::home()->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(\App\Service\PortfolioUrls::OVERVIEW_LABEL)))); ?>
    <section class="page-hero">
      <div class="container">
        <h1><?= \App\Service\Language\SiteText::escaped(\App\Service\PortfolioUrls::OVERVIEW_LABEL) ?></h1>
      </div>
    </section>
    <?php if ($overviewGallery['items'] === []): ?>
      <section>
        <div class="container">
          <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Er staan nog geen projecten in het portfolio.', 'en' => 'There are no projects in the portfolio yet.']) ?></p>
        </div>
      </section>
    <?php else: ?>
      <?php render_section_item_gallery($overviewGallery, 'portfolio-overview'); ?>
    <?php endif; ?>
  <?php elseif ($page === null): ?>
    <?php render_page_not_found(); ?>
  <?php else: ?>
    <?php \App\Service\SectionRegistry::renderPage('portfolio', \App\Service\Breadcrumbs\PageBreadcrumb::forPage($page)); ?>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
