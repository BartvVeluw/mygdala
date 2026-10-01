<?php

require_once __DIR__ . '/section-head.php';

/**
 * Renders the Shop's product grid — extracted verbatim from shop.php when
 * the page builder became one ordered list per page and this fixed template
 * block became a real, positioned block instance
 * (App\Service\SectionRegistry's `product_grid`).
 *
 * The grid itself is fetched client-side by assets/js/shop/shop.js; products,
 * photos and stock are managed via Producten.
 *
 * ITS OWN HEAD, OPTIONAL. It used to print a fixed "Alle producten" whenever
 * collection tiles were on the shop, words no editor had typed and none could
 * change or remove (gone since Content Blocks Polish 1). Now the editor may
 * give it an eyebrow, a title and a text of its own
 * (App\Service\Blocks\BlockHead, partials/section-head.php). Without any, the
 * block prints exactly what it always did: no head wrapper, and no top
 * spacing, since it sits under a page header or the collection tiles. With a
 * head it is an ordinary section with its own room above that head. A
 * product card's name is an h3 under a title of the block, else an h2: the
 * grid tells shop.js so in data-card-heading (App\Service\Blocks\CardHeading).
 *
 * The closing "Zoek je iets specifieks?" paragraph used to be hardcoded
 * here. Phase 2 moved it into an ordinary Rich text block directly below
 * this one (db/migrations/20260908270300_move_the_shop_note_into_a_rich_text_block.php)
 * — editable, reorderable and removable like any other text, and no CMS
 * field of its own was needed for it.
 *
 * @param array<string, mixed> $head 'eyebrow', 'title', 'lead' (App\Service\ShopListingContent); none = no head
 */
function render_section_product_grid(array $head = []): void
{
    $hasHead = \App\Service\Blocks\BlockHead::has($head);
    ?>
  <section<?= $hasHead ? '' : ' style="padding-top:0;"' ?>>
    <div class="container">
<?php render_section_head($head); ?>
      <div class="shop-grid" data-products-grid data-card-heading="<?= \App\Service\Blocks\CardHeading::under(trim((string) ($head['title'] ?? '')) !== '') ?>">
        <p class="lead" data-products-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten laden…', 'en' => 'Loading products…']) ?></p>
      </div>
      <p class="lead" data-products-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Producten kunnen op dit moment niet worden geladen. Probeer het later opnieuw of neem contact op via het offerteformulier.', 'en' => 'Products can\'t be loaded right now. Please try again later or get in touch via the quote form.']) ?></p>
    </div>
  </section>
  <?php
}
