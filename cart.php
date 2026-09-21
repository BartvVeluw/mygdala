<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';

// This route has no CMS page behind it, so its SEO metadata is built here
// — but through the same App\Service\SeoMetadata every other public page
// uses, so the title convention, the escaping, the Open Graph copy and the
// social-image fallback all come from one place.
//
// NOT INDEXABLE, and that is the point of stating it: the cart is a private,
// per-visitor, always-empty-to-a-crawler page. Before SEO Foundation V1 this
// page carried a canonical tag and Open Graph tags and no robots tag at all,
// which made it a candidate for the index. Its canonical is this route in the
// request's own language (docs/multilingual/ROUTING.md, §10), like every
// system page's: App\Service\AppUrl alone knows no language, and named the
// default language's cart from /en/cart.php.
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Winkelwagen', 'en' => 'Shopping cart'])),
    description: \App\Service\Language\SiteText::pick([
        'nl' => 'Bekijk en pas je winkelwagen aan voordat je afrekent.',
        'en' => 'Review and adjust your cart before checking out.',
    ]),
    canonical: \App\Service\Routing\LocalizedUrl::absolute('/cart.php'),
    indexable: false,
);

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
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

  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toPage('shop', 'shop')
          ->toRoute('cart')
  ); ?>

  <section class="page-hero" style="padding-bottom:0;">
    <div class="container">
      <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Stap 1 van 2', 'en' => 'Step 1 of 2']) ?></p>
      <h1 style="max-width:20ch;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Jouw winkelwagen', 'en' => 'Your shopping cart']) ?></h1>
    </div>
  </section>

  <section>
    <div class="container">
      <div class="cart-layout">

        <div data-reveal>
          <div class="cart-list" data-cart-list></div>

          <!-- Lege-winkelwagen staat (verborgen zolang er producten in de winkelwagen zitten) -->
          <div class="cart-empty" data-cart-empty hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
            <h3><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je winkelwagen is leeg', 'en' => 'Your cart is empty']) ?></h3>
            <p><?= \App\Service\Language\SiteText::escaped(['nl' => 'Nog niets toegevoegd? Bekijk de shop voor beschikbare producten.', 'en' => 'Nothing added yet? Browse the shop for available products.']) ?></p>
            <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/shop.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar de shop', 'en' => 'Go to shop']) ?></a>
          </div>

          <div style="margin-top:var(--sp-4);">
            <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/shop.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost btn--sm">&larr; <?= \App\Service\Language\SiteText::escaped(['nl' => 'Verder winkelen', 'en' => 'Continue shopping']) ?></a>
          </div>
        </div>

        <aside class="order-summary" data-reveal>
          <h3><?= \App\Service\Language\SiteText::escaped(['nl' => 'Overzicht', 'en' => 'Summary']) ?></h3>
          <div class="order-summary__row">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Subtotaal', 'en' => 'Subtotal']) ?></span>
            <strong data-cart-subtotal>&euro;0,00</strong>
          </div>
          <div class="order-summary__row">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzending', 'en' => 'Shipping']) ?></span>
            <strong><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bepaald bij afrekenen', 'en' => 'Calculated at checkout']) ?></strong>
          </div>
          <div class="order-summary__row order-summary__row--total">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Totaal', 'en' => 'Total']) ?></span>
            <strong data-cart-total>&euro;0,00</strong>
          </div>
          <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/checkout.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--block"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Afrekenen', 'en' => 'Proceed to checkout']) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          <p class="order-summary__note"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Betaling via iDEAL en overige methoden bij Mollie. Prijzen zijn inclusief btw.', 'en' => 'Payment via iDEAL and other methods through Mollie. Prices include VAT.']) ?></p>
        </aside>

      </div>
    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
