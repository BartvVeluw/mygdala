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
require_once __DIR__ . '/partials/product-order-fields.php';


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
 * OP AANVRAAG (App\Service\PurchaseMode, Shop Product & Ordering 2.0): the
 * product is shown — name, pictures, description, the variant picker, its
 * specifications — but it has no price, no quantity and no cart here. The
 * purchase area says so and offers contact instead, and a personalization
 * configurator (a way to order) is not rendered. The server refuses such a
 * product in the cart and the checkout whatever the browser sends; this is
 * what the visitor sees.
 */
$inquiry = $productId > 0 && $seo !== null
    && \App\Service\PurchaseMode::isInquiry((new \App\Repository\ProductRepository())->purchaseMode($productId));
if ($inquiry) {
    $personalization = null;
}

/**
 * BESTELVELDEN (App\Service\OrderFields\OrderFields, Shop Product & Ordering
 * 2.0): the questions this product asks before it goes in the cart, in the
 * language of the page — none for a product that asks nothing, and none for
 * one "op aanvraag", which has no cart to put answers in. Drawn inside the add
 * row (renderProductAddRow()), so they sit above the quantity and the button
 * wherever that row is, the personalization section included.
 */
$productOrderQuestions = ($productId > 0 && $seo !== null && !$inquiry)
    ? (new \App\Service\OrderFields\OrderFields())->questions($productId, \App\Service\Routing\RequestLanguage::current())
    : [];

/**
 * How the gallery changes picture: this product's own choice, else the
 * Shop's default (App\Service\ProductGalleryTransition — the one place that
 * decides it). Always one of none/fade/slide, written into the gallery's
 * data-gallery-transition for assets/js/shop/product-gallery.js, which checks
 * it against the same three words again.
 */
$galleryTransition = \App\Service\ProductGalleryTransition::forProduct($seo !== null ? $productId : 0);

/**
 * The quantity stepper plus the add-to-cart button. Rendered exactly once per
 * page — that is the whole point of it being a function.
 *
 * STOCK (Shop Product & Ordering 2.0): beside it sits the sold-out block,
 * hidden. assets/js/shop/shop.js shows it instead of the add row when the
 * chosen unit — the product, or the selected variant — tracks stock and has
 * none left (api/product.php says so, never the figure itself), and caps the
 * quantity at what is left. Another variant that is in stock stays orderable.
 * The server refuses a sold-out line anyway (api/cart-check.php,
 * api/checkout.php): this only says it before the customer tries.
 */
function renderProductAddRow(): void
{
    global $productOrderQuestions;
    ?>
    <div class="product-detail__sold-out" data-product-sold-out hidden>
      <p class="product-detail__stock-status"><strong><?= htmlspecialchars(\App\Service\ShopScriptText::text('sold_out'), ENT_QUOTES, 'UTF-8') ?></strong></p>
      <?php /* Terug op voorraad (App\Service\Inventory\StockNotifications):
               one mail for exactly this unit — the product, or the variant
               chosen above, which assets/js/shop/shop.js sends along. No
               account; the answer is the same whether the address was known
               or not. The hidden field is a honeypot a person never fills. */ ?>
      <form class="product-detail__notify" data-product-notify novalidate>
        <label for="product-notify-email"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Mail mij als dit weer beschikbaar is', 'en' => 'Email me when this is available again']) ?></label>
        <div class="product-detail__notify-row">
          <input type="email" id="product-notify-email" name="email" required maxlength="254" autocomplete="email" placeholder="<?= \App\Service\Language\SiteText::escaped(['nl' => 'jouw@e-mailadres.nl', 'en' => 'your@email.com']) ?>">
          <button type="submit" class="btn btn--ghost"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Houd mij op de hoogte', 'en' => 'Keep me posted']) ?></button>
        </div>
        <input type="text" name="hp-note" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="product-detail__notify-hp">
        <p class="product-detail__notify-note"><?= \App\Service\Language\SiteText::escaped(['nl' => 'We gebruiken je adres alleen voor deze ene melding.', 'en' => 'We only use your address for this one notification.']) ?></p>
        <p class="product-detail__notify-message" data-product-notify-message role="status" hidden></p>
      </form>
    </div>
    <?php render_product_order_fields($productOrderQuestions ?? []); ?>
    <div class="product-detail__add-row" data-product-add-row>
      <div class="qty-stepper" data-product-qty>
        <button type="button" data-step="down" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Aantal verlagen', 'en' => 'Decrease quantity']) ?>">&minus;</button>
        <input type="number" value="1" min="1" max="20" inputmode="numeric" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Aantal', 'en' => 'Quantity']) ?>">
        <button type="button" data-step="up" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Aantal verhogen', 'en' => 'Increase quantity']) ?>">+</button>
      </div>
      <button type="button" class="btn" data-product-add-to-cart>
        <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Toevoegen aan winkelwagen', 'en' => 'Add to cart']) ?></span>
        <svg class="btn-icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
        <svg class="btn-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>
      </button>
    </div>
    <p class="product-detail__add-message" data-product-add-message role="status" hidden></p>
    <?php
}

$siteName = \App\Service\SiteSettings::get('site_name');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// WHERE THIS PRODUCT LIVES IN EACH LANGUAGE, for the language switch and for
// hreflang (docs/multilingual/ROUTING.md). A product has no slug: its identity
// is the id in the query string, and the switch's assumed paths carry the
// path only, so undeclared it offered /en/product.php — the bare route, not
// this product. App\Service\ProductSeo builds each version from the validated
// integer, so no other parameter of this request can travel along.
//
// Declared for every id the URL names, found or not: the same id is the same
// product in every language, and the switch on a product that is gone leads
// to that language's answer for it (the same 404), never to a different
// page. hreflang follows only for a found product, because only that head
// has a canonical. An id that is not a positive integer names no product and
// declares nothing, so the switch offers the bare route.
if ($productId > 0) {
    \App\Service\Routing\LanguageAlternates::declareVersions(\App\Service\ProductSeo::alternates($productId));
}

