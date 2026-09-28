<?php

declare(strict_types=1);

require_once __DIR__ . '/product-order-fields.php';

use App\Service\Language\SiteText;
use App\Service\ShopScriptText;

/**
 * The purchase area of a product, wherever a product can be bought or asked
 * about: the product page (product.php) and the Uitgelicht product block
 * (partials/section-featured-product.php). Which of these a place draws is
 * App\Service\ProductPurchasePath's answer, never its own:
 *
 *   cart         render_product_add_row(): the sold-out block (hidden), the
 *                order questions, the quantity and "Toevoegen aan winkelwagen"
 *   inquiry      render_product_inquiry(): no price, no quantity, no cart
 *   unorderable  render_product_unorderable(): no way to order right now
 *   personalize  render_product_personalize_cue(): the one purchase action is
 *                at the end of the product page's configurator
 *
 * ONE MARKUP, ONE SCRIPT. assets/js/shop/shop.js finds every part by its
 * data-product-* hook inside the [data-product-detail] element around it, so
 * a block and the product page behave identically: the same stock rules, the
 * same order-question check, the same api/cart-check.php before anything is
 * added, the same api/stock-notification.php. There is no second copy of any
 * of it.
 *
 * $scope prefixes every id (and a radio group's name) a part prints, so two
 * products on one page — two blocks — never share one. The product page
 * passes '' and keeps the ids it always had.
 *
 * Every word is the site's own in the language of the page (SiteText, or
 * ShopScriptText for a word the cart script shares), escaped.
 */

/**
 * The quantity stepper plus the add-to-cart button, with the sold-out block
 * beside it and the order questions above it.
 *
 * STOCK (Shop Product & Ordering 2.0): the sold-out block starts hidden.
 * assets/js/shop/shop.js shows it instead of the add row when the chosen unit
 * — the product, or the selected variant — tracks stock and has none left
 * (the payload says so, never the figure itself), and caps the quantity at
 * what is left. Another variant that is in stock stays orderable. The server
 * refuses a sold-out line anyway (api/cart-check.php, api/checkout.php): this
 * only says it before the customer tries.
 *
 * @param list<array<string, mixed>> $orderQuestions App\Service\OrderFields\OrderFields::questions()
 */
function render_product_add_row(array $orderQuestions, string $scope = ''): void
{
    render_product_sold_out($scope, true);
    render_product_order_fields($orderQuestions, $scope);
    ?>
    <div class="product-detail__add-row" data-product-add-row>
      <div class="qty-stepper" data-product-qty>
        <button type="button" data-step="down" aria-label="<?= SiteText::escaped(['nl' => 'Aantal verlagen', 'en' => 'Decrease quantity']) ?>">&minus;</button>
        <input type="number" value="1" min="1" max="20" inputmode="numeric" aria-label="<?= SiteText::escaped(['nl' => 'Aantal', 'en' => 'Quantity']) ?>">
        <button type="button" data-step="up" aria-label="<?= SiteText::escaped(['nl' => 'Aantal verhogen', 'en' => 'Increase quantity']) ?>">+</button>
      </div>
      <button type="button" class="btn" data-product-add-to-cart>
        <span><?= SiteText::escaped(['nl' => 'Toevoegen aan winkelwagen', 'en' => 'Add to cart']) ?></span>
        <svg class="btn-icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
        <svg class="btn-icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12l5 5L20 6"/></svg>
      </button>
    </div>
    <p class="product-detail__add-message" data-product-add-message role="status" hidden></p>
    <?php
}

/**
 * "Uitverkocht", hidden until assets/js/shop/shop.js finds the unit on show
 * sold out. With $withNotify, the TERUG OP VOORRAAD form under it
 * (App\Service\Inventory\StockNotifications): one mail for exactly this unit
 * — the product, or the variant chosen above, which shop.js sends along. No
 * account; the answer is the same whether the address was known or not. The
 * hidden field is a honeypot a person never fills.
 */
