<?php

declare(strict_types=1);

require_once __DIR__ . '/product-purchase.php';

use App\Service\FeaturedProductContent;
use App\Service\ProductPurchasePath;
use App\Service\ShopScriptText;

/**
 * Renders ONE Uitgelicht product block (App\Service\FeaturedProductContent):
 * a product of the Shop with its pictures beside its name, price, text,
 * variants and — when the block and the product both allow it — its purchase
 * area, plus a button to its own product page.
 *
 * THE PRODUCT PAGE'S OWN PARTS, NOT A COPY. The pictures, the price, the
 * variant picker, the stock, the order questions, "Uitverkocht" with its
 * back-in-stock form and the add-to-cart row carry the same data-product-*
 * hooks and classes as product.php, inside one [data-product-detail] element,
 * so assets/js/shop/shop.js runs the very same code on them: variants, stock,
 * api/cart-check.php, api/stock-notification.php and the cart. The purchase
 * area is partials/product-purchase.php, shared with the product page. What
 * is new here is only the layout around it (assets/css/shop/featured-product.css).
 *
 * THE PAYLOAD. The product as App\Service\ProductDetail builds it — the same
 * JSON GET /api/product.php answers — is printed into the block, so shop.js
 * draws it at once instead of asking for it. A price the product hides is not
 * in it; with the price switched off and no cart on offer, no price is.
 * json_encode() escapes <, >, & and quotes, so nothing in it can end the
 * script element.
 *
 * Server-side and readable without the script: the name, the block's intro,
 * the description and the specifications. The script adds the pictures, the
 * price and the variant picker, and keeps them in step with the variant.
 *
 * $scope ("<revealGroup>-") prefixes every id the block prints, so two blocks
 * on one page — even with the same product — never share one.
 *
 * Caller must already have checked $content['state'] !==
 * FeaturedProductContent::STATE_HIDDEN. STATE_FALLBACK, and an active block
 * without a product a visitor may see, render nothing: no empty frame, no
 * room.
 *
 * @param array<string, mixed> $content see FeaturedProductContent::forSection()
 */
