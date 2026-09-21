<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// An old project address belongs to the Portfolio module: with it switched off
// every /portfolio/<slug> answers the site's own 404, like a project that never
// existed (App\Module\ModuleGuard). Nothing below runs — the redirect neither,
// since it reads the module's own tables.
\App\Module\ModuleGuard::requirePublicRoute('portfolio');
require_once __DIR__ . '/partials/breadcrumb.php';
require_once __DIR__ . '/partials/section-cta-band.php';

/**
 * The OLD project page: the page a Portfolio item could switch on at
 * /portfolio/<slug> (see .htaccess) before a project page became an ordinary
 * CMS page that the item links to (MODULES.md, "Portfolio"). Edited nowhere
 * any more, and kept so that no address that was ever public silently breaks.
 *
 * During the transition this address is a compatibility route. An address
 * whose item links to a published page is answered with a temporary redirect
 * (302) to that page, before anything of the old page is read
 * (App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl()). Every
 * other address renders what it always rendered: one fixed structure (back
 * link, title/subtitle, main image, intro, description, additional image
 * gallery) driven by the item's own stored content, or the 404 below
 * (App\Service\PortfolioGalleryContent::itemForDetailPage()).
 *
 * Uses $portfolioItem (not $item) deliberately: partials/header.php and
 * partials/footer.php both run a `foreach ($navItems as $key => $item)` in
 * this same top-level scope (require, not a function call), which would
 * silently clobber a variable named $item the moment either partial is
 * included — this file is served from a nested path (/portfolio/<slug>), so
 * every asset/nav URL below must also be root-relative ("/assets/...", not
 * "assets/..." as other top-level pages use) or it 404s from that path.
 */

$slug = (string) ($_GET['slug'] ?? '');

// An old address whose item links to a published page: send the visitor there
// before anything of the old page is read. Temporarily, with the CMS's own
// "borrowed for now" code: this is a compatibility route, and the link behind
// it may still be changed or removed. Why it is temporary, and why this is not
// the Redirect Manager's job: PortfolioGalleryContent::legacyProjectRedirectUrl().
$projectPageUrl = \App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl($slug);
if ($projectPageUrl !== null) {
    header('Location: ' . $projectPageUrl, true, \App\Service\Redirects\Redirect::STATUS_TEMPORARY);
    exit;
}

$portfolioItem = \App\Service\PortfolioGalleryContent::itemForDetailPage($slug);

// This route is not a CMS page of its own; it deliberately reuses the
// Portfolio page's CTA band, whichever instance is first there — never a
// hardcoded section_key.
$cta = \App\Service\CtaBandContent::firstOnPage('portfolio');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// The item's words arrive as one string per field from
// App\Service\PortfolioLocalization, already in the language of the request
// with the fallback applied. This file names no language of its own.
//
// assets/js/portfolio-detail.js reads the lightbox caption from each
// trigger's own `data-alt`, the same alt text the thumbnail carries.

if ($portfolioItem === null) {
    http_response_code(404);
} else {
    // One neutral slug, answered in every published language: each prefixed
    // address is a version of this page, declared for hreflang and the
    // language switch like every other route (docs/multilingual/ROUTING.md).
    $projectVersions = [];
    foreach (\App\Service\Language\SiteLanguages::activeCodes() as $projectLanguage) {
        $projectVersions[$projectLanguage] = \App\Service\Routing\LocalizedUrl::path(
            \App\Service\PortfolioGalleryContent::publicPath((string) $portfolioItem['slug']),
            $projectLanguage
        );
    }
    \App\Service\Routing\LanguageAlternates::declareVersions($projectVersions);
}
// A Portfolio project is not a CMS page and not a shop item, so its
// metadata is resolved here — but through the same App\Service\SeoMetadata
// as everything else, and rendered by the one shared partial. Its title
// convention ("<project> | Portfolio — <site name>") and its description
// (the project's subtitle) are exactly what this file rendered before.
//
// The project's own main photo becomes the share image, which is what the
// visitor sees anyway; a project without one falls back to the site-wide
// Standaard deel-afbeelding, the same chain a product follows.
$seoMetadata = $portfolioItem === null
    ? \App\Service\SeoMetadata::notFound(
        \App\Service\Language\SiteText::pick(['nl' => 'Project niet gevonden', 'en' => 'Project not found']) . ' — ' . \App\Service\SeoDefaults::siteName()
    )
    : \App\Service\SeoMetadata::create(
        // The item's own words in the language of the request, read through
        // App\Service\PortfolioLocalization with its fallback.
        title: $portfolioItem['title'] . ' | Portfolio — ' . \App\Service\SeoDefaults::siteName(),
        description: $portfolioItem['subtitle'],
        canonical: \App\Service\PortfolioGalleryContent::canonicalUrlForSlug((string) $portfolioItem['slug']),
        // og:type stays "website", the value this page has always emitted —
        // the same call product.php makes, for the same reason (SEO.md).
        socialImage: (string) ($portfolioItem['image_path'] ?? ''),
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
// This page is not built out of content blocks, so it asks for its
// own project lightbox itself.
\App\Service\PageAssets::requireScript('assets/js/portfolio-detail.js');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'portfolio';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

<?php if ($portfolioItem === null): ?>
  <?php /* The Portfolio level is that CMS page, by its own title and its own
           address, so a rename follows through. The last level is what this
           page is, which keeps the link above it clickable. A project that
           IS found keeps its own "Terug naar portfolio" link and no trail —
           this legacy detail page is on its way out (MODULES.md). */ ?>
  <?php render_breadcrumb(
      \App\Service\Breadcrumbs\BreadcrumbTrail::home()
          ->toPage('portfolio')
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Project niet gevonden', 'en' => 'Project not found'])))
  ); ?>
  <section class="page-hero">
    <div class="container">
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Project niet gevonden', 'en' => 'Project not found']) ?></h1>
      <p class="lead" style="margin-top:1rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit project bestaat niet (meer) of is niet zichtbaar. Bekijk de rest van het portfolio hieronder.', 'en' => 'This project doesn\'t exist (anymore) or isn\'t visible. Browse the rest of the portfolio below.']) ?></p>
      <a href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/portfolio.php')) ?>" class="btn" style="margin-top:1.5rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar portfolio', 'en' => 'To portfolio']) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </section>
