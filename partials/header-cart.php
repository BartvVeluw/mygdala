<?php

/**
 * The Shop's mini-cart, rendered into the shared public header's action area.
 *
 * It is included by partials/header.php through
 * App\Module\ShopModule::headerPartials(), which is the only reason the header
 * knows a cart exists: with the Shop switched off nothing includes this file,
 * no cart markup reaches the page, and the Shop's cart CSS/JS are not
 * requested either (App\Module\ShopModule::shellStyles()).
 *
 * IT ALWAYS RENDERS EMPTY. assets/js/shop/cart.js replaces this markup with
 * the visitor's real cart the moment it runs (renderCartHeader()), so the
 * server-rendered state is only ever what a visitor sees for one frame — and
 * every visitor's true first-frame state, on a first visit, is an empty cart.
 *
 * It used to render a placeholder line item instead: an engraved birch
 * keychain at EUR 14,95, on every page except product.php and
 * bestelling-status.php. That was one shop's product copy shipped inside the
 * shared header, so a brand-new installation with an empty catalogue showed a
 * product it had never heard of and a subtotal it could not explain. The
 * empty state below is the same markup and the same sentence cart.js renders
 * for a genuinely empty cart, so the hand-over is now invisible instead of a
 * visible jump from one product to none.
 *
 * Expects the including header to have prepared:
 *
 *   $h  the header's htmlspecialchars() helper.
 */
?>
        <div class="cart-trigger" data-cart-trigger>
          <button type="button" class="cart-trigger__btn" aria-haspopup="true" aria-expanded="false" aria-controls="cart-dropdown" aria-label="Winkelwagen" data-nl-aria="Winkelwagen" data-en-aria="Shopping cart">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="10" cy="20" r="1.4"/><circle cx="17" cy="20" r="1.4"/></svg>
            <span class="cart-trigger__badge" data-cart-count>0</span>
          </button>
          <div class="cart-dropdown" id="cart-dropdown" data-cart-dropdown>
            <div class="cart-dropdown__head">
              <p data-nl="Winkelwagen" data-en="Shopping cart">Winkelwagen</p>
              <span class="cart-dropdown__count" data-nl="0 producten" data-en="0 items">0 producten</span>
            </div>
            <ul class="cart-dropdown__items">
              <li class="cart-dropdown__empty" data-nl="Je winkelwagen is leeg." data-en="Your cart is empty.">Je winkelwagen is leeg.</li>
            </ul>
            <!-- Hidden while the cart is empty, exactly as cart.js hides it
                 (renderCartHeader()) — a subtotal of nothing is noise. -->
            <div class="cart-dropdown__subtotal" hidden>
              <span data-nl="Subtotaal" data-en="Subtotal">Subtotaal</span>
              <strong>&euro;0,00</strong>
            </div>
            <div class="cart-dropdown__actions">
              <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/cart.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--ghost btn--sm btn--block" data-nl="Bekijk winkelwagen" data-en="View cart">Bekijk winkelwagen</a>
              <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/checkout.php'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn--sm btn--block" data-nl="Afrekenen" data-en="Checkout">Afrekenen</a>
            </div>
          </div>
        </div>
