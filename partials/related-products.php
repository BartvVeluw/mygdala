<?php

/**
 * Renders the "Gerelateerde producten" section at the bottom of a product
 * detail page (App\Service\RelatedProductsContent::forProduct()).
 *
 * Deliberately NOT a partials/section-*.php file: this is not a page-builder
 * section and nobody adds it to a page. product.php renders it automatically
 * whenever forProduct() returns content, which is why this file has no
 * "state"/hidden handling of its own — a block that must not appear simply
 * never reaches here (the caller has null).
 *
 * There is NO product-card markup in this file. The grid is the shop's own
 * `.shop-grid` + `[data-products-grid]` container, filled by
 * assets/js/shop/shop.js's initShopProducts() from GET /api/products.php —
 * byte-for-byte the same container shop.php and collectie.php use, so image,
 * title, price, link and availability handling all come from the one shop
 * implementation and the responsive behaviour is `.shop-grid`'s (4 columns,
 * 2 below 900px, 1 below 560px). The only addition is `data-product-ids`:
 * the ids this product's collection yielded, already filtered to active
 * products, already in the collection's order and already capped.
 *
 * Those ids are not a trust boundary: api/products.php re-filters them to
 * `active = 1` server-side, exactly as it does for the unfiltered shop grid.
 *
 * There is deliberately NO error paragraph here, unlike the shop and
 * collection grids. Related products are OPTIONAL: a product with none is a
 * perfectly normal product, not an error, and nothing about this block may
 * ever put a message where the customer expected products. The server already
 * renders nothing at all when there is nothing to show
 * (App\Service\RelatedProductsContent::forProduct() returns null — globally
 * off, unknown/inactive product, no eligible collection, no other visible
 * products), and initShopProducts() REMOVES this whole section when the ids
 * resolve to nothing or the request fails. Either way the page simply has no
 * related-products block — no heading, no wrapper, no warning text.
 *
 * @param array<string, mixed> $related see RelatedProductsContent::forProduct()
 */
function render_related_products(array $related): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $productIds = $related['product_ids'] ?? [];
    if ($productIds === []) {
        return;
    }

    // One LocalizedValue with the whole precedence already applied — the
    // collection's own heading, else the shop-wide setting, each with the
    // ordinary language fallback (App\Service\RelatedProductsContent::heading()).
    $heading = $related['heading'];
    ?>
    <?php // .bg-soft is the site's existing "next section, softly separated"
          // modifier (index.php, over-mij.php, diensten.php) — it sets this
          // block apart from the product above it without one line of new
          // CSS. data-related-products is a hook, not styling. ?>
    <section class="bg-soft" data-related-products>
      <div class="container">
        <?php if (\App\Service\Language\SiteText::visibleOf($heading) !== ''): ?>
        <div class="section-head" data-reveal>
          <h2<?= \App\Service\Language\SiteText::attrsOf($heading) ?>><?= $h(\App\Service\Language\SiteText::visibleOf($heading)) ?></h2>
        </div>
        <?php endif; ?>

        <div class="shop-grid" data-products-grid data-product-ids="<?= $h(implode(',', $productIds)) ?>">
          <p class="lead" data-products-loading data-nl="Producten laden&hellip;" data-en="Loading products&hellip;">Producten laden&hellip;</p>
        </div>
      </div>
    </section>
    <?php
}
