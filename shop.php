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

// The storefront has two sources, and this is the one place that chooses.
//
// An installation whose CMS has a page with content_key `shop` — every one
// that ran the install bootstrap before it stopped seeding that page —
// renders that page: its title, its SEO fields and its blocks, the product
// grid among them, exactly as before.
//
// A newer installation has no such page, because a webshop is a module and
// not a page an editor has to keep (INSTALL-BOOTSTRAP.md). This URL is still
// the Shop's — the cart, the checkout and every product page link back to
// it — so it renders the module's own overview instead: a heading and the
// product grid, whose assets are asked for through the grid's own block
// definition so they keep their one owner
// (Tests\Service\FrontendAssetOwnershipTest).
$page = \App\Service\PageContent::forContentKey('shop');
$storefrontGrid = $page === null ? \App\Service\Blocks\BlockDefinitions::get('product_grid') : null;

if ($page === null) {
    // The same shape as cart.php's head: a route without a CMS page builds its
    // metadata here, through the one App\Service\SeoMetadata. Unlike the cart
    // this is public content a crawler should find, so it stays indexable and
    // the Shop lists it in the sitemap (App\Module\ShopModule).
    $seoMetadata = \App\Service\SeoMetadata::create(
        titleNl: \App\Service\Seo::routeTitle('Shop'),
        titleEn: \App\Service\Seo::routeTitle('Shop'),
        canonical: \App\Service\AppUrl::canonical('shop.php'),
    );
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
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
      <h1 data-nl="Shop" data-en="Shop">Shop</h1>
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
