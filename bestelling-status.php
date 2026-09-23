<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');
require_once __DIR__ . '/partials/breadcrumb.php';


$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// WHERE THIS ORDER'S STATUS LIVES IN EACH LANGUAGE, for the language switch
// (docs/multilingual/ROUTING.md, §9). The order is identified by the id in
// the query string, and the switch's assumed paths carry the path only, so
// undeclared it offered /en/bestelling-status.php: the bare route, which
// answers "this order can't be found". Each version is this route with that
// one id under the language's prefix (the default language unprefixed), so
// nothing else from the request travels: no tracking, no status, no `lang`.
//
// The id is read EXACTLY as assets/js/shop/shop.js reads it, because that
// script is what shows the order, and the switch may be neither stricter nor
// looser than the page: the FIRST `order` in the query string, as
// URLSearchParams.get() finds it (PHP's $_GET keeps the last), with the
// whitespace JavaScript's trim() removes taken off (a space, a tab, a line
// break, a no-break space, a byte-order mark), and then only the digits of a
// positive integer. So ' 5' shows order 5 here and travels as 5, while '+5'
// (an encoded plus), '05', '5abc', '1e3', an array or a URL show no order and
// declare nothing: the switch offers the bare route, as before, and a value
// shop.js refuses never becomes a valid order on the other side. Digits too
// large for any order (api/order-status.php refuses what PHP cannot hold as
// an integer) declare nothing either. Whether the order exists is not looked
// up here; api/order-status.php answers that, the same in every language.
// This page has no canonical, so this feeds the switch only and never
// hreflang. The withdrawal link below reads the order from here too.
$orderStatusShown = null;
$orderStatusOrder = null;
foreach (explode('&', (string) ($_SERVER['QUERY_STRING'] ?? '')) as $orderStatusPair) {
    $orderStatusPair = explode('=', $orderStatusPair, 2);
    if (urldecode($orderStatusPair[0]) === 'order') {
        $orderStatusShown = urldecode($orderStatusPair[1] ?? '');
        break;
    }
}
$orderStatusTrimmed = '\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';
if (
    $orderStatusShown !== null
    && preg_match('/\A[' . $orderStatusTrimmed . ']*([1-9][0-9]*)[' . $orderStatusTrimmed . ']*\z/u', $orderStatusShown, $orderStatusDigits) === 1
    && is_int(filter_var($orderStatusDigits[1], FILTER_VALIDATE_INT))
) {
    $orderStatusOrder = $orderStatusDigits[1];
    $orderStatusVersions = [];
    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $orderStatusLanguage) {
        $orderStatusVersions[$orderStatusLanguage] = \App\Service\Routing\LocalizedUrl::path('/bestelling-status.php?order=' . $orderStatusOrder, $orderStatusLanguage);
    }
    \App\Service\Routing\LanguageAlternates::declareVersions($orderStatusVersions);
}

// THE WITHDRAWAL LINK names the order this page shows, read the one way above,
// and nothing else from the query string, on the withdrawal form in this
// page's own language (docs/multilingual/ROUTING.md, §9). It used to read
// $_GET's LAST `order` with PHP's looser integer rule and to drop the prefix,
// so /en/…?order=7&order=8 showed order 7 and offered to withdraw order 8, in
// Dutch. No order shown, no order carried: the bare form, still in this
// language.
$withdrawalUrl = \App\Service\Routing\LocalizedUrl::path(
    '/herroeping.php' . ($orderStatusOrder !== null ? '?order=' . $orderStatusOrder : '')
);
// A per-order page reached from a payment return link. Never indexable,
// and deliberately WITHOUT a canonical URL: every visit is about a
// different order, so there is no one URL this page is the canonical
// version of. It kept its noindex tag through the refactor; what it gains
// is the shared renderer and a title that stops repeating the site name
// when a site is called "Bestelstatus".
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Bestelstatus', 'en' => 'Order status'])),
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


