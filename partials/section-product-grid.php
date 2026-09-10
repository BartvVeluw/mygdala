<?php

/**
 * Renders the Shop's product grid — extracted verbatim from shop.php when
 * the page builder became one ordered list per page and this fixed template
 * block became a real, positioned block instance
 * (App\Service\SectionRegistry's `product_grid`).
 *
 * The grid itself is fetched client-side by assets/js/shop/shop.js; products,
 * photos and stock are managed via Producten. The "Alle producten" heading
 * only appears when the collection tiles above it do (identical condition to
 * the original template — CollectionContent::activeForShop() is cached, so
 * asking a second time costs no extra query).
 *
 * The closing "Zoek je iets specifieks?" paragraph used to be hardcoded
 * here. Phase 2 moved it into an ordinary Rich text block directly below
 * this one (db/migrations/20260908270300_move_the_shop_note_into_a_rich_text_block.php)
 * — editable, reorderable and removable like any other text, and no CMS
 * field of its own was needed for it.
 */
function render_section_product_grid(): void
{
    $shopCollections = \App\Service\CollectionContent::activeForShop();
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <?php if ($shopCollections !== []): ?>
        <h2 class="collection-tiles__heading" data-nl="Alle producten" data-en="All products">Alle producten</h2>
      <?php endif; ?>
      <div class="shop-grid" data-products-grid>
        <p class="lead" data-products-loading data-nl="Producten laden…" data-en="Loading products…">Producten laden…</p>
      </div>
      <p class="lead" data-products-error hidden data-nl="Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier." data-en="Products can't be loaded right now. Please try again later or get in touch via the quote form.">Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier.</p>
    </div>
  </section>
  <?php
}
