<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
require_once __DIR__ . '/partials/breadcrumb.php';

use App\Service\CookieConsentConfig;

$categories = CookieConsentConfig::categories();
$policyEntries = CookieConsentConfig::policyEntries();
$hasOptionalEntriesInUse = CookieConsentConfig::hasOptionalEntriesInUse();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
// This route has no CMS page behind it, so its metadata is built here and
// rendered by the one shared partial like every other public page.
//
// Not indexable, as before — but the canonical URL is now derived from the
// configured application URL instead of being the one literal production
// domain written into a template, which is what made this file the last
// place a second site could not be deployed from. It is this route in the
// request's own language, as on cart.php.
$siteName = \App\Service\SiteSettings::get('site_name');
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle(\App\Service\Language\SiteText::pick(['nl' => 'Cookiebeleid', 'en' => 'Cookie policy'])),
    description: sprintf(\App\Service\Language\SiteText::pick([
        'nl' => 'Welke cookies en lokale opslag %s gebruikt, waarvoor, en hoe je je voorkeuren kunt beheren.',
        'en' => 'Which cookies and local storage %s uses, what for, and how to manage your preferences.',
    ]), $siteName),
    canonical: \App\Service\Routing\LocalizedUrl::absolute('/cookiebeleid.php'),
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
<?php
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <?php /* A Core route with no `pages` row: App\Service\RouteRegistry is
           where it is named and where its address lives. */ ?>
  <?php render_breadcrumb(\App\Service\Breadcrumbs\BreadcrumbTrail::home()->toRoute('cookiebeleid')); ?>

  <section class="page-hero">
    <div class="container">
      <p class="eyebrow"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Juridisch', 'en' => 'Legal']) ?></p>
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Cookiebeleid', 'en' => 'Cookie policy']) ?></h1>
      <p class="lead" style="margin-top:1rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Een overzicht van welke cookies en lokale opslag deze site gebruikt, waarvoor, en hoe lang.', 'en' => 'An overview of which cookies and local storage this site uses, what for, and for how long.']) ?></p>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container" style="max-width: var(--container-narrow);">

      <h2><?= \App\Service\Language\SiteText::escaped(['nl' => 'Wat we gebruiken', 'en' => 'What we use']) ?></h2>
      <p>
        <?= \App\Service\Language\SiteText::escaped(['nl' => 'Deze site zet, naast wat noodzakelijk is om te functioneren, op dit moment geen cookies of trackingtechnieken in voor analyse of marketing.', 'en' => 'Besides what is necessary for the site to function, this site does not currently set any cookies or tracking technology for analytics or marketing.']) ?>
      </p>
<?php if (!$hasOptionalEntriesInUse): ?>
      <p>
        <?= \App\Service\Language\SiteText::escaped(['nl' => 'Er zijn op dit moment dus geen optionele cookies (voorkeuren, analyse of marketing) actief. Als dat verandert — bijvoorbeeld omdat er een bezoekstatistieken-tool wordt toegevoegd — verschijnt die hier met naam, doel, aanbieder en bewaartermijn, en vraagt de cookiemelding opnieuw om toestemming voordat die wordt geladen.', 'en' => 'So no optional cookies (preferences, analytics or marketing) are currently active. If that changes — for example because a visitor-statistics tool is added — it will be listed here with its name, purpose, provider and retention period, and the cookie banner will ask for consent again before it is loaded.']) ?>
      </p>
<?php endif; ?>

      <div class="cookie-policy-table-wrap" style="margin-top: var(--sp-4);">
        <table class="cookie-policy-table">
          <thead>
            <tr>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naam', 'en' => 'Name']) ?></th>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Type', 'en' => 'Type']) ?></th>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Categorie', 'en' => 'Category']) ?></th>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Doel', 'en' => 'Purpose']) ?></th>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Aanbieder', 'en' => 'Provider']) ?></th>
              <th><?= \App\Service\Language\SiteText::escaped(['nl' => 'Bewaartermijn', 'en' => 'Retention']) ?></th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($policyEntries as $entry): ?>
<?php $cat = $categories[$entry['category']]; ?>
            <tr>
              <td><code><?= $h($entry['name']) ?></code></td>
              <td><?= $h($entry['type']) ?></td>
              <td><?= $h($cat['label']) ?></td>
              <td><?= $h($entry['purpose']) ?></td>
              <td><?= $h($entry['provider']) ?></td>
              <td><?= $h($entry['retention']) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h2 style="margin-top: var(--sp-6);"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Categorieën', 'en' => 'Categories']) ?></h2>
      <p>
        <?= \App\Service\Language\SiteText::escaped(['nl' => 'We werken met vier cookiecategorieën. Noodzakelijke cookies staan altijd aan; de rest zet je zelf aan via de cookiemelding of onderstaande knop.', 'en' => 'We work with four cookie categories. Necessary cookies are always on; the rest you switch on yourself via the cookie banner or the button below.']) ?>
      </p>
      <ul style="margin: var(--sp-3) 0; padding-left: 1.2rem; display: flex; flex-direction: column; gap: 0.5rem;">
<?php foreach ($categories as $cat): ?>
        <li><strong><?= $h($cat['label']) ?></strong> — <span><?= $h($cat['description']) ?></span></li>
<?php endforeach; ?>
      </ul>

      <h2 style="margin-top: var(--sp-6);"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Je voorkeuren wijzigen', 'en' => 'Change your preferences']) ?></h2>
      <p>
        <?= \App\Service\Language\SiteText::escaped(['nl' => 'Je kunt je cookievoorkeuren op elk moment bekijken of wijzigen, ook nadat je al een keuze hebt gemaakt.', 'en' => 'You can review or change your cookie preferences at any time, even after you\'ve already made a choice.']) ?>
      </p>
      <p style="margin-top: var(--sp-3);">
        <button type="button" class="btn" data-cookie-settings-open><?= \App\Service\Language\SiteText::escaped(['nl' => 'Cookievoorkeuren beheren', 'en' => 'Manage cookie preferences']) ?></button>
      </p>

    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
