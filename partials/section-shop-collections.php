<?php

/**
 * Renders the Shop's collection tiles (App\Service\CollectionContent) —
 * extracted verbatim from shop.php when the page builder became one ordered
 * list per page and this fixed template block became a real, positioned
 * block instance (App\Service\SectionRegistry's `shop_collections`).
 *
 * Rendered server-side (unlike the product grid, which is fetched) because
 * there are only a handful of collections and they carry this page's
 * internal links: they should exist in the HTML.
 *
 * activeForShop() already excludes inactive collections and collections with
 * no active products, so this whole block disappears on its own until the
 * owner has actually published one. Which collections exist and in what
 * order stays managed via Collecties, not as page content — this block only
 * decides WHERE on the page the tiles render.
 *
 * The collections arrive as an argument, read by
 * App\Service\Blocks\ShopCollectionsBlock::render(), so this file only
 * renders and the block library can show it with sample tiles.
 *
 * @param list<array<string, mixed>> $shopCollections see CollectionContent::activeForShop()
 */
function render_section_shop_collections(array $shopCollections): void
{
    if ($shopCollections === []) {
        return;
    }
    ?>
  <section style="padding-top:0;">
    <div class="container">
      <h2 class="collection-tiles__heading" data-nl="Collecties" data-en="Collections">Collecties</h2>
      <div class="collection-tiles">
        <?php foreach ($shopCollections as $shopCollection): ?>
          <?php
            $collectionH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            // A tile's words arrive as one LocalizedValue per field
            // (Multilingual 2.0 phase 5 wave C); the teaser is that value with
            // the excerpt rule applied to each half.
            $collectionName = $shopCollection['name'];
            $collectionTeaser = \App\Service\Language\LocalizedValue::of([
                \App\Service\Language\LanguageRegistry::DUTCH => \App\Service\CollectionContent::excerpt($shopCollection['description']->in(\App\Service\Language\LanguageRegistry::DUTCH), 90),
                \App\Service\Language\LanguageRegistry::ENGLISH => \App\Service\CollectionContent::excerpt($shopCollection['description']->in(\App\Service\Language\LanguageRegistry::ENGLISH), 90),
            ]);
          ?>
          <a class="collection-tile" href="<?= $collectionH($shopCollection['url']) ?>" data-reveal data-reveal-group="collections">
            <div class="collection-tile__media">
              <?php if ($shopCollection['image_path'] !== null): ?>
                <img src="/<?= $collectionH(ltrim($shopCollection['image_path'], '/')) ?>" alt="" loading="lazy">
              <?php else: ?>
                <?php // Same generic keyring glyph a product card falls back to
                      // when it has no photo (assets/js/shop/shop.js's
                      // genericProductIcon) — one visual language for "no image
                      // yet" across the shop. ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round" aria-hidden="true"><circle cx="8" cy="6" r="3.2"/><path d="M10.3 8.3L20 18l-2.5 2.5L8 10.7"/><path d="M14 14l3 3"/></svg>
              <?php endif; ?>
            </div>
            <div class="collection-tile__body">
              <h3 class="collection-tile__name"<?= \App\Service\Language\SiteText::attrsOf($collectionName) ?>><?= $collectionH(\App\Service\Language\SiteText::visibleOf($collectionName)) ?></h3>
              <?php if (\App\Service\Language\SiteText::visibleOf($collectionTeaser) !== ''): ?>
                <p class="collection-tile__desc"<?= \App\Service\Language\SiteText::attrsOf($collectionTeaser) ?>><?= $collectionH(\App\Service\Language\SiteText::visibleOf($collectionTeaser)) ?></p>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php
}
