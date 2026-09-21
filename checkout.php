<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';

$termsUrl = htmlspecialchars(\App\Service\LegalPages::termsAndConditionsUrl(), ENT_QUOTES, 'UTF-8');
$turnstileSiteKey = htmlspecialchars(\App\Service\TurnstileVerifier::siteKey(), ENT_QUOTES, 'UTF-8');
// This route has no CMS page behind it — see cart.php for the reasoning
// behind building the metadata here and rendering it with the one shared
// partial. Not indexable: a checkout form is transactional, and it carried
// a canonical plus Open Graph tags and no robots tag before this. The
// canonical is this route in the request's own language, as on cart.php.
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Afrekenen', 'en' => 'Checkout'])),
    description: \App\Service\Language\SiteText::pick([
        'nl' => 'Rond je bestelling af: gegevens, verzending en betaling via Mollie.',
        'en' => 'Complete your order: details, shipping and payment via Mollie.',
    ]),
    canonical: \App\Service\Routing\LocalizedUrl::absolute('/checkout.php'),
    indexable: false,
);

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
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


<main id="main">

  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toRoute('cart')
          ->toRoute('checkout')
  ); ?>

  <section class="page-hero" style="padding-bottom:0;">
    <div class="container">
      <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Stap 2 van 2', 'en' => 'Step 2 of 2']) ?></p>
      <h1 style="max-width:20ch;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bestelling afronden', 'en' => 'Complete your order']) ?></h1>
    </div>
  </section>

  <section>
    <div class="container">
      <p class="lead" data-checkout-empty hidden>
        <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je winkelwagen is leeg — er is niets om af te rekenen.', 'en' => 'Your cart is empty — there\'s nothing to check out.']) ?></span>
        <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/shop.php'), ENT_QUOTES, 'UTF-8') ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar de shop', 'en' => 'Go to shop']) ?></a>
      </p>

      <form class="checkout-layout" data-checkout-form>

        <div data-reveal>

          <div class="checkout-section">
            <h2><span class="step-num">1</span><span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Contactgegevens', 'en' => 'Contact details']) ?></span></h2>
            <div class="form-grid">
              <div class="form-field">
                <label for="voornaam"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Voornaam', 'en' => 'First name']) ?> <span class="req">*</span></label>
                <input type="text" id="voornaam" name="voornaam" required>
              </div>
              <div class="form-field">
                <label for="achternaam"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Achternaam', 'en' => 'Last name']) ?> <span class="req">*</span></label>
                <input type="text" id="achternaam" name="achternaam" required>
              </div>
              <div class="form-field form-field--full">
                <label for="email"><?= \App\Service\Language\SiteText::escaped(['nl' => 'E-mailadres', 'en' => 'Email address']) ?> <span class="req">*</span></label>
                <input type="email" id="email" name="email" required>
                <span class="hint"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Voor je orderbevestiging', 'en' => 'For your order confirmation']) ?></span>
              </div>
              <div class="form-field form-field--full">
                <label for="telefoon"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Telefoonnummer', 'en' => 'Phone number']) ?></label>
                <input type="tel" id="telefoon" name="telefoon">
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">2</span><span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzending', 'en' => 'Shipping']) ?></span></h2>
            <div class="radio-card-group" role="radiogroup" aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzendmethode', 'en' => 'Shipping method']) ?>">
              <label class="radio-card">
                <input type="radio" name="verzendmethode" value="afhalen" checked>
                <span class="radio-card__label">
                  <?php
                  // The town comes from the company address in Instellingen
                  // (App\Service\Shipping\PickupLocation); an installation
                  // that has not filled one in simply offers "Afhalen".
                  ?>
                  <strong><?= htmlspecialchars(\App\Service\Shipping\PickupLocation::label(), ENT_QUOTES, 'UTF-8') ?></strong>
                  <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Gratis · na bericht dat je bestelling klaar is', 'en' => 'Free · once you\'re notified your order is ready']) ?></span>
                </span>
                <span class="radio-card__price"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Gratis', 'en' => 'Free']) ?></span>
              </label>
              <label class="radio-card">
                <input type="radio" name="verzendmethode" value="verzenden">
                <span class="radio-card__label">
                  <strong><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzenden', 'en' => 'Shipping']) ?></strong>
                  <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Prijs hangt af van bestemming, gewicht en inhoud van je bestelling', 'en' => 'Price depends on destination, weight and the contents of your order']) ?></span>
                </span>
                <span class="radio-card__price" data-checkout-shipping-option-price>&hellip;</span>
              </label>
            </div>

            <div class="form-grid" style="margin-top:var(--sp-3);" data-address-fields>
              <div class="form-field form-field--full" data-checkout-land-field hidden>
                <label for="land"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Land', 'en' => 'Country']) ?> <span class="req">*</span></label>
                <select id="land" name="land" data-address-country>
                  <option value="NL"><?= htmlspecialchars(\App\Service\Shipping\ShippingCountries::name('NL'), ENT_QUOTES, 'UTF-8') ?></option>
                  <option value="BE"><?= htmlspecialchars(\App\Service\Shipping\ShippingCountries::name('BE'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
              </div>
              <div class="form-field form-field--full">
                <label for="bedrijf"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bedrijfsnaam (optioneel)', 'en' => 'Company name (optional)']) ?></label>
                <input type="text" id="bedrijf" name="bedrijf" autocomplete="organization">
              </div>
              <div class="form-field">
                <label for="postcode"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Postcode', 'en' => 'Postal code']) ?> <span class="req">*</span></label>
                <input type="text" id="postcode" name="postcode" inputmode="text" autocapitalize="characters" autocomplete="postal-code" data-address-postcode required>
              </div>
              <div class="form-field">
                <label for="huisnummer"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Huisnummer', 'en' => 'House number']) ?> <span class="req">*</span></label>
                <div class="form-field-inline-group">
                  <input type="text" id="huisnummer" name="huisnummer" inputmode="numeric" autocomplete="off" data-address-house-number required>
                  <input type="text" id="huisnummer_toevoeging" name="huisnummer_toevoeging" autocomplete="off" data-address-house-number-addition placeholder="A" aria-label="Toevoeging">
                </div>
              </div>
              <p class="hint" data-address-status role="status" aria-live="polite" hidden></p>
              <p class="form-error" data-address-error role="alert"></p>
              <div class="form-field form-field--full">
                <label for="straat"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Straat', 'en' => 'Street']) ?> <span class="req">*</span></label>
                <input type="text" id="straat" name="straat" autocomplete="address-line1" data-address-street required>
              </div>
              <div class="form-field form-field--full">
                <label for="plaats"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Plaats', 'en' => 'City']) ?> <span class="req">*</span></label>
                <input type="text" id="plaats" name="plaats" autocomplete="address-level2" data-address-city required>
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">3</span><span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Facturatiegegevens', 'en' => 'Billing details']) ?></span></h2>
            <label class="checkbox-field">
              <input type="checkbox" id="facturatie_zelfde" name="facturatie_zelfde" checked>
              <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Factuuradres is hetzelfde als verzendadres', 'en' => 'Billing address is the same as shipping address']) ?></span>
            </label>

            <div class="form-grid" style="margin-top:var(--sp-3);" data-billing-fields data-address-fields hidden>
              <div class="form-field form-field--full">
                <label for="facturatie_land"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Land', 'en' => 'Country']) ?> <span class="req">*</span></label>
                <select id="facturatie_land" name="facturatie_land" data-address-country>
                  <option value="NL"><?= htmlspecialchars(\App\Service\Shipping\ShippingCountries::name('NL'), ENT_QUOTES, 'UTF-8') ?></option>
                  <option value="BE"><?= htmlspecialchars(\App\Service\Shipping\ShippingCountries::name('BE'), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
              </div>
              <div class="form-field">
                <label for="facturatie_voornaam"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Voornaam', 'en' => 'First name']) ?> <span class="req">*</span></label>
                <input type="text" id="facturatie_voornaam" name="facturatie_voornaam" autocomplete="off">
              </div>
              <div class="form-field">
                <label for="facturatie_achternaam"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Achternaam', 'en' => 'Last name']) ?> <span class="req">*</span></label>
                <input type="text" id="facturatie_achternaam" name="facturatie_achternaam" autocomplete="off">
              </div>
              <div class="form-field form-field--full">
                <label for="facturatie_bedrijf"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bedrijfsnaam (optioneel)', 'en' => 'Company name (optional)']) ?></label>
                <input type="text" id="facturatie_bedrijf" name="facturatie_bedrijf" autocomplete="off">
              </div>
              <div class="form-field">
                <label for="facturatie_postcode"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Postcode', 'en' => 'Postal code']) ?> <span class="req">*</span></label>
                <input type="text" id="facturatie_postcode" name="facturatie_postcode" inputmode="text" autocapitalize="characters" autocomplete="off" data-address-postcode>
              </div>
              <div class="form-field">
                <label for="facturatie_huisnummer"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Huisnummer', 'en' => 'House number']) ?> <span class="req">*</span></label>
                <div class="form-field-inline-group">
                  <input type="text" id="facturatie_huisnummer" name="facturatie_huisnummer" inputmode="numeric" autocomplete="off" data-address-house-number>
                  <input type="text" id="facturatie_huisnummer_toevoeging" name="facturatie_huisnummer_toevoeging" autocomplete="off" data-address-house-number-addition placeholder="A" aria-label="Toevoeging">
                </div>
              </div>
              <p class="hint" data-address-status role="status" aria-live="polite" hidden></p>
              <p class="form-error" data-address-error role="alert"></p>
              <div class="form-field form-field--full">
                <label for="facturatie_straat"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Straat', 'en' => 'Street']) ?> <span class="req">*</span></label>
                <input type="text" id="facturatie_straat" name="facturatie_straat" autocomplete="off" data-address-street>
              </div>
              <div class="form-field form-field--full">
                <label for="facturatie_plaats"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Plaats', 'en' => 'City']) ?> <span class="req">*</span></label>
                <input type="text" id="facturatie_plaats" name="facturatie_plaats" autocomplete="off" data-address-city>
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">4</span><span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Betaalmethode', 'en' => 'Payment method']) ?></span></h2>
            <div class="radio-card-group" role="radiogroup" aria-label="Betaalmethode">
              <label class="radio-card">
                <input type="radio" name="betaalmethode" value="ideal" checked>
                <span class="radio-card__label">
                  <strong>iDEAL</strong>
                  <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Direct betalen via je eigen bank', 'en' => 'Pay directly through your own bank']) ?></span>
                </span>
              </label>
              <label class="radio-card">
                <input type="radio" name="betaalmethode" value="kaart">
                <span class="radio-card__label">
                  <strong><?= \App\Service\Language\SiteText::escaped(['nl' => 'Creditcard', 'en' => 'Credit card']) ?></strong>
                  <span>Visa, Mastercard</span>
                </span>
              </label>
            </div>
            <p class="hint" style="margin-top:var(--sp-2);"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving.', 'en' => 'Secure checkout via Mollie. After placing your order you\'ll be redirected to your payment environment.']) ?></p>
            <?php
              // Built from escaped pieces: every link is this site's own
              // address in the language being read. The terms page is always
              // named (App\Service\LegalPages); the shipping & returns page
              // and the privacy statement only when they are published, so the
              // sentence never points at a 404 or at a slug typed in here.
              $readLinks = ['<a href="' . $termsUrl . '">' . \App\Service\Language\SiteText::escaped(['nl' => 'algemene voorwaarden', 'en' => 'terms & conditions']) . '</a>'];
              $shippingReturnsUrl = \App\Service\LegalPages::publishedPageUrl(\App\Service\LegalPages::SHIPPING_RETURNS_KEY);
              if ($shippingReturnsUrl !== null) {
                  $readLinks[] = '<a href="' . htmlspecialchars($shippingReturnsUrl, ENT_QUOTES, 'UTF-8') . '">' . \App\Service\Language\SiteText::escaped(['nl' => 'verzend- & retourinformatie', 'en' => 'shipping & returns info']) . '</a>';
              }
              $privacyUrl = \App\Service\LegalPages::publishedPageUrl(\App\Service\LegalPages::PRIVACY_KEY);
              if ($privacyUrl !== null) {
                  $readLinks[] = '<a href="' . htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8') . '">' . \App\Service\Language\SiteText::escaped(['nl' => 'privacyverklaring', 'en' => 'privacy policy']) . '</a>';
              }
              $lastReadLink = array_pop($readLinks);
              $readList = $readLinks === []
                  ? $lastReadLink
                  : implode(', ', $readLinks) . \App\Service\Language\SiteText::escaped(['nl' => ' en ', 'en' => ' and ']) . $lastReadLink;
            ?>
            <p class="hint" style="margin-top:var(--sp-2);"><?= sprintf(\App\Service\Language\SiteText::escaped(['nl' => 'Lees onze %s.', 'en' => 'Read our %s.']), $readList) ?></p>
          </div>

        </div>

        <aside class="order-summary" data-reveal>
          <h3><?= \App\Service\Language\SiteText::escaped(['nl' => 'Jouw bestelling', 'en' => 'Your order']) ?></h3>
          <div class="checkout-summary-items" data-checkout-items></div>
          <div class="order-summary__row">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Subtotaal', 'en' => 'Subtotal']) ?></span>
            <strong data-checkout-subtotal>&euro;0,00</strong>
          </div>
          <div class="order-summary__row">
            <span><span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzending', 'en' => 'Shipping']) ?></span><span data-checkout-shipping-method></span></span>
            <strong data-checkout-shipping>&euro;0,00</strong>
          </div>
          <div class="order-summary__row order-summary__row--total">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Totaal', 'en' => 'Total']) ?></span>
            <strong data-checkout-total>&euro;0,00</strong>
          </div>
          <div class="checkout-consent">
            <label class="checkbox-field">
              <input type="checkbox" id="terms_accepted" name="terms_accepted" aria-required="true" aria-describedby="checkout-terms-error">
              <span><?= sprintf(
                  \App\Service\Language\SiteText::escaped(['nl' => 'Ik ga akkoord met de %s.', 'en' => 'I agree to the %s.']),
                  '<a href="' . $termsUrl . '" target="_blank" rel="noopener">' . \App\Service\Language\SiteText::escaped(['nl' => 'algemene voorwaarden', 'en' => 'terms & conditions']) . '</a>'
              ) ?></span>
            </label>
            <span class="form-error" id="checkout-terms-error" role="alert" data-checkout-terms-error></span>
          </div>

          <div class="checkout-consent">
            <div class="cf-turnstile" data-checkout-turnstile data-sitekey="<?= $turnstileSiteKey ?>" aria-describedby="checkout-turnstile-error"></div>
            <span class="form-error" id="checkout-turnstile-error" role="alert" data-checkout-turnstile-error></span>
          </div>

          <p class="form-status form-status--error" data-checkout-error></p>
          <button type="submit" class="btn btn--block" data-checkout-submit>
            <span data-checkout-submit-label><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bestelling plaatsen', 'en' => 'Place order']) ?></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </button>
          <p class="order-summary__note"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving.', 'en' => 'Secure checkout via Mollie. After placing your order you\'ll be redirected to your payment environment.']) ?></p>
        </aside>

      </form>

      <div class="checkout-overlay" data-checkout-overlay hidden role="alertdialog" aria-modal="true" aria-labelledby="checkout-overlay-title" aria-describedby="checkout-overlay-text">
        <div class="checkout-overlay__box">
          <span class="checkout-overlay__spinner" aria-hidden="true"></span>
          <p class="checkout-overlay__title" id="checkout-overlay-title"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Even geduld...', 'en' => 'One moment...']) ?></p>
          <p class="checkout-overlay__text" id="checkout-overlay-text"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je wordt doorgestuurd naar de beveiligde betaalomgeving.', 'en' => 'You\'re being redirected to the secure payment environment.']) ?></p>
        </div>
      </div>
    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
<!-- Deliberately still a hand-written tag, and deliberately after the page's
     own scripts: it needs query parameters plus async/defer that
     App\Service\PageAssets does not model, and assets/js/shop/shop.js defines
     the window.vvlOnTurnstileLoad callback its onload names (and handles the
     case where this script's onload fires first) — see initCheckoutPage(). -->
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=vvlOnTurnstileLoad&render=explicit" async defer></script>
</body>
</html>
