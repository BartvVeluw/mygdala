<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';

// /shop.php is not the Shop's page by definition any more. Which page is the
// product overview, if any, is the owner's choice under Shop-instellingen
// (App\Service\ShopOverview, MODULES.md "Shop"), and this route follows it:
//
//   - no overview: 404, like any URL that has nothing behind it. It never
//     lists every product on its own;
//   - the storefront page (content_key `shop`, what older installations were
//     seeded with): rendered here, at its own address, exactly as before;
//   - any other page: a 302 to that page's address in the language being
//     read, so an old link or bookmark still lands on the overview. 302 and
//     not 301, because the owner can choose another page tomorrow;
//   - 'builtin', an older installation's automatic listing, kept by
//     db/migrations/20260923140000 until its owner chooses: a heading and the
//     product grid, whose assets are asked for through the grid's own block
//     definition so they keep their one owner
//     (Tests\Service\FrontendAssetOwnershipTest).
$overviewMode = \App\Service\ShopOverview::mode();
$overviewPage = \App\Service\ShopOverview::page();
$page = null;

if ($overviewMode === \App\Service\ShopOverview::PAGE && \App\Service\ShopOverview::isStorefrontPage($overviewPage)) {
    $page = \App\Service\PageContent::forContentKey('shop');
} elseif ($overviewMode === \App\Service\ShopOverview::PAGE) {
    $overviewUrl = \App\Service\ShopOverview::url();
    if ($overviewUrl !== null) {
        header('Location: ' . $overviewUrl, true, 302);
        exit;
    }
} elseif ($overviewMode === \App\Service\ShopOverview::BUILTIN) {
    $page = \App\Service\PageContent::forContentKey('shop');
}

// Nothing to show: no overview, a chosen page that is not published, or a
// storefront page that is not (or no longer) there.
$builtin = $overviewMode === \App\Service\ShopOverview::BUILTIN && $page === null;
if ($page === null && !$builtin) {
    http_response_code(404);
    require __DIR__ . '/partials/route-not-found-page.php';
    exit;
}

$storefrontGrid = $builtin ? \App\Service\Blocks\BlockDefinitions::get('product_grid') : null;

if ($page === null) {
    // The same shape as cart.php's head: a route without a CMS page builds its
    // metadata here, through the one App\Service\SeoMetadata. Unlike the cart
    // this is public content a crawler should find, so it stays indexable and
    // the Shop lists it in the sitemap (App\Module\ShopModule), once per
    // active language — and each of those is its own canonical, in the
    // request's language like the cart's.
    $seoMetadata = \App\Service\SeoMetadata::create(
        title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Shop', 'en' => 'Shop'])),
        canonical: \App\Service\Routing\LocalizedUrl::absolute('/shop.php'),
    );
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
if ($page !== null) {
    // Title, meta description, canonical and Open Graph tags all come from
    // this page's own row in the CMS `pages` table (see
    // App\Service\PageContent and partials/page-head.php).
    require __DIR__ . '/partials/page-head.php';
} else {
    require __DIR__ . '/partials/seo-head.php';
}
?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
if ($page !== null) {
    \App\Service\SectionRegistry::collectPageAssets('shop');
} elseif ($storefrontGrid !== null) {
    foreach ($storefrontGrid->styles() as $style) {
        \App\Service\PageAssets::requireStyle($style);
    }
    foreach ($storefrontGrid->scripts() as $script) {
        \App\Service\PageAssets::requireScript($script);
    }
    foreach ($storefrontGrid->vendorScripts() as $vendor) {
        \App\Service\PageAssets::requireVendorScript($vendor);
    }
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'shop';
require __DIR__ . '/partials/header.php';
?>


<main id="main">

<?php if ($page !== null): ?>
  <?php render_breadcrumb(\App\Service\Breadcrumbs\PageBreadcrumb::forPage($page)); ?>
  <?php \App\Service\SectionRegistry::renderPage('shop'); ?>
<?php else: ?>
  <?php /* No `pages` row, so there is no page title to name and no switch to
           read: the storefront is named by its own route, which is also what
           the cart, the checkout and every product page fall back to. */ ?>
  <?php render_breadcrumb(\App\Service\Breadcrumbs\BreadcrumbTrail::home()->toRoute('shop')); ?>
  <section class="page-hero">
    <div class="container">
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Shop', 'en' => 'Shop']) ?></h1>
    </div>
  </section>
  <?php
    // A fixed block with no content row: the grid reads nothing from the
    // attachment it is handed, so there is no page_sections row to invent.
    $storefrontGrid?->render(['id' => 0, 'section_type' => 'product_grid'], false, 'product_grid-storefront');
  ?>
<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
