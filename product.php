<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');

require_once __DIR__ . '/partials/breadcrumb.php';
require_once __DIR__ . '/partials/related-products.php';
require_once __DIR__ . '/partials/product-personalization.php';


/**
 * "Gerelateerde producten" is resolved server-side, unlike the product's own
 * content (which assets/js/shop/shop.js fetches from /api/product.php): which
 * collection a product belongs to, whether that collection has related
 * products enabled, and how many to show are all CMS configuration, so the
 * decision — and the resulting ordered product ids — belong on the server.
 * The CARDS are still built by the shop's one renderer; see
 * partials/related-products.php.
 *
 * Returns null for anything that must not show a related-products section
 * (globally off, unknown/inactive product, no eligible collection, no other
 * visible products), in which case nothing at all is rendered below — no
 * heading, no empty grid.
 */
$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$productId = ($productId === null || $productId === false) ? 0 : $productId;

$relatedProducts = $productId > 0
    ? \App\Service\RelatedProductsContent::forProduct($productId)
    : null;

/**
 * The product's own SEO metadata is resolved SERVER-SIDE, even though the
 * visible product content is still fetched from /api/product.php by
 * assets/js/shop/shop.js: a <head> that only exists after JavaScript has run is
 * no use to a crawler, a link preview or a share button. $seo therefore
 * carries the resolved title/description/canonical/social image and the
 * Product JSON-LD — all derived from the same `products` row the API will
 * return, so the structured data can never describe something other than
 * what the page shows.
 *
 * null means "no such product, or it is not active". The page then renders a
 * noindex head with no canonical, no Open Graph and no structured data at
 * all, so a deactivated product cannot keep advertising itself to search
 * engines — exactly how collectie.php treats an unpublished collection.
 *
 * The STATUS CODE distinguishes two different situations:
 *   - a URL that NAMES a product which isn't there (deactivated, deleted,
 *     or never existed) answers 404. Without that, a removed product would
 *     be a soft 404: an indexable-looking 200 for a page with nothing on it,
 *     and a URL the sitemap must never be able to contain.
 *   - a URL that names no product at all (/product.php, ?id=, ?id=abc) keeps
 *     answering 200: the route itself resolves and renders its normal shell,
 *     nothing is missing. That is this project's existing behaviour for this
 *     route and stays untouched.
 */
$seo = $productId > 0 ? \App\Service\ProductSeo::forPublicPage($productId) : null;

if ($seo === null && $productId > 0) {
    http_response_code(404);
}

/**
 * Personalisatie is resolved server-side for the same reason "Gerelateerde
 * producten" is: whether this product can be personalized at all, what a
 * customer may add and where the engraving area sits are CMS configuration,
 * not product content — so the decision belongs on the server and the panel
 * is rendered from it, never assembled from a second API round trip.
 *
 * null means "this product has no personalization" (never configured, switched
 * off, or missing a preview image), and then NOTHING is rendered: a normal
 * product's page keeps exactly the markup it has today, down to the byte.
 * $seo === null is deliberately included in that: a deactivated or unknown
 * product must not advertise a personalization panel either.
 */
$personalization = ($productId > 0 && $seo !== null)
    ? \App\Service\Personalization\ProductPersonalizationContent::forProduct($productId)
    : null;

/**
 * Whether this product may ONLY be bought personalized. When it may not, the
 * page has exactly ONE purchase action and it lives inside the personalization
 * section, underneath the configurator the customer has to complete — never a
 * second "Toevoegen aan winkelwagen" higher up that would quietly bypass it.
 *
 * The button is the same markup either way (renderProductAddRow() below), so
 * assets/js/shop/shop.js finds it by the same hook and behaves identically; only
 * WHERE it is rendered changes. The rule itself is enforced on the server by
 * App\Service\Personalization\PersonalizationValidator, which rejects an
 * unpersonalized line for such a product whatever the browser sent.
 */
$personalizationRequired = $personalization !== null && ($personalization['is_required'] ?? false) === true;

