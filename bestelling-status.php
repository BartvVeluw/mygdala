<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';


$statusOrderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
$withdrawalUrl = '/herroeping.php' . (($statusOrderId !== null && $statusOrderId !== false && $statusOrderId >= 1) ? '?order=' . $statusOrderId : '');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
// A per-order page reached from a payment return link. Never indexable,
// and deliberately WITHOUT a canonical URL: every visit is about a
// different order, so there is no one URL this page is the canonical
// version of. It kept its noindex tag through the refactor; what it gains
// is the shared renderer and a title that stops repeating the site name
// when a site is called "Bestelstatus".
$seoMetadata = \App\Service\SeoMetadata::create(
    titleNl: \App\Service\Seo::routeTitle('Bestelstatus'),
    titleEn: \App\Service\Seo::routeTitle('Order status'),
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


<main id="main" data-order-status>

  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toPage('shop', 'shop')
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current('Bestelstatus', 'Order status'))
  ); ?>

  <section class="page-hero" style="padding-bottom:0;">
    <div class="container">
      <h1 style="max-width:20ch;" data-nl="Jouw bestelling" data-en="Your order">Jouw bestelling</h1>
    </div>
  </section>

  <section>
    <div class="container" style="max-width:640px;">

      <p class="lead" data-order-status-loading data-nl="Bestelstatus laden…" data-en="Loading order status…">Bestelstatus laden…</p>

      <p class="lead" data-order-status-error hidden data-nl="Deze bestelling kan niet worden gevonden. Klopt de link? Neem anders contact met ons op." data-en="This order can't be found. Is the link correct? Otherwise, please get in touch.">Deze bestelling kan niet worden gevonden. Klopt de link? Neem anders contact met ons op.</p>

      <div data-order-status-content hidden>

        <div data-order-status-paid hidden>
          <p class="eyebrow" data-nl="Betaald" data-en="Paid">Betaald</p>
          <h2 data-nl="Bedankt voor je bestelling!" data-en="Thank you for your order!">Bedankt voor je bestelling!</h2>
          <p class="lead" data-nl="Je betaling is gelukt. We gaan zo snel mogelijk voor je aan de slag." data-en="Your payment was successful. We'll get started on your order as soon as possible.">Je betaling is gelukt. We gaan zo snel mogelijk voor je aan de slag.</p>
          <p data-order-status-confirmation hidden data-nl="Je ontvangt een bevestigingsmail met de details van je bestelling." data-en="You'll receive a confirmation email with your order details.">Je ontvangt een bevestigingsmail met de details van je bestelling.</p>
        </div>

        <div data-order-status-pending hidden>
          <p class="eyebrow" data-nl="In verwerking" data-en="Processing">In verwerking</p>
          <h2 data-nl="We wachten nog op je betaling" data-en="Still waiting for your payment">We wachten nog op je betaling</h2>
          <p class="lead" data-nl="Dit kan even duren, bijvoorbeeld bij iDEAL-betalingen. Ververs deze pagina zo nodig." data-en="This can take a moment, for example with iDEAL payments. Refresh this page if needed.">Dit kan even duren, bijvoorbeeld bij iDEAL-betalingen. Ververs deze pagina zo nodig.</p>
        </div>

        <div data-order-status-failed hidden>
          <p class="eyebrow" data-nl="Niet gelukt" data-en="Not successful">Niet gelukt</p>
          <h2 data-nl="De betaling is niet gelukt" data-en="The payment wasn't successful">De betaling is niet gelukt</h2>
          <p class="lead" data-nl="Er is niets afgeschreven. Je winkelwagen staat nog klaar, dus je kunt het opnieuw proberen." data-en="Nothing was charged. Your cart is still waiting, so you can try again.">Er is niets afgeschreven. Je winkelwagen staat nog klaar, dus je kunt het opnieuw proberen.</p>
          <a href="checkout.php" class="btn" data-nl="Opnieuw proberen" data-en="Try again">Opnieuw proberen</a>
        </div>

        <div class="order-summary" style="margin-top:var(--sp-4);">
          <h3>
            <span data-nl="Bestelling" data-en="Order">Bestelling</span>
            <span data-order-status-id></span>
          </h3>
          <div class="checkout-summary-items" data-order-status-items></div>
          <div class="order-summary__row">
            <span data-nl="Verzendkosten" data-en="Shipping">Verzendkosten</span>
            <span data-order-status-shipping>&euro;0,00</span>
          </div>
          <div class="order-summary__row order-summary__row--total">
            <span data-nl="Totaal" data-en="Total">Totaal</span>
            <strong data-order-status-total>&euro;0,00</strong>
          </div>
        </div>

        <div style="margin-top:var(--sp-4);">
          <a href="shop.php" class="btn btn--ghost" data-nl="Terug naar de shop" data-en="Back to shop">Terug naar de shop</a>
        </div>

        <?php /* data-lang-html: developer-authored HTML with hardcoded <a> tags
                 and only $withdrawalUrl (htmlspecialchars'd) interpolated, so
                 applyLang() re-renders it with innerHTML on a language switch —
                 a plain-text field now gets textContent, the XSS-safe default. */ ?>
        <p class="hint" style="margin-top:var(--sp-3);" data-lang-html data-nl="Bestelling herroepen? Bekijk <a href='/verzenden-retourneren'>verzenden &amp; retourneren</a> of <a href='<?= $h($withdrawalUrl) ?>'>meld je bestelling aan voor herroeping</a>." data-en="Want to withdraw this order? See <a href='/verzenden-retourneren'>shipping &amp; returns</a> or <a href='<?= $h($withdrawalUrl) ?>'>report your order for withdrawal</a>.">Bestelling herroepen? Bekijk <a href="/verzenden-retourneren">verzenden &amp; retourneren</a> of <a href="<?= $h($withdrawalUrl) ?>">meld je bestelling aan voor herroeping</a>.</p>

      </div>

    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