function render_product_sold_out(string $scope = '', bool $withNotify = true): void
{
    $emailId = $scope . 'product-notify-email';
    ?>
    <div class="product-detail__sold-out" data-product-sold-out hidden>
      <p class="product-detail__stock-status"><strong><?= htmlspecialchars(ShopScriptText::text('sold_out'), ENT_QUOTES, 'UTF-8') ?></strong></p>
      <?php if ($withNotify): ?>
      <form class="product-detail__notify" data-product-notify novalidate>
        <label for="<?= htmlspecialchars($emailId, ENT_QUOTES, 'UTF-8') ?>"><?= SiteText::escaped(['nl' => 'Mail mij als dit weer beschikbaar is', 'en' => 'Email me when this is available again']) ?></label>
        <div class="product-detail__notify-row">
          <input type="email" id="<?= htmlspecialchars($emailId, ENT_QUOTES, 'UTF-8') ?>" name="email" required maxlength="254" autocomplete="email" placeholder="<?= SiteText::escaped(['nl' => 'jouw@e-mailadres.nl', 'en' => 'your@email.com']) ?>">
          <button type="submit" class="btn btn--ghost"><?= SiteText::escaped(['nl' => 'Houd mij op de hoogte', 'en' => 'Keep me posted']) ?></button>
        </div>
        <input type="text" name="hp-note" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="product-detail__notify-hp">
        <p class="product-detail__notify-note"><?= SiteText::escaped(['nl' => 'We gebruiken je adres alleen voor deze ene melding.', 'en' => 'We only use your address for this one notification.']) ?></p>
        <p class="product-detail__notify-message" data-product-notify-message role="status" hidden></p>
      </form>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * OP AANVRAAG (App\Service\PurchaseMode): where price, quantity and the cart
 * would be, the way to ask instead. The server refuses such a product in the
 * cart and the checkout whatever the browser sends; this is what the visitor
 * sees.
 */
function render_product_inquiry(): void
{
    ?>
    <div class="product-detail__inquiry" data-product-inquiry>
      <p><strong><?= SiteText::escaped(['nl' => 'Prijs en bestellen op aanvraag', 'en' => 'Price and ordering on request']) ?></strong></p>
      <p><?= SiteText::escaped(['nl' => 'Dit product maken we op aanvraag. Vertel ons wat je zoekt, dan hoor je van ons.', 'en' => 'We make this product on request. Tell us what you are looking for and we will get back to you.']) ?></p>
      <p><a class="btn btn--ghost" href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/contact.php'), ENT_QUOTES, 'UTF-8') ?>"><?= SiteText::escaped(['nl' => 'Neem contact op', 'en' => 'Get in touch']) ?></a></p>
    </div>
    <?php
}

/**
 * Personalization-only, but there is nothing to personalize (switched off,
 * or no preview image / no zone yet). There is genuinely no way to order
 * this right now, and saying so beats a button the server would refuse.
 */
function render_product_unorderable(): void
{
    ?>
    <p class="product-detail__personalize-cue">
      <span><?= SiteText::escaped(['nl' => 'Dit product is op dit moment niet te bestellen.', 'en' => 'This product cannot be ordered right now.']) ?></span>
      <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/contact.php'), ENT_QUOTES, 'UTF-8') ?>"><?= SiteText::escaped(['nl' => 'Neem contact op', 'en' => 'Get in touch']) ?></a>
    </p>
    <?php
}

/**
 * A product with a personalization configurator: no add-to-cart here, the
 * single purchase action sits at the end of that configurator, where the
 * customer finishes. A button here would be a second, competing flow — and
 * for a required product, one that skips the configuration they still have
 * to do. On the product page the link goes down to the configurator
 * ($productPath null); anywhere else it goes to the product page's
 * configurator, at $productPath.
 */
function render_product_personalize_cue(bool $required, ?string $productPath = null): void
{
    $href = $productPath === null ? '#personaliseren' : $productPath . '#personaliseren';
    ?>
    <p class="product-detail__personalize-cue">
      <?php if ($required): ?>
        <span><?= SiteText::escaped(['nl' => 'Dit product maak je zelf af.', 'en' => 'You finish this product yourself.']) ?></span>
      <?php else: ?>
        <span><?= SiteText::escaped(['nl' => 'Dit product kun je personaliseren.', 'en' => 'You can personalise this product.']) ?></span>
      <?php endif; ?>
      <?php if ($productPath === null): ?>
      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= SiteText::escaped(['nl' => 'Personaliseer het hieronder', 'en' => 'Personalise it below']) ?></a>
      <?php else: ?>
      <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= SiteText::escaped(['nl' => 'Personaliseer het op de productpagina', 'en' => 'Personalise it on the product page']) ?></a>
      <?php endif; ?>
    </p>
    <?php
}