<main id="main" data-order-status>

  <?php render_breadcrumb(
      \App\Service\ShopOverview::extendTrail(\App\Service\Breadcrumbs\BreadcrumbTrail::home())
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Bestelstatus', 'en' => 'Order status'])))
  ); ?>

  <section class="page-hero" style="padding-bottom:0;">
    <div class="container">
      <h1 style="max-width:20ch;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Jouw bestelling', 'en' => 'Your order']) ?></h1>
    </div>
  </section>

  <section>
    <div class="container" style="max-width:640px;">

      <p class="lead" data-order-status-loading><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bestelstatus laden…', 'en' => 'Loading order status…']) ?></p>

      <p class="lead" data-order-status-error hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Deze bestelling kan niet worden gevonden. Klopt de link? Neem anders contact met ons op.', 'en' => 'This order can\'t be found. Is the link correct? Otherwise, please get in touch.']) ?></p>

      <div data-order-status-content hidden>

        <div data-order-status-paid hidden>
          <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Betaald', 'en' => 'Paid']) ?></p>
          <h2><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bedankt voor je bestelling!', 'en' => 'Thank you for your order!']) ?></h2>
          <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je betaling is gelukt. We gaan zo snel mogelijk voor je aan de slag.', 'en' => 'Your payment was successful. We\'ll get started on your order as soon as possible.']) ?></p>
          <p data-order-status-confirmation hidden><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je ontvangt een bevestigingsmail met de details van je bestelling.', 'en' => 'You\'ll receive a confirmation email with your order details.']) ?></p>
        </div>

        <div data-order-status-pending hidden>
          <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'In verwerking', 'en' => 'Processing']) ?></p>
          <h2><?= \App\Service\Language\SiteText::escaped(['nl' => 'We wachten nog op je betaling', 'en' => 'Still waiting for your payment']) ?></h2>
          <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit kan even duren, bijvoorbeeld bij iDEAL-betalingen. Ververs deze pagina zo nodig.', 'en' => 'This can take a moment, for example with iDEAL payments. Refresh this page if needed.']) ?></p>
        </div>

        <div data-order-status-failed hidden>
          <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Niet gelukt', 'en' => 'Not successful']) ?></p>
          <h2><?= \App\Service\Language\SiteText::escaped(['nl' => 'De betaling is niet gelukt', 'en' => 'The payment wasn\'t successful']) ?></h2>
          <p class="lead"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Er is niets afgeschreven. Je winkelwagen staat nog klaar, dus je kunt het opnieuw proberen.', 'en' => 'Nothing was charged. Your cart is still waiting, so you can try again.']) ?></p>
          <a href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/checkout.php')) ?>" class="btn"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Opnieuw proberen', 'en' => 'Try again']) ?></a>
        </div>

        <div class="order-summary" style="margin-top:var(--sp-4);">
          <h3>
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bestelling', 'en' => 'Order']) ?></span>
            <span data-order-status-id></span>
          </h3>
          <div class="checkout-summary-items" data-order-status-items></div>
          <div class="order-summary__row">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzendkosten', 'en' => 'Shipping']) ?></span>
            <span data-order-status-shipping>&euro;0,00</span>
          </div>
          <div class="order-summary__row order-summary__row--total">
            <span><?= \App\Service\Language\SiteText::escaped(['nl' => 'Totaal', 'en' => 'Total']) ?></span>
            <strong data-order-status-total>&euro;0,00</strong>
          </div>
        </div>

        <div style="margin-top:var(--sp-4);">
          <?php if (\App\Service\ShopOverview::url() !== null): ?>
          <a href="<?= $h(\App\Service\ShopOverview::url()) ?>" class="btn btn--ghost"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Terug naar de shop', 'en' => 'Back to shop']) ?></a>
          <?php endif; ?>
        </div>

        <?php
          // Built from escaped pieces: the two links are this site's own
          // addresses in the language being read — the shipping & returns
          // page by its content key, and only when it is published
          // (App\Service\LegalPages::publishedPageUrl()), the withdrawal form
          // by its route. No slug is typed into this template.
          $shippingReturnsUrl = \App\Service\LegalPages::publishedPageUrl(\App\Service\LegalPages::SHIPPING_RETURNS_KEY);
          $withdrawalLink = '<a href="' . $h($withdrawalUrl) . '">'
              . \App\Service\Language\SiteText::escaped(['nl' => 'meld je bestelling aan voor herroeping', 'en' => 'report your order for withdrawal']) . '</a>';
          $withdrawalHint = $shippingReturnsUrl === null
              ? sprintf(\App\Service\Language\SiteText::escaped(['nl' => 'Bestelling herroepen? Dan kun je %s.', 'en' => 'Want to withdraw this order? Then you can %s.']), $withdrawalLink)
              : sprintf(
                  \App\Service\Language\SiteText::escaped(['nl' => 'Bestelling herroepen? Bekijk %1$s of %2$s.', 'en' => 'Want to withdraw this order? See %1$s or %2$s.']),
                  '<a href="' . $h($shippingReturnsUrl) . '">' . \App\Service\Language\SiteText::escaped(['nl' => 'verzenden & retourneren', 'en' => 'shipping & returns']) . '</a>',
                  $withdrawalLink
              );
        ?>
        <p class="hint" style="margin-top:var(--sp-3);"><?= $withdrawalHint ?></p>

      </div>

    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
