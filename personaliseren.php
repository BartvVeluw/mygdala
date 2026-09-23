<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('personalization');
require_once __DIR__ . '/partials/breadcrumb.php';


/**
 * The public Personalisatie catalogue — /personaliseren.
 *
 * Lists every product the owner put in the personalization channel that can
 * ACTUALLY be personalized right now (see
 * App\Service\Personalization\PersonalizationCatalog). That includes products
 * which do not appear in the ordinary shop at all: a blank keychain that only
 * exists to be engraved belongs here and nowhere else.
 *
 * Deliberately the SAME `.shop-grid` + `[data-products-grid]` markup /shop.php
 * and /collectie.php use, filled by assets/js/shop/shop.js's initShopProducts()
 * from GET /api/products.php?ids=… — byte-for-byte the same product card, so
 * image, title, price, link and availability handling all come from the one
 * shop implementation and this page adds no second card design. The ids are
 * resolved server-side because "which products offer personalization" is CMS
 * configuration, exactly like "Gerelateerde producten".
 *
 * `?ids=` is not a trust boundary: api/products.php re-filters to
 * `active = 1` server-side, so a tampered list can only ever return products
 * that are already publicly visible.
 *
 * A fixed template page, not a CMS page: it has no page_sections and no
 * `pages` row, exactly like collectie.php and portfolio-detail.php. Its SEO
 * head is the shared partials/shop-seo-head.php block, so there is no
 * parallel SEO implementation here either.
 */

$catalog = \App\Service\Personalization\PersonalizationCatalog::forPublicPage();

// The catalogue is one fixed route, answered in every published language, so
// each language's address is a real version of it — declared, so hreflang and
// the language switch name exactly those (docs/multilingual/ROUTING.md). An
// empty catalogue advertises nothing: the sitemap leaves it out as well.
if ($catalog !== null) {
    $personalizationVersions = [];
    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $personalizationLanguage) {
        $personalizationVersions[$personalizationLanguage] = \App\Service\Routing\LocalizedUrl::path(
            \App\Service\Personalization\PersonalizationCatalog::publicPath(),
            $personalizationLanguage
        );
    }
    \App\Service\Routing\LanguageAlternates::declareVersions($personalizationVersions);
}

$siteName = \App\Service\SiteSettings::get('site_name');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$seo = [
    'title' => \App\Service\Language\SiteText::pick(['nl' => 'Personaliseren', 'en' => 'Personalise']) . ' — ' . $siteName,
    'description' => \App\Service\Language\SiteText::pick([
        'nl' => 'Ontdek welke producten je zelf kunt personaliseren met je eigen naam, tekst of afbeelding. Bekijk het live voorbeeld voordat je bestelt.',
        'en' => 'Discover which products you can personalise with your own name, text or image. See a live preview before you order.',
    ]),
    // This route in the request's own language: /en/personaliseren.php is its
    // own version, not a copy of the default language's (ROUTING.md, §10).
    'canonical_url' => \App\Service\Routing\LocalizedUrl::absolute(
        \App\Service\Personalization\PersonalizationCatalog::publicPath()
    ),
    'og_image_path' => null,
    'json_ld' => null,
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require __DIR__ . '/partials/shop-seo-head.php'; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
// The same .shop-grid markup /shop.php uses, filled by the same code.
\App\Service\PageAssets::requireStyle('assets/css/shop/shop.css');
\App\Service\PageAssets::requireScript('assets/js/shop/shop.js');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'personaliseren';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

  <?php render_breadcrumb(
      \App\Service\ShopOverview::extendTrail(\App\Service\Breadcrumbs\BreadcrumbTrail::home())
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Personaliseren', 'en' => 'Personalise'])))
  ); ?>

  <section class="page-hero">
    <div class="container">
      <div class="section-head" data-reveal>
        <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Op maat', 'en' => 'Made to order']) ?></p>
        <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Personaliseer je product', 'en' => 'Personalise your product']) ?></h1>
        <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Deze producten maak je zelf af: kies je tekst of je eigen afbeelding, kies een lettertype en zie meteen hoe de gravure eruit komt te zien.', 'en' => 'You finish these products yourself: choose your text or your own image, pick a font, and see straight away how the engraving will look.']) ?></p>
      </div>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container">
      <?php if ($catalog === null): ?>
        <?php /* No empty grid and no error: there is simply nothing to
                 personalize yet, and the page says so in the customer's own
                 words. */ ?>
        <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Er zijn op dit moment geen producten om te personaliseren. Kijk gerust rond in de shop — of vraag een offerte aan voor volledig maatwerk.', 'en' => 'There are no products to personalise right now. Feel free to browse the shop — or request a quote for fully custom work.']) ?></p>
        <p style="margin-top:var(--sp-4);">
          <?php if (\App\Service\ShopOverview::url() !== null): ?>
          <a href="<?= $h(\App\Service\ShopOverview::url()) ?>" class="btn btn--ghost"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar de shop', 'en' => 'To the shop']) ?></a>
          <?php endif; ?>
        </p>
      <?php else: ?>
        <div class="shop-grid" data-products-grid data-product-ids="<?= $h(implode(',', $catalog['product_ids'])) ?>">
          <p class="lead" data-products-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten laden…', 'en' => 'Loading products…']) ?></p>
        </div>
        <p class="lead" data-products-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw.', 'en' => 'Products can\'t be loaded right now. Please try again later.']) ?></p>

        <p class="lead" style="margin-top:var(--sp-6); max-width: 60ch;" data-reveal><?= \App\Service\Language\SiteText::escaped(['nl' => 'Staat jouw idee er niet tussen? Voor volledig maatwerk kun je een offerte aanvragen.', 'en' => 'Don\'t see your idea here? For fully custom work you can request a quote.']) ?></p>
      <?php endif; ?>
    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
