<?php
require_once __DIR__ . '/vendor/autoload.php';

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
// place a second site could not be deployed from.
$siteName = \App\Service\SiteSettings::get('site_name');
$seoMetadata = \App\Service\SeoMetadata::create(
    titleNl: \App\Service\Seo::routeTitle('Cookiebeleid'),
    titleEn: \App\Service\Seo::routeTitle('Cookie policy'),
    descriptionNl: 'Welke cookies en lokale opslag ' . $siteName . ' gebruikt, waarvoor, en hoe je je voorkeuren kunt beheren.',
    descriptionEn: 'Which cookies and local storage ' . $siteName . ' uses, what for, and how to manage your preferences.',
    canonical: \App\Service\AppUrl::canonical('cookiebeleid.php'),
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
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <section class="page-hero">
    <div class="container">
      <div class="breadcrumb">
        <a href="index.php" data-nl="Home" data-en="Home">Home</a><span>/</span><span data-nl="Cookiebeleid" data-en="Cookie policy">Cookiebeleid</span>
      </div>
      <p class="eyebrow" data-nl="Juridisch" data-en="Legal">Juridisch</p>
      <h1 data-nl="Cookiebeleid" data-en="Cookie policy">Cookiebeleid</h1>
      <p class="lead" style="margin-top:1rem;" data-nl="Een overzicht van welke cookies en lokale opslag deze site gebruikt, waarvoor, en hoe lang." data-en="An overview of which cookies and local storage this site uses, what for, and for how long.">Een overzicht van welke cookies en lokale opslag deze site gebruikt, waarvoor, en hoe lang.</p>
    </div>
  </section>

  <section style="padding-top:0;">
    <div class="container" style="max-width: var(--container-narrow);">

      <h2 data-nl="Wat we gebruiken" data-en="What we use">Wat we gebruiken</h2>
      <p data-nl="Deze site zet, naast wat noodzakelijk is om te functioneren, op dit moment geen cookies of trackingtechnieken in voor analyse of marketing." data-en="Besides what is necessary for the site to function, this site does not currently set any cookies or tracking technology for analytics or marketing.">
        Deze site zet, naast wat noodzakelijk is om te functioneren, op dit moment geen cookies of trackingtechnieken in voor analyse of marketing.
      </p>
<?php if (!$hasOptionalEntriesInUse): ?>
      <p data-nl="Er zijn op dit moment dus geen optionele cookies (voorkeuren, analyse of marketing) actief. Als dat verandert — bijvoorbeeld omdat er een bezoekstatistieken-tool wordt toegevoegd — verschijnt die hier met naam, doel, aanbieder en bewaartermijn, en vraagt de cookiemelding opnieuw om toestemming voordat die wordt geladen." data-en="So no optional cookies (preferences, analytics or marketing) are currently active. If that changes — for example because a visitor-statistics tool is added — it will be listed here with its name, purpose, provider and retention period, and the cookie banner will ask for consent again before it is loaded.">
        Er zijn op dit moment dus geen optionele cookies (voorkeuren, analyse of marketing) actief. Als dat verandert — bijvoorbeeld omdat er een bezoekstatistieken-tool wordt toegevoegd — verschijnt die hier met naam, doel, aanbieder en bewaartermijn, en vraagt de cookiemelding opnieuw om toestemming voordat die wordt geladen.
      </p>
<?php endif; ?>

      <div class="cookie-policy-table-wrap" style="margin-top: var(--sp-4);">
        <table class="cookie-policy-table">
          <thead>
            <tr>
              <th data-nl="Naam" data-en="Name">Naam</th>
              <th data-nl="Type" data-en="Type">Type</th>
              <th data-nl="Categorie" data-en="Category">Categorie</th>
              <th data-nl="Doel" data-en="Purpose">Doel</th>
              <th data-nl="Aanbieder" data-en="Provider">Aanbieder</th>
              <th data-nl="Bewaartermijn" data-en="Retention">Bewaartermijn</th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($policyEntries as $entry): ?>
<?php $cat = $categories[$entry['category']]; ?>
            <tr>
              <td><code><?= $h($entry['name']) ?></code></td>
              <td data-nl="<?= $h($entry['type_nl']) ?>" data-en="<?= $h($entry['type_en']) ?>"><?= $h($entry['type_nl']) ?></td>
              <td data-nl="<?= $h($cat['label_nl']) ?>" data-en="<?= $h($cat['label_en']) ?>"><?= $h($cat['label_nl']) ?></td>
              <td data-nl="<?= $h($entry['purpose_nl']) ?>" data-en="<?= $h($entry['purpose_en']) ?>"><?= $h($entry['purpose_nl']) ?></td>
              <td><?= $h($entry['provider']) ?></td>
              <td data-nl="<?= $h($entry['retention_nl']) ?>" data-en="<?= $h($entry['retention_en']) ?>"><?= $h($entry['retention_nl']) ?></td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h2 style="margin-top: var(--sp-6);" data-nl="Categorieën" data-en="Categories">Categorieën</h2>
      <p data-nl="We werken met vier cookiecategorieën. Noodzakelijke cookies staan altijd aan; de rest zet je zelf aan via de cookiemelding of onderstaande knop." data-en="We work with four cookie categories. Necessary cookies are always on; the rest you switch on yourself via the cookie banner or the button below.">
        We werken met vier cookiecategorieën. Noodzakelijke cookies staan altijd aan; de rest zet je zelf aan via de cookiemelding of onderstaande knop.
      </p>
      <ul style="margin: var(--sp-3) 0; padding-left: 1.2rem; display: flex; flex-direction: column; gap: 0.5rem;">
<?php foreach ($categories as $cat): ?>
        <li><strong data-nl="<?= $h($cat['label_nl']) ?>" data-en="<?= $h($cat['label_en']) ?>"><?= $h($cat['label_nl']) ?></strong> — <span data-nl="<?= $h($cat['description_nl']) ?>" data-en="<?= $h($cat['description_en']) ?>"><?= $h($cat['description_nl']) ?></span></li>
<?php endforeach; ?>
      </ul>

      <h2 style="margin-top: var(--sp-6);" data-nl="Je voorkeuren wijzigen" data-en="Change your preferences">Je voorkeuren wijzigen</h2>
      <p data-nl="Je kunt je cookievoorkeuren op elk moment bekijken of wijzigen, ook nadat je al een keuze hebt gemaakt." data-en="You can review or change your cookie preferences at any time, even after you've already made a choice.">
        Je kunt je cookievoorkeuren op elk moment bekijken of wijzigen, ook nadat je al een keuze hebt gemaakt.
      </p>
      <p style="margin-top: var(--sp-3);">
        <button type="button" class="btn" data-cookie-settings-open data-nl="Cookievoorkeuren beheren" data-en="Manage cookie preferences">Cookievoorkeuren beheren</button>
      </p>

    </div>
  </section>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