<?php else: ?>
  <section class="project-hero">
    <div class="container">
      <a class="project-hero__back" href="<?= $h(\App\Service\Routing\LocalizedUrl::path('/portfolio.php')) ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => '← Terug naar portfolio', 'en' => '← Back to portfolio']) ?></a>

      <div class="project-hero__grid">
        <figure class="project-hero__media" data-reveal>
          <button type="button" class="project-hero__zoom" data-project-lightbox-trigger
            data-src="/<?= $h($portfolioItem['image_path']) ?>"
            data-alt="<?= $h($portfolioItem['alt']) ?>"
            aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Bekijk in groot formaat', 'en' => 'View full size']) ?>">
            <img src="/<?= $h($portfolioItem['image_path']) ?>" alt="<?= $h($portfolioItem['alt']) ?>" class="project-hero__image" fetchpriority="high">
          </button>
        </figure>

        <div class="project-hero__panel" data-reveal data-reveal-group="project-hero">
          <?php if ($portfolioItem['categories'] !== []): ?>
            <ul class="tag-list">
              <?php foreach ($portfolioItem['categories'] as $category): ?>
                <li class="tag"><?= $h($category['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <h1 class="project-hero__title"><?= $h($portfolioItem['title']) ?></h1>

          <?php if ($portfolioItem['subtitle'] !== ''): ?>
            <p class="lead project-hero__subtitle"><?= $h($portfolioItem['subtitle']) ?></p>
          <?php endif; ?>

          <span class="project-hero__divider" aria-hidden="true"></span>

          <?php if ($portfolioItem['intro'] !== ''): ?>
            <?php
              // `intro` and `description` are already sanitized HTML in the
              // language of the request (RichTextSanitizer, both at save time
              // and again in App\Service\PortfolioLocalization::itemRich()) —
              // rendered here as real markup, never escaped back to plain
              // text.
            ?>
            <div class="rich-content rich-content--intro"><?= $portfolioItem['intro'] ?></div>
          <?php endif; ?>
          <?php if ($portfolioItem['description'] !== ''): ?>
            <div class="rich-content"><?= $portfolioItem['description'] ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if ($portfolioItem['images'] !== []): ?>
  <section class="project-gallery-section">
    <div class="container">
      <div class="project-gallery">
        <?php foreach ($portfolioItem['images'] as $extraImage): ?>
          <button type="button" class="project-gallery__item" data-project-lightbox-trigger
            data-src="/<?= $h($extraImage['image_path']) ?>"
            data-alt="<?= $h($extraImage['alt']) ?>"
            aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Bekijk in groot formaat', 'en' => 'View full size']) ?>"
            data-reveal data-reveal-group="project-gallery">
            <img src="/<?= $h($extraImage['thumbnail_path']) ?>" alt="<?= $h($extraImage['alt']) ?>" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php if ($portfolioItem !== null): ?>
<div class="lightbox" data-project-lightbox aria-hidden="true">
  <button class="lightbox__close" data-lightbox-close aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Sluiten', 'en' => 'Close']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button class="lightbox__nav lightbox__nav--prev" data-lightbox-prev aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Vorige', 'en' => 'Previous']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
  </button>
  <button class="lightbox__nav lightbox__nav--next" data-lightbox-next aria-label="<?= \App\Service\Language\SiteText::escaped(['nl' => 'Volgende', 'en' => 'Next']) ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
  </button>
  <div class="lightbox__inner">
    <img src="" alt="">
    <p class="lightbox__caption"></p>
    <p class="lightbox__counter" data-lightbox-counter></p>
  </div>
</div>
<?php endif; ?>

  <?php
  // The CTA band block's own partial, not a copy of its markup: its words
  // are stored per website language and printed through SiteText, and an
  // empty band renders nothing, exactly as on the Portfolio page itself.
  if ($cta['state'] !== \App\Service\CtaBandContent::STATE_HIDDEN) {
      render_section_cta_band($cta);
  }
  ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