function render_section_featured_product(array $content, string $revealGroup): void
{
    $product = $content['product'] ?? null;
    if (($content['state'] ?? '') !== FeaturedProductContent::STATE_ACTIVE || !is_array($product)) {
        return;
    }

    $payload = json_encode(
        $product['payload'] ?? null,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($payload) || !is_array($product['payload'] ?? null)) {
        return;
    }

    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $scope = $revealGroup . '-';

    $imageMode = FeaturedProductContent::choice(FeaturedProductContent::IMAGE_MODES, $content['image_mode'] ?? null);
    $position = FeaturedProductContent::choice(FeaturedProductContent::IMAGE_POSITIONS, $content['image_position'] ?? null);
    $size = FeaturedProductContent::choice(FeaturedProductContent::IMAGE_SIZES, $content['image_size'] ?? null, FeaturedProductContent::DEFAULT_IMAGE_SIZE);
    $align = FeaturedProductContent::choice(FeaturedProductContent::ALIGNMENTS, $content['content_align'] ?? null);
    $ordering = FeaturedProductContent::choice(FeaturedProductContent::ORDERINGS, $content['ordering'] ?? null);

    // The defaults add no class: a block with every default is the plain layout.
    $classes = ['featured-product'];
    if ($position !== FeaturedProductContent::DEFAULT_IMAGE_POSITION) {
        $classes[] = 'featured-product--image-' . $position;
    }
    if ($size !== FeaturedProductContent::DEFAULT_IMAGE_SIZE) {
        $classes[] = 'featured-product--image-' . $size;
    }
    if ($align !== FeaturedProductContent::DEFAULT_ALIGNMENT) {
        $classes[] = 'featured-product--align-' . $align;
    }

    $inquiry = !empty($product['inquiry']);
    $path = (string) ($product['purchase_path'] ?? ProductPurchasePath::CART);
    $direct = $ordering === 'direct';
    $name = (string) ($product['name'] ?? '');
    $description = (string) ($product['description'] ?? '');
    $intro = trim((string) ($content['intro'] ?? ''));
    $linkLabel = trim((string) ($content['link_label'] ?? ''));
    $url = (string) ($product['url'] ?? '');
    $specifications = is_array($product['specifications'] ?? null) ? $product['specifications'] : [];
    $transition = (string) ($product['gallery_transition'] ?? '');
    ?>
    <section class="featured-product-section">
      <div class="container">
        <div class="<?= $h(implode(' ', $classes)) ?>" data-product-detail="<?= $h($scope) ?>" data-reveal data-reveal-group="<?= $h($revealGroup) ?>">

          <?php /* The gallery of the product page (assets/js/shop/product-gallery.js):
                   a square stage that keeps its shape, and thumbnail buttons.
                   "Alleen de hoofdafbeelding" has no thumbnail row, and
                   shop.js hands the stage one picture: the first of the
                   product, or of the variant on show. */ ?>
          <div class="product-detail__gallery featured-product__gallery" data-product-gallery data-gallery-transition="<?= $h($transition) ?>"<?= $imageMode === 'main' ? ' data-gallery-main-only' : '' ?>>
            <div class="product-detail__media" data-product-media></div>
            <?php if ($imageMode === 'gallery'): ?>
            <div class="product-detail__thumbs" data-product-thumbs hidden></div>
            <?php endif; ?>
          </div>

          <div class="featured-product__body">
            <?php if (!empty($content['show_name']) && $name !== ''): ?>
            <h2 class="product-detail__title featured-product__title" data-product-name><?= $h($name) ?></h2>
            <?php endif; ?>

            <?php if (!empty($content['show_price'])): ?>
              <?php if ($inquiry): ?>
                <?php if (!$direct): ?>
            <p class="product-detail__price featured-product__price--inquiry"><?= $h(ShopScriptText::text('on_request')) ?></p>
                <?php endif; ?>
              <?php else: ?>
            <p class="product-detail__price" data-product-price></p>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($intro !== ''): ?>
            <p class="featured-product__intro"><?= $h($intro) ?></p>
            <?php endif; ?>

            <div class="product-detail__variants" data-product-variants hidden></div>

            <?php if (!empty($content['show_description'])): ?>
            <div class="product-detail__desc rich-content" data-product-description<?= $description === '' ? ' hidden' : '' ?>><?= $description ?></div>
            <?php endif; ?>

            <?php if ($specifications !== []): ?>
            <div class="product-specs">
              <h3 class="product-specs__heading"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Specificaties', 'en' => 'Specifications']) ?></h3>
              <dl class="product-specs__list">
                <?php foreach ($specifications as $specification): ?>
                  <div class="product-specs__row">
                    <dt><?= $h((string) $specification['name']) ?></dt>
                    <dd><?= $h((string) $specification['value']) ?><?= ($specification['unit'] ?? '') !== '' ? ' ' . $h((string) $specification['unit']) : '' ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
            <?php endif; ?>

            <?php /* The purchase area: what App\Service\ProductPurchasePath
                     allows, and never more than this block's own choice.
                     "Alleen product bekijken" keeps only "Uitverkocht" for
                     the unit on show. */ ?>
            <?php if ($direct && $path === ProductPurchasePath::INQUIRY): ?>
              <?php render_product_inquiry(); ?>
            <?php elseif ($direct && $path === ProductPurchasePath::UNORDERABLE): ?>
              <?php render_product_unorderable(); ?>
            <?php elseif ($direct && $path === ProductPurchasePath::PERSONALIZE): ?>
              <?php render_product_personalize_cue(!empty($product['personalization_required']), $url); ?>
            <?php elseif ($direct && !empty($product['offers_cart'])): ?>
              <?php render_product_add_row(is_array($product['order_questions'] ?? null) ? $product['order_questions'] : [], $scope); ?>
            <?php elseif ($path !== ProductPurchasePath::INQUIRY): ?>
              <?php render_product_sold_out($scope, false); ?>
            <?php endif; ?>

            <?php /* The product's name travels along for a screen reader, so
                     two of these blocks on one page are two different
                     links, not two times "Bekijk product". */ ?>
            <?php if (!empty($content['show_product_link']) && $url !== '' && $linkLabel !== ''): ?>
            <p class="featured-product__link">
              <?php $button = \App\Service\Theme\ButtonStyles::classes(\App\Service\Theme\ButtonStyles::storedChoice($content['button_style'] ?? null), ['btn', 'btn--ghost']); ?>
              <a class="<?= $h($button['class']) ?>" href="<?= $h($url) ?>"><?= $h($linkLabel) ?><?php if ($name !== ''): ?><span class="visually-hidden">: <?= $h($name) ?></span><?php endif; ?></a>
            </p>
            <?php endif; ?>
          </div>

          <script type="application/json" data-product-payload><?= $payload ?></script>
        </div>
      </div>
    </section>
    <?php
}