/**
 * Whether this product may be bought the ORDINARY way at all. False for a
 * personalization-only product (`products.in_shop = 0`), which has no
 * ordinary purchase path by definition — see
 * db/migrations/20260908220000_add_product_availability_channels.php.
 *
 * A personalization-only product whose configuration is renderable needs no
 * special handling here: it resolves to `is_required`, so the single purchase
 * action already lives inside the configurator. This flag exists for the one
 * case that cannot: a personalization-only product whose personalization is
 * switched off or unfinished has NOTHING to configure and no ordinary way to
 * buy it, so the page must say so rather than show an add-to-cart button that
 * api/checkout.php would refuse.
 */
$shopPurchasable = $productId > 0 && $seo !== null
    ? (new \App\Repository\ProductRepository())->isShopPurchasable($productId)
    : true;
$unorderable = !$shopPurchasable && $personalization === null;

/**
 * The quantity stepper plus the add-to-cart button. Rendered exactly once per
 * page — that is the whole point of it being a function.
 */
function renderProductAddRow(): void
{
    ?>
    <div class="product-detail__add-row">
      <div class="qty-stepper" data-product-qty>
        <button type="button" data-step="down" aria-label="Aantal verlagen" data-nl-aria="Aantal verlagen" data-en-aria="Decrease quantity">&minus;</button>
        <input type="number" value="1" min="1" max="20" inputmode="numeric" aria-label="Aantal" data-nl-aria="Aantal" data-en-aria="Quantity">
        <button type="button" data-step="up" aria-label="Aantal verhogen" data-nl-aria="Aantal verhogen" data-en-aria="Increase quantity">+</button>
      </div>
      <button type="button" class="btn" data-product-add-to-cart>
        <span data-nl="Toevoegen aan winkelwagen" data-en="Add to cart">Toevoegen aan winkelwagen</span>
        <svg class="btn-icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
        <svg class="btn-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>
      </button>
    </div>
    <?php
}

$siteName = \App\Service\SiteSettings::get('site_name');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// An inactive or unknown product renders a "not found" head — a title,
// noindex,follow and nothing else — and the found case goes through the
// shop's own resolver. Both end up in partials/seo-head.php, so the two
// branches cannot drift into different tag sets the way they used to.
if ($seo === null) {
    $seoMetadata = \App\Service\SeoMetadata::notFound(
        'Product niet gevonden — ' . $siteName,
        'Product not found — ' . $siteName
    );
    require __DIR__ . '/partials/seo-head.php';
} else {
    // og:type stays "website" — the value this page has always emitted.
    // Switching it to "product" would be more precise but is a change to
    // live metadata that nothing in this step needs; SEO.md records it as
    // deliberately left alone.
    require __DIR__ . '/partials/shop-seo-head.php';
}
?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
//
// The engraving workspace comes first and only for a product that actually
// offers personalization, exactly as before: the editor registers
// window.VVLPersonalization, and the add-to-cart handler in the Shop's own
// script looks for it. Every other page — and every normal product — never
// downloads either file.
if ($personalization !== null) {
    \App\Service\PageAssets::requireStyle('assets/css/shop/personalization.css');
    \App\Service\PageAssets::requireScript('assets/js/personalization.js');
}
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


