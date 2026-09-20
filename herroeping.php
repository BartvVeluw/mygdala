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
// nobody may index is still a page somebody can link to.
$seoMetadata = \App\Service\SeoMetadata::create(
    titleNl: \App\Service\Seo::routeTitle('Herroepingsrecht'),
    titleEn: \App\Service\Seo::routeTitle('Right of withdrawal'),
    descriptionNl: 'Meld een bestelling aan voor herroeping (bedenktijd).',
    descriptionEn: 'Report an order for withdrawal (cooling-off period).',
    canonical: \App\Service\AppUrl::canonical('herroeping.php'),
    indexable: false,
);

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
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
      <p class="eyebrow" data-nl="Juridisch" data-en="Legal">Juridisch</p>
      <h1 data-nl="Bestelling herroepen" data-en="Withdraw an order">Bestelling herroepen</h1>
      <p class="lead" style="margin-top:1rem;" data-nl="Wil je gebruikmaken van je herroepingsrecht (bedenktijd)? Meld je bestelling hieronder aan, dan nemen we het verzoek in behandeling. Lees ook onze pagina Verzenden &amp; retourneren voor meer uitleg, waaronder de uitzondering voor gepersonaliseerde/op maat gemaakte producten." data-en="Want to use your right of withdrawal (cooling-off period)? Report your order below and we'll take the request into review. See also our Shipping &amp; returns page for more details, including the exception for personalised/made-to-order products.">Wil je gebruikmaken van je herroepingsrecht (bedenktijd)? Meld je bestelling hieronder aan, dan nemen we het verzoek in behandeling. Lees ook onze pagina <a href="/verzenden-retourneren">Verzenden &amp; retourneren</a> voor meer uitleg, waaronder de uitzondering voor gepersonaliseerde/op maat gemaakte producten.</p>
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

        <div class="form-grid">
          <div class="form-field">
            <label for="wr-order" data-nl="Ordernummer" data-en="Order number">Ordernummer <span class="req">*</span></label>
            <input type="text" inputmode="numeric" id="wr-order" name="order_id" required value="<?= $h($prefillOrderValue) ?>">
          </div>
          <div class="form-field">
            <label for="wr-email" data-nl="E-mailadres bij de bestelling" data-en="Email address used for the order">E-mailadres bij de bestelling <span class="req">*</span></label>
            <input type="email" id="wr-email" name="email" required autocomplete="email">
          </div>
          <div class="form-field form-field--full">
            <label for="wr-reason" data-nl="Toelichting (optioneel)" data-en="Explanation (optional)">Toelichting (optioneel)</label>
            <textarea id="wr-reason" name="reason" maxlength="2000"></textarea>
          </div>
        </div>

        <button type="submit" class="btn btn--block" data-nl="Verzoek indienen" data-en="Submit request">Verzoek indienen
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