// An inactive or unknown product renders a "not found" head — a title,
// noindex,follow and nothing else — and the found case goes through the
// shop's own resolver. Both end up in partials/seo-head.php, so the two
// branches cannot drift into different tag sets the way they used to.
if ($seo === null) {
    $seoMetadata = \App\Service\SeoMetadata::notFound(
        \App\Service\Language\SiteText::pick(['nl' => 'Product niet gevonden', 'en' => 'Product not found']) . ' — ' . $siteName
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
// The gallery controller first: shop.js hands it the pictures once the
// product has loaded.
\App\Service\PageAssets::requireScript('assets/js/shop/product-gallery.js');
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
        \App\Service\ShopOverview::extendTrail(\App\Service\Breadcrumbs\BreadcrumbTrail::home())
            ->to($seo === null
                ? \App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Product', 'en' => 'Product']))
                : \App\Service\Breadcrumbs\BreadcrumbItem::current((string) $seo['name']))
    );
  ?>

  <section>
    <div class="container">

      <p class="lead" data-product-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Product laden…', 'en' => 'Loading product…']) ?></p>

      <p class="lead" data-product-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit product kan niet worden gevonden of is niet meer beschikbaar. Probeer het later opnieuw of bekijk de andere producten in de shop.', 'en' => 'This product can\'t be found or is no longer available. Please try again later or browse the other products in the shop.']) ?></p>

      <div class="product-detail" data-product-content hidden>

        <?php /* The big picture's frame is the gallery's STAGE: it keeps its
                 square whatever picture is on show, holds the outgoing and
                 the incoming picture during a transition, and is where a
                 swipe is read (assets/js/shop/product-gallery.js). The
                 thumbnails are real buttons and always work too. */ ?>
        <div class="product-detail__gallery" data-product-gallery data-gallery-transition="<?= htmlspecialchars($galleryTransition, ENT_QUOTES, 'UTF-8') ?>">
          <div class="product-detail__media" data-product-media></div>
          <div class="product-detail__thumbs" data-product-thumbs hidden></div>
        </div>

        <div>
          <h1 class="product-detail__title" data-product-name></h1>
          <p class="product-detail__price" data-product-price></p>

          <div class="product-detail__variants" data-product-variants hidden></div>

          <?php /* A <div>, not a <p>: the description is rich text with paragraphs
                   and lists of its own, and .rich-content gives its links,
                   lists and headings the site's one rich-text styling. */ ?>
          <div class="product-detail__desc rich-content" data-product-description hidden></div>

          <?php /* THE purchase action lives at the BOTTOM of the
                   personalization section whenever this product has one, so
                   the customer never has to scroll back up after finishing.
                   There is exactly one add-to-cart on the page either way —
                   renderProductAddRow() is called from exactly one of these
                   two places. */ ?>
          <?php if ($inquiry): ?>
            <div class="product-detail__inquiry" data-product-inquiry>
              <p><strong><?= \App\Service\Language\SiteText::escaped(['nl' => 'Prijs en bestellen op aanvraag', 'en' => 'Price and ordering on request']) ?></strong></p>
              <p><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit product maken we op aanvraag. Vertel ons wat je zoekt, dan hoor je van ons.', 'en' => 'We make this product on request. Tell us what you are looking for and we will get back to you.']) ?></p>
              <p><a class="btn btn--ghost" href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/contact.php'), ENT_QUOTES, 'UTF-8') ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Neem contact op', 'en' => 'Get in touch']) ?></a></p>
            </div>
          <?php elseif ($unorderable): ?>
            <?php /* Personalization-only, but there is nothing to personalize
                     (switched off, or no preview image / no zone yet). There
                     is genuinely no way to order this right now, and saying
                     so beats a button the server would refuse. */ ?>
            <p class="product-detail__personalize-cue">
              <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit product is op dit moment niet te bestellen.', 'en' => 'This product cannot be ordered right now.']) ?></span>
              <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/contact.php'), ENT_QUOTES, 'UTF-8') ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Neem contact op', 'en' => 'Get in touch']) ?></a>
            </p>
          <?php elseif ($personalization !== null): ?>
            <?php /* No add-to-cart here: the single purchase action sits at
                     the end of the configurator below, where the customer
                     finishes. A button here would be a second, competing
                     flow — and for a required product, one that skips the
                     configuration they still have to do. */ ?>
            <p class="product-detail__personalize-cue">
              <?php if ($personalizationRequired): ?>
                <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit product maak je zelf af.', 'en' => 'You finish this product yourself.']) ?></span>
              <?php else: ?>
                <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit product kun je personaliseren.', 'en' => 'You can personalise this product.']) ?></span>
              <?php endif; ?>
              <a href="#personaliseren"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Personaliseer het hieronder', 'en' => 'Personalise it below']) ?></a>
            </p>
          <?php else: ?>
            <?php renderProductAddRow(); ?>
          <?php endif; ?>
        </div>

      </div>

      <div style="margin-top:var(--sp-5);">
        <?php if (\App\Service\ShopOverview::url() !== null): ?>
        <a href="<?= htmlspecialchars(\App\Service\ShopOverview::url(), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Terug naar producten', 'en' => 'Back to products']) ?></a>
        <?php endif; ?>
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