<main id="main" data-product-detail>

  <?php
    /**
     * The product's own name, server-side. It comes from the same `products`
     * row App\Service\ProductSeo already resolved for the <head>, so the trail
     * cannot describe a different product than the page does — and a visitor
     * without JavaScript, a crawler and a link preview all get the real name
     * instead of the word "Product". assets/js/shop/shop.js no longer touches
     * it for exactly that reason.
     *
     * A URL naming no product, or one that is not active, keeps the generic
     * last level: there is no name to print, and the page below says so.
     */
    render_breadcrumb(
        \App\Service\Breadcrumbs\BreadcrumbTrail::home()
            ->toPage('shop', 'shop')
            ->to($seo === null
                ? \App\Service\Breadcrumbs\BreadcrumbItem::current('Product', 'Product')
                : \App\Service\Breadcrumbs\BreadcrumbItem::current((string) $seo['name_nl'], (string) $seo['name_en']))
    );
  ?>

  <section>
    <div class="container">

      <p class="lead" data-product-loading data-nl="Product laden…" data-en="Loading product…">Product laden…</p>

      <p class="lead" data-product-error hidden data-nl="Dit product kan niet worden gevonden of is niet meer beschikbaar. Probeer het later opnieuw of bekijk de andere producten in de shop." data-en="This product can't be found or is no longer available. Please try again later or browse the other products in the shop.">Dit product kan niet worden gevonden of is niet meer beschikbaar. Probeer het later opnieuw of bekijk de andere producten in de shop.</p>

      <div class="product-detail" data-product-content hidden>

        <div class="product-detail__gallery">
          <div class="product-detail__media" data-product-media></div>
          <div class="product-detail__thumbs" data-product-thumbs hidden></div>
        </div>

        <div>
          <h1 class="product-detail__title" data-product-name></h1>
          <p class="product-detail__price" data-product-price></p>

          <div class="product-detail__variants" data-product-variants hidden></div>

          <p class="product-detail__desc" data-product-description hidden></p>

          <?php /* THE purchase action lives at the BOTTOM of the
                   personalization section whenever this product has one, so
                   the customer never has to scroll back up after finishing.
                   There is exactly one add-to-cart on the page either way —
                   renderProductAddRow() is called from exactly one of these
                   two places. */ ?>
          <?php if ($unorderable): ?>
            <?php /* Personalization-only, but there is nothing to personalize
                     (switched off, or no preview image / no zone yet). There
                     is genuinely no way to order this right now, and saying
                     so beats a button the server would refuse. */ ?>
            <p class="product-detail__personalize-cue">
              <span data-nl="Dit product is op dit moment niet te bestellen." data-en="This product cannot be ordered right now.">Dit product is op dit moment niet te bestellen.</span>
              <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/contact.php'), ENT_QUOTES, 'UTF-8') ?>" data-nl="Neem contact op" data-en="Get in touch">Neem contact op</a>
            </p>
          <?php elseif ($personalization !== null): ?>
            <?php /* No add-to-cart here: the single purchase action sits at
                     the end of the configurator below, where the customer
                     finishes. A button here would be a second, competing
                     flow — and for a required product, one that skips the
                     configuration they still have to do. */ ?>
            <p class="product-detail__personalize-cue">
              <?php if ($personalizationRequired): ?>
                <span data-nl="Dit product maak je zelf af." data-en="You finish this product yourself.">Dit product maak je zelf af.</span>
              <?php else: ?>
                <span data-nl="Dit product kun je personaliseren." data-en="You can personalise this product.">Dit product kun je personaliseren.</span>
              <?php endif; ?>
              <a href="#personaliseren" data-nl="Personaliseer het hieronder" data-en="Personalise it below">Personaliseer het hieronder</a>
            </p>
          <?php else: ?>
            <?php renderProductAddRow(); ?>
          <?php endif; ?>
        </div>

      </div>

      <div style="margin-top:var(--sp-5);">
        <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/shop.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost" data-nl="Terug naar producten" data-en="Back to products">Terug naar producten</a>
      </div>

    </div>
  </section>

  <?php
  /**
   * Personalisatie is its OWN full-width section, below the normal product
   * area and at the site's ordinary content width — not a panel squeezed into
   * the product-information column. It is a workspace: it needs a large
   * preview, room for its controls, and a heading of its own, and none of
   * those fit in half a column beside the gallery.
   *
   * The normal product presentation above is completely untouched by it: the
   * gallery, title, price, variants and description render exactly as they do
   * for any other product.
   */
  if ($personalization !== null) {
      render_product_personalization($personalization, 'renderProductAddRow');
  }

  // After the product's own content, before the footer — the position the
  // CMS never has to configure.
  if ($relatedProducts !== null) {
      render_related_products($relatedProducts);
  }
  ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
