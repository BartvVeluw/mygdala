<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';


/**
 * One reusable dynamic template for every shop collection's public page
 * (see /collecties/{slug} in .htaccess). Not a generic page builder — a
 * single fixed structure (breadcrumb, title, optional image, description,
 * product grid) driven entirely by one collection's own CMS content, exactly
 * like portfolio-detail.php does for a Portfolio project.
 *
 * The product grid is the SAME `.shop-grid` + `[data-products-grid]` markup
 * /shop.php uses; assets/js/shop/shop.js's initShopProducts() fills it from
 * GET /api/products.php?collection=<slug>. There is deliberately no second
 * product-card implementation — a card must look and behave identically
 * whether it was reached from the shop or from a collection, and every card
 * links to the product's own canonical /product.php?id=… page rather than to
 * anything nested under /collecties/.
 *
 * An unknown slug and an inactive collection are deliberately
 * indistinguishable to a visitor: CollectionContent::forPublicPage() returns
 * null for both, producing the same 404 body/status this project already
 * uses on pagina.php and portfolio-detail.php, so an unpublished collection
 * cannot leak through this route.
 *
 * Uses $collection (not $item) and root-relative asset/nav URLs for the same
 * two reasons portfolio-detail.php documents: partials/header.php and
 * partials/footer.php both `foreach ($navItems as $key => $item)` in this
 * same top-level scope, and this file is served from a nested path
 * (/collecties/<slug>), where "assets/..." would 404.
 */

$slug = (string) ($_GET['slug'] ?? '');
$collection = \App\Service\CollectionContent::forPublicPage($slug);

$siteName = \App\Service\SiteSettings::get('site_name');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($collection === null) {
    http_response_code(404);
}

if ($collection !== null) {
    // SEO copy comes from App\Service\CollectionContent — the collection's own
    // editable SEO fields, falling back to its normal CMS content whenever
    // they are empty. No parallel SEO implementation here, and no separately
    // authored third copy that could drift from the visible page. The head is
    // then rendered by partials/shop-seo-head.php, the same block product.php
    // uses.
    $seo = [
        // The head carries the request's language, resolved by
        // CollectionContent.
        'title' => \App\Service\CollectionContent::seoTitle($collection),
        'description' => \App\Service\CollectionContent::metaDescription($collection),
        // The canonical URL of a collection page is the collection page
        // itself. Products keep their own canonical URLs — a product
        // appearing in three collections is still one page at
        // /product.php?id=…, never three.
        'canonical_url' => \App\Service\CollectionContent::canonicalUrl($collection),
        'og_image_path' => \App\Service\CollectionContent::socialImagePath($collection),
        // A collection page is a listing, not a single item, so it emits no
        // structured data of its own; the products it lists carry their own
        // Product JSON-LD on their own pages.
        'json_ld' => null,
    ];
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// An unknown or unpublished collection renders a "not found" head — a
// title, noindex,follow and nothing else — and the found case goes through
// the collection's own resolver. Both end up in partials/seo-head.php.
if ($collection === null) {
    $seoMetadata = \App\Service\SeoMetadata::notFound(
        \App\Service\Language\SiteText::pick(['nl' => 'Collectie niet gevonden', 'en' => 'Collection not found']) . ' — ' . $siteName
    );
    require __DIR__ . '/partials/seo-head.php';
} else {
    // The language versions this collection really has, for hreflang and
    // for the language switch (docs/multilingual/ROUTING.md).
    \App\Service\Routing\LanguageAlternates::declareVersions(
        \App\Service\CollectionContent::alternates($collection)
    );
    require __DIR__ . '/partials/shop-seo-head.php';
}
?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
// A collection page is the shop grid under another heading.
\App\Service\PageAssets::requireStyle('assets/css/shop/shop.css');
\App\Service\PageAssets::requireScript('assets/js/shop/shop.js');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'shop';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

<?php if ($collection === null): ?>
  <?php /* The last level is what the page IS, so the storefront above it
           stays a link the visitor can take. It used to end at "Shop", which
           made the one useful link in the trail unclickable. */ ?>
  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toPage('shop', 'shop')
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Collectie niet gevonden', 'en' => 'Collection not found'])))
  ); ?>
  <section class="page-hero">
    <div class="container">
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Collectie niet gevonden', 'en' => 'Collection not found']) ?></h1>
      <p class="lead" style="margin-top:1rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Deze collectie bestaat niet (meer) of is niet zichtbaar. Bekijk hieronder de rest van de shop.', 'en' => 'This collection doesn\'t exist (anymore) or isn\'t visible. Browse the rest of the shop below.']) ?></p>
      <a href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/shop.php')) ?>" class="btn" style="margin-top:1.5rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar de shop', 'en' => 'To the shop']) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </section>
<?php else: ?>
  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toPage('shop', 'shop')
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current($collection['name']))
  ); ?>
  <section class="page-hero">
    <div class="container">
      <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Collectie', 'en' => 'Collection']) ?></p>
      <h1><?= $h($collection['name']) ?></h1>
    </div>
  </section>

  <?php if ($collection['image_path'] !== null || $collection['description'] !== ''): ?>
  <section style="padding-top:0;">
    <div class="container">
      <div class="collection-intro<?= $collection['image_path'] === null ? ' collection-intro--text-only' : '' ?>">
        <?php if ($collection['image_path'] !== null): ?>
          <figure class="collection-intro__media" data-reveal>
            <img src="/<?= $h(ltrim($collection['image_path'], '/')) ?>" alt="<?= $h($collection['name']) ?>" fetchpriority="high">
          </figure>
        <?php endif; ?>
        <?php if ($collection['description'] !== ''): ?>
          <?php
            // The description is already sanitized HTML in the language of
            // the request (DescriptionSanitizer, at save time and again per
            // language in App\Service\ShopLocalization) — rendered as real
            // markup here, never escaped back to plain text. Identical
            // treatment to the Blog's body and the Detailsectie's.
          ?>
          <div class="rich-content collection-intro__text" data-reveal><?= $collection['description'] ?></div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section style="padding-top:0;">
    <div class="container">
      <div class="shop-grid" data-products-grid data-collection-slug="<?= $h($collection['slug']) ?>">
        <p class="lead" data-products-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten laden…', 'en' => 'Loading products…']) ?></p>
      </div>
      <p class="lead" data-products-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier.', 'en' => 'Products can\'t be loaded right now. Please try again later or get in touch via the quote form.']) ?></p>

      <div style="margin-top:var(--sp-5);">
        <a href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/shop.php')) ?>" class="btn btn--ghost"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Alle producten bekijken', 'en' => 'Browse all products']) ?></a>
      </div>
    </div>
  </section>
<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
