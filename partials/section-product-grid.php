<?php

/**
 * Renders the Shop's product grid — extracted verbatim from shop.php when
 * the page builder became one ordered list per page and this fixed template
 * block became a real, positioned block instance
 * (App\Service\SectionRegistry's `product_grid`).
 *
 * The grid itself is fetched client-side by assets/js/shop/shop.js; products,
 * photos and stock are managed via Producten.
 *
 * NO HEADING OF ITS OWN. It used to print a fixed "Alle producten" whenever
 * collection tiles were on the shop, words no editor had typed and none could
 * change or remove. The block has no title field, so it prints no title at
 * all (Content Blocks Polish 1); an editor who wants one puts a Tekstblok
 * above it.
 *
 * The closing "Zoek je iets specifieks?" paragraph used to be hardcoded
 * here. Phase 2 moved it into an ordinary Rich text block directly below
 * this one (db/migrations/20260908270300_move_the_shop_note_into_a_rich_text_block.php)
 * — editable, reorderable and removable like any other text, and no CMS
 * field of its own was needed for it.
 */
function render_section_product_grid(): void
{
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <div class="shop-grid" data-products-grid>
        <p class="lead" data-products-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten laden…', 'en' => 'Loading products…']) ?></p>
      </div>
      <p class="lead" data-products-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier.', 'en' => 'Products can\'t be loaded right now. Please try again later or get in touch via the quote form.']) ?></p>
    </div>
  </section>
  <?php
}
