<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * Customer-facing right-of-withdrawal ("herroepingsrecht") form — see
 * db/migrations/20260906150000_create_withdrawal_requests_table.php and
 * api/withdrawal-request.php. A customer identifies their order (order
 * number + the e-mail address used at checkout) and submits a request; the
 * shop owner reviews and handles it manually in
 * admin/withdrawal-requests.php (see that migration's docblock for why this
 * is deliberately not automated).
 */

$contactEmail = \App\Service\SiteSettings::get('email');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$prefillOrder = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
$prefillOrderValue = ($prefillOrder !== null && $prefillOrder !== false && $prefillOrder >= 1) ? (string) $prefillOrder : '';

// WHERE THIS FORM, FOR THIS ORDER, LIVES IN EACH LANGUAGE, for the language
// switch (docs/multilingual/ROUTING.md, §9). The order a customer came to
// withdraw is named by the id in the query string, and the switch's assumed
// paths carry the path only, so undeclared it offered /en/herroeping.php: the
// same form with the order field empty. Each version is this route with that
// one id under the language's prefix (the default language unprefixed), so
// nothing else from the request travels: not the status and reason of a
// refused request, no tracking, no `lang`.
//
// Built from $prefillOrderValue and from nothing else: the value the order
// field below is filled with. So the switch reads the id exactly as this page
// does, and the other language fills in the same order; whatever fills in
// nothing here ('05', '1e3', an array, a URL, an empty value) declares nothing
// and the switch offers the bare route, as before. The order is not looked up
// here; api/withdrawal-request.php checks it against the e-mail address on
// submit, the same in every language. This page has a canonical, so these
// versions are also its hreflang alternates: exactly what the switch links,
// on a page that stays noindex.
if ($prefillOrderValue !== '') {
    $withdrawalVersions = [];
    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $withdrawalLanguage) {
        $withdrawalVersions[$withdrawalLanguage] = \App\Service\Routing\LocalizedUrl::path('/herroeping.php?order=' . $prefillOrderValue, $withdrawalLanguage);
    }
    \App\Service\Routing\LanguageAlternates::declareVersions($withdrawalVersions);
}

$formStatus = $_GET['status'] ?? null;
$formSuccess = $formStatus === 'success';
$formBanner = null;
if ($formSuccess) {
    $formBanner = 'Bedankt — je herroepingsverzoek is ontvangen. Je krijgt hiervan ook een bevestiging per e-mail; we nemen zo nodig contact met je op.';
} elseif ($formStatus === 'error') {
    $formBanner = match ($_GET['reason'] ?? null) {
        'validation' => 'We konden geen bestelling vinden met dat ordernummer en e-mailadres. Controleer beide en probeer het opnieuw.',
        'duplicate' => 'Er staat al een openstaand herroepingsverzoek voor deze bestelling. We nemen contact met je op.',
        default => 'Er ging iets mis. Mail ons direct via ' . $contactEmail . '.',
    };
}
// This route has no CMS page behind it — same reasoning as cart.php. Not
// indexable, as it already was, and its Open Graph tags are kept: a page
// nobody may index is still a page somebody can link to. The canonical is the
// bare form in the request's own language, never the default language's
// (docs/multilingual/ROUTING.md, §10).
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Herroepingsrecht', 'en' => 'Right of withdrawal'])),
    description: \App\Service\Language\SiteText::pick([
        'nl' => 'Meld een bestelling aan voor herroeping (bedenktijd).',
        'en' => 'Report an order for withdrawal (cooling-off period).',
    ]),
    canonical: \App\Service\Routing\LocalizedUrl::absolute('/herroeping.php'),
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
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>

<main id="main">

  <?php render_breadcrumb(\App\Service\Breadcrumbs\BreadcrumbTrail::home()->toRoute('herroeping')); ?>

  <section class="page-hero">
    <div class="container">
      <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Juridisch', 'en' => 'Legal']) ?></p>
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bestelling herroepen', 'en' => 'Withdraw an order']) ?></h1>
      <?php
        // Built from escaped pieces. The shipping & returns page is named by
        // its content key and linked in the language being read, and only
        // when it is published (App\Service\LegalPages::publishedPageUrl());
        // without it the sentence simply ends after the request.
        $shippingReturnsUrl = \App\Service\LegalPages::publishedPageUrl(\App\Service\LegalPages::SHIPPING_RETURNS_KEY);
        $withdrawalLead = \App\Service\Language\SiteText::escaped([
            'nl' => 'Wil je gebruikmaken van je herroepingsrecht (bedenktijd)? Meld je bestelling hieronder aan, dan nemen we het verzoek in behandeling.',
            'en' => 'Want to use your right of withdrawal (cooling-off period)? Report your order below and we\'ll take the request into review.',
        ]);
        if ($shippingReturnsUrl !== null) {
            $withdrawalLead .= ' ' . sprintf(
                \App\Service\Language\SiteText::escaped([
                    'nl' => 'Lees ook onze pagina %s voor meer uitleg, waaronder de uitzondering voor gepersonaliseerde/op maat gemaakte producten.',
                    'en' => 'See also our %s page for more details, including the exception for personalised/made-to-order products.',
                ]),
                '<a href="' . $h($shippingReturnsUrl) . '">' . \App\Service\Language\SiteText::escaped(['nl' => 'Verzenden & retourneren', 'en' => 'Shipping & returns']) . '</a>'
            );
        }
      ?>
      <p class="lead" style="margin-top:1rem;"><?= $withdrawalLead ?></p>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container container--narrow">

      <?php if (!$formSuccess): ?>
      <form method="post" action="/api/withdrawal-request.php" class="contact-card">
        <div class="form-field visually-hidden" aria-hidden="true">
          <label for="wr-hp-note">Laat dit veld leeg</label>
          <input type="text" id="wr-hp-note" name="hp-note" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="form_ts" value="<?= time() ?>">
        <?php /* The endpoint's path has no language, so the answer finds this
                 page's language back through this field, checked against the
                 registry there (api/withdrawal-request.php, formLanguage()). */ ?>
        <input type="hidden" name="language" value="<?= $h(\App\Service\Routing\RequestLanguage::current()) ?>">

        <div class="form-grid">
          <div class="form-field">
            <label for="wr-order"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Ordernummer', 'en' => 'Order number']) ?> <span class="req">*</span></label>
            <input type="text" inputmode="numeric" id="wr-order" name="order_id" required value="<?= $h($prefillOrderValue) ?>">
          </div>
          <div class="form-field">
            <label for="wr-email"><?= \App\Service\Language\SiteText::escaped(['nl' => 'E-mailadres bij de bestelling', 'en' => 'Email address used for the order']) ?> <span class="req">*</span></label>
            <input type="email" id="wr-email" name="email" required autocomplete="email">
          </div>
          <div class="form-field form-field--full">
            <label for="wr-reason"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Toelichting (optioneel)', 'en' => 'Explanation (optional)']) ?></label>
            <textarea id="wr-reason" name="reason" maxlength="2000"></textarea>
          </div>
        </div>

        <button type="submit" class="btn btn--block"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Verzoek indienen', 'en' => 'Submit request']) ?>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
      </form>
      <?php endif; ?>

      <?php if ($formBanner !== null): ?>
        <p class="form-status is-visible <?= $formSuccess ? 'form-status--ok' : 'form-status--error' ?>" role="status" style="margin-top:var(--sp-3);"><?= $h($formBanner) ?></p>
      <?php endif; ?>

    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
