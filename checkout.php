<?php
require_once __DIR__ . '/vendor/autoload.php';
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
// a canonical plus Open Graph tags and no robots tag before this.
$seoMetadata = \App\Service\SeoMetadata::create(
    titleNl: \App\Service\Seo::routeTitle('Afrekenen'),
    titleEn: \App\Service\Seo::routeTitle('Checkout'),
    descriptionNl: 'Rond je bestelling af: gegevens, verzending en betaling via Mollie.',
    descriptionEn: 'Complete your order: details, shipping and payment via Mollie.',
    canonical: \App\Service\AppUrl::canonical('checkout.php'),
    indexable: false,
);

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
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
      <p class="eyebrow" data-nl="Stap 2 van 2" data-en="Step 2 of 2">Stap 2 van 2</p>
      <h1 style="max-width:20ch;" data-nl="Bestelling afronden" data-en="Complete your order">Bestelling afronden</h1>
    </div>
  </section>

  <section>
    <div class="container">
      <p class="lead" data-checkout-empty hidden>
        <span data-nl="Je winkelwagen is leeg — er is niets om af te rekenen." data-en="Your cart is empty — there's nothing to check out.">Je winkelwagen is leeg — er is niets om af te rekenen.</span>
        <a href="shop.php" data-nl="Naar de shop" data-en="Go to shop">Naar de shop</a>
      </p>

      <form class="checkout-layout" data-checkout-form>

        <div data-reveal>

          <div class="checkout-section">
            <h2><span class="step-num">1</span><span data-nl="Contactgegevens" data-en="Contact details">Contactgegevens</span></h2>
            <div class="form-grid">
              <div class="form-field">
                <label for="voornaam" data-nl="Voornaam" data-en="First name">Voornaam <span class="req">*</span></label>
                <input type="text" id="voornaam" name="voornaam" required>
              </div>
              <div class="form-field">
                <label for="achternaam" data-nl="Achternaam" data-en="Last name">Achternaam <span class="req">*</span></label>
                <input type="text" id="achternaam" name="achternaam" required>
              </div>
              <div class="form-field form-field--full">
                <label for="email" data-nl="E-mailadres" data-en="Email address">E-mailadres <span class="req">*</span></label>
                <input type="email" id="email" name="email" required>
                <span class="hint" data-nl="Voor je orderbevestiging" data-en="For your order confirmation">Voor je orderbevestiging</span>
              </div>
              <div class="form-field form-field--full">
                <label for="telefoon" data-nl="Telefoonnummer" data-en="Phone number">Telefoonnummer</label>
                <input type="tel" id="telefoon" name="telefoon">
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">2</span><span data-nl="Verzending" data-en="Shipping">Verzending</span></h2>
            <div class="radio-card-group" role="radiogroup" aria-label="Verzendmethode">
              <label class="radio-card">
                <input type="radio" name="verzendmethode" value="afhalen" checked>
                <span class="radio-card__label">
                  <?php
                  // The town comes from the company address in Instellingen
                  // (App\Service\Shipping\PickupLocation); an installation
                  // that has not filled one in simply offers "Afhalen".
                  $pickupNl = htmlspecialchars(\App\Service\Shipping\PickupLocation::labelNl(), ENT_QUOTES, 'UTF-8');
                  $pickupEn = htmlspecialchars(\App\Service\Shipping\PickupLocation::labelEn(), ENT_QUOTES, 'UTF-8');
                  ?>
                  <strong data-nl="<?= $pickupNl ?>" data-en="<?= $pickupEn ?>"><?= $pickupNl ?></strong>
                  <span data-nl="Gratis &middot; na bericht dat je bestelling klaar is" data-en="Free &middot; once you're notified your order is ready">Gratis &middot; na bericht dat je bestelling klaar is</span>
                </span>
                <span class="radio-card__price" data-nl="Gratis" data-en="Free">Gratis</span>
              </label>
              <label class="radio-card">
                <input type="radio" name="verzendmethode" value="verzenden">
                <span class="radio-card__label">
                  <strong data-nl="Verzenden" data-en="Shipping">Verzenden</strong>
                  <span data-nl="Prijs hangt af van bestemming, gewicht en inhoud van je bestelling" data-en="Price depends on destination, weight and the contents of your order">Prijs hangt af van bestemming, gewicht en inhoud van je bestelling</span>
                </span>
                <span class="radio-card__price" data-checkout-shipping-option-price>&hellip;</span>
              </label>
            </div>

            <div class="form-grid" style="margin-top:var(--sp-3);" data-address-fields>
              <div class="form-field form-field--full" data-checkout-land-field hidden>
                <label for="land" data-nl="Land" data-en="Country">Land <span class="req">*</span></label>
                <select id="land" name="land" data-address-country>
                  <option value="NL" data-nl="Nederland" data-en="Netherlands">Nederland</option>
                  <option value="BE" data-nl="België" data-en="Belgium">België</option>
                </select>
              </div>
              <div class="form-field form-field--full">
                <label for="bedrijf" data-nl="Bedrijfsnaam (optioneel)" data-en="Company name (optional)">Bedrijfsnaam (optioneel)</label>
                <input type="text" id="bedrijf" name="bedrijf" autocomplete="organization">
              </div>
              <div class="form-field">
                <label for="postcode" data-nl="Postcode" data-en="Postal code">Postcode <span class="req">*</span></label>
                <input type="text" id="postcode" name="postcode" inputmode="text" autocapitalize="characters" autocomplete="postal-code" data-address-postcode required>
              </div>
              <div class="form-field">
                <label for="huisnummer" data-nl="Huisnummer en toevoeging" data-en="House number and addition">Huisnummer <span class="req">*</span></label>
                <div class="form-field-inline-group">
                  <input type="text" id="huisnummer" name="huisnummer" inputmode="numeric" autocomplete="off" data-address-house-number required>
                  <input type="text" id="huisnummer_toevoeging" name="huisnummer_toevoeging" autocomplete="off" data-address-house-number-addition placeholder="A" aria-label="Toevoeging">
                </div>
              </div>
              <p class="hint" data-address-status role="status" aria-live="polite" hidden></p>
              <p class="form-error" data-address-error role="alert"></p>
              <div class="form-field form-field--full">
                <label for="straat" data-nl="Straat" data-en="Street">Straat <span class="req">*</span></label>
                <input type="text" id="straat" name="straat" autocomplete="address-line1" data-address-street required>
              </div>
              <div class="form-field form-field--full">
                <label for="plaats" data-nl="Plaats" data-en="City">Plaats <span class="req">*</span></label>
                <input type="text" id="plaats" name="plaats" autocomplete="address-level2" data-address-city required>
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">3</span><span data-nl="Facturatiegegevens" data-en="Billing details">Facturatiegegevens</span></h2>
            <label class="checkbox-field">
              <input type="checkbox" id="facturatie_zelfde" name="facturatie_zelfde" checked>
              <span data-nl="Factuuradres is hetzelfde als verzendadres" data-en="Billing address is the same as shipping address">Factuuradres is hetzelfde als verzendadres</span>
            </label>

            <div class="form-grid" style="margin-top:var(--sp-3);" data-billing-fields data-address-fields hidden>
              <div class="form-field form-field--full">
                <label for="facturatie_land" data-nl="Land" data-en="Country">Land <span class="req">*</span></label>
                <select id="facturatie_land" name="facturatie_land" data-address-country>
                  <option value="NL" data-nl="Nederland" data-en="Netherlands">Nederland</option>
                  <option value="BE" data-nl="België" data-en="Belgium">België</option>
                </select>
              </div>
              <div class="form-field">
                <label for="facturatie_voornaam" data-nl="Voornaam" data-en="First name">Voornaam <span class="req">*</span></label>
                <input type="text" id="facturatie_voornaam" name="facturatie_voornaam" autocomplete="off">
              </div>
              <div class="form-field">
                <label for="facturatie_achternaam" data-nl="Achternaam" data-en="Last name">Achternaam <span class="req">*</span></label>
                <input type="text" id="facturatie_achternaam" name="facturatie_achternaam" autocomplete="off">
              </div>
              <div class="form-field form-field--full">
                <label for="facturatie_bedrijf" data-nl="Bedrijfsnaam (optioneel)" data-en="Company name (optional)">Bedrijfsnaam (optioneel)</label>
                <input type="text" id="facturatie_bedrijf" name="facturatie_bedrijf" autocomplete="off">
              </div>
              <div class="form-field">
                <label for="facturatie_postcode" data-nl="Postcode" data-en="Postal code">Postcode <span class="req">*</span></label>
                <input type="text" id="facturatie_postcode" name="facturatie_postcode" inputmode="text" autocapitalize="characters" autocomplete="off" data-address-postcode>
              </div>
              <div class="form-field">
                <label for="facturatie_huisnummer" data-nl="Huisnummer en toevoeging" data-en="House number and addition">Huisnummer <span class="req">*</span></label>
                <div class="form-field-inline-group">
                  <input type="text" id="facturatie_huisnummer" name="facturatie_huisnummer" inputmode="numeric" autocomplete="off" data-address-house-number>
                  <input type="text" id="facturatie_huisnummer_toevoeging" name="facturatie_huisnummer_toevoeging" autocomplete="off" data-address-house-number-addition placeholder="A" aria-label="Toevoeging">
                </div>
              </div>
              <p class="hint" data-address-status role="status" aria-live="polite" hidden></p>
              <p class="form-error" data-address-error role="alert"></p>
              <div class="form-field form-field--full">
                <label for="facturatie_straat" data-nl="Straat" data-en="Street">Straat <span class="req">*</span></label>
                <input type="text" id="facturatie_straat" name="facturatie_straat" autocomplete="off" data-address-street>
              </div>
              <div class="form-field form-field--full">
                <label for="facturatie_plaats" data-nl="Plaats" data-en="City">Plaats <span class="req">*</span></label>
                <input type="text" id="facturatie_plaats" name="facturatie_plaats" autocomplete="off" data-address-city>
              </div>
            </div>
          </div>

          <div class="checkout-section">
            <h2><span class="step-num">4</span><span data-nl="Betaalmethode" data-en="Payment method">Betaalmethode</span></h2>
            <div class="radio-card-group" role="radiogroup" aria-label="Betaalmethode">
              <label class="radio-card">
                <input type="radio" name="betaalmethode" value="ideal" checked>
                <span class="radio-card__label">
                  <strong>iDEAL</strong>
                  <span data-nl="Direct betalen via je eigen bank" data-en="Pay directly through your own bank">Direct betalen via je eigen bank</span>
                </span>
              </label>
              <label class="radio-card">
                <input type="radio" name="betaalmethode" value="kaart">
                <span class="radio-card__label">
                  <strong data-nl="Creditcard" data-en="Credit card">Creditcard</strong>
                  <span>Visa, Mastercard</span>
                </span>
              </label>
            </div>
            <p class="hint" style="margin-top:var(--sp-2);" data-nl="Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving." data-en="Secure checkout via Mollie. After placing your order you'll be redirected to your payment environment.">Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving.</p>
            <p class="hint" style="margin-top:var(--sp-2);" data-nl="Lees onze <a href='<?= $termsUrl ?>'>algemene voorwaarden</a>, <a href='/verzenden-retourneren'>verzend- &amp; retourinformatie</a> en <a href='/privacyverklaring'>privacyverklaring</a>." data-en="Read our <a href='<?= $termsUrl ?>'>terms &amp; conditions</a>, <a href='/verzenden-retourneren'>shipping &amp; returns info</a> and <a href='/privacyverklaring'>privacy policy</a>.">Lees onze <a href="<?= $termsUrl ?>">algemene voorwaarden</a>, <a href="/verzenden-retourneren">verzend- &amp; retourinformatie</a> en <a href="/privacyverklaring">privacyverklaring</a>.</p>
          </div>

        </div>

        <aside class="order-summary" data-reveal>
          <h3 data-nl="Jouw bestelling" data-en="Your order">Jouw bestelling</h3>
          <div class="checkout-summary-items" data-checkout-items></div>
          <div class="order-summary__row">
            <span data-nl="Subtotaal" data-en="Subtotal">Subtotaal</span>
            <strong data-checkout-subtotal>&euro;0,00</strong>
          </div>
          <div class="order-summary__row">
            <span><span data-nl="Verzending" data-en="Shipping">Verzending</span><span data-checkout-shipping-method></span></span>
            <strong data-checkout-shipping>&euro;0,00</strong>
          </div>
          <div class="order-summary__row order-summary__row--total">
            <span data-nl="Totaal" data-en="Total">Totaal</span>
            <strong data-checkout-total>&euro;0,00</strong>
          </div>
          <div class="checkout-consent">
            <label class="checkbox-field">
              <input type="checkbox" id="terms_accepted" name="terms_accepted" aria-required="true" aria-describedby="checkout-terms-error">
              <span data-nl="Ik ga akkoord met de <a href='<?= $termsUrl ?>' target='_blank' rel='noopener'>algemene voorwaarden</a>." data-en="I agree to the <a href='<?= $termsUrl ?>' target='_blank' rel='noopener'>terms &amp; conditions</a>.">Ik ga akkoord met de <a href="<?= $termsUrl ?>" target="_blank" rel="noopener">algemene voorwaarden</a>.</span>
            </label>
            <span class="form-error" id="checkout-terms-error" role="alert" data-checkout-terms-error></span>
          </div>

          <div class="checkout-consent">
            <div class="cf-turnstile" data-checkout-turnstile data-sitekey="<?= $turnstileSiteKey ?>" aria-describedby="checkout-turnstile-error"></div>
            <span class="form-error" id="checkout-turnstile-error" role="alert" data-checkout-turnstile-error></span>
          </div>

          <p class="form-status form-status--error" data-checkout-error></p>
          <button type="submit" class="btn btn--block" data-checkout-submit data-nl="Bestelling plaatsen" data-en="Place order">
            <span data-checkout-submit-label data-nl="Bestelling plaatsen" data-en="Place order">Bestelling plaatsen</span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </button>
          <p class="order-summary__note" data-nl="Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving." data-en="Secure checkout via Mollie. After placing your order you'll be redirected to your payment environment.">Veilig afrekenen via Mollie. Je wordt na het plaatsen van de bestelling doorgestuurd naar je betaalomgeving.</p>
        </aside>

      </form>

      <div class="checkout-overlay" data-checkout-overlay hidden role="alertdialog" aria-modal="true" aria-labelledby="checkout-overlay-title" aria-describedby="checkout-overlay-text">
        <div class="checkout-overlay__box">
          <span class="checkout-overlay__spinner" aria-hidden="true"></span>
          <p class="checkout-overlay__title" id="checkout-overlay-title" data-nl="Even geduld..." data-en="One moment...">Even geduld...</p>
          <p class="checkout-overlay__text" id="checkout-overlay-text" data-nl="Je wordt doorgestuurd naar de beveiligde betaalomgeving." data-en="You're being redirected to the secure payment environment.">Je wordt doorgestuurd naar de beveiligde betaalomgeving.</p>
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
