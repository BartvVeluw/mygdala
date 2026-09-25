<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// A project address belongs to the Portfolio module: with it switched off
// every /portfolio/<slug> answers the site's own 404, like a project that never
// existed (App\Module\ModuleGuard). Nothing below runs — the redirect neither,
// since it reads the module's own tables.
\App\Module\ModuleGuard::requirePublicRoute('portfolio');
require_once __DIR__ . '/partials/breadcrumb.php';
require_once __DIR__ . '/partials/lightbox.php';
require_once __DIR__ . '/partials/section-cta-band.php';

/**
 * A Portfolio item's own project page at /portfolio/<slug> (Portfolio 2.0,
 * MODULES.md "Portfolio"): rendered dynamically from the item, never from a
 * `pages` row. One fixed structure, filled by the item's own content —
 * breadcrumb, categories, title, short text, main picture, intro,
 * description, the extra photos and a way back to the Portfolio — through
 * App\Service\PortfolioGalleryContent::itemForDetailPage(). V1 is structured
 * content, deliberately not a page builder: no blocks here.
 *
 * Three answers, in this order:
 *
 *   1. the item still links to a published ordinary page (phase 4B): a
 *      temporary redirect (302) to that page, before anything else is read
 *      (PortfolioGalleryContent::legacyProjectRedirectUrl());
 *   2. a visible item with its project page switched on: that page;
 *   3. otherwise the Redirect Manager, for an address a rename left behind
 *      (App\Service\PortfolioSlug::recordRename()), and then the 404 below.
 *
 * ONE LIGHTBOX (assets/js/lightbox.js, partials/lightbox.php): the main
 * picture and every photo are one group, so previous and next step through
 * this project's own pictures and nothing else on the site.
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

// An address whose item still links to a published ordinary page: send the
// visitor there before anything of the item's own page is read. Temporarily,
// with the CMS's own "borrowed for now" code: the link may still be removed.
// Why it is temporary, and why this is not the Redirect Manager's job:
// PortfolioGalleryContent::legacyProjectRedirectUrl().
$projectPageUrl = \App\Service\PortfolioGalleryContent::legacyProjectRedirectUrl($slug);
if ($projectPageUrl !== null) {
    header('Location: ' . $projectPageUrl, true, \App\Service\Redirects\Redirect::STATUS_TEMPORARY);
    exit;
}

$portfolioItem = \App\Service\PortfolioGalleryContent::itemForDetailPage($slug);

if ($portfolioItem === null) {
    // The one moment the Redirect Manager may speak on this route, and the
    // reason a renamed project's old address keeps working: the dispatcher
    // routed the request here, so 404.php never sees it. The lookup runs
    // after the item lookup failed, so a redirect can never shadow a project
    // that does exist. See REDIRECTS.md.
    \App\Service\Redirects\RedirectGate::handleOr404();

    http_response_code(404);
} else {
    // One neutral slug, answered in every published language: each prefixed
    // address is a version of this page, declared for hreflang and the
    // language switch like every other route (docs/multilingual/ROUTING.md).
    \App\Service\Routing\LanguageAlternates::declareVersions(
        \App\Service\PortfolioSeo::alternates((string) $portfolioItem['slug'])
    );
}

// This route is not a CMS page of its own; it deliberately reuses the
// Portfolio page's CTA band, whichever instance is first there — never a
// hardcoded section_key.
$cta = \App\Service\CtaBandContent::firstOnPage('portfolio');
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

// Title, description, canonical and share image, through the same
// App\Service\SeoMetadata as everything else (App\Service\PortfolioSeo).
$seoMetadata = $portfolioItem === null
    ? \App\Service\PortfolioSeo::notFound()
    : \App\Service\PortfolioSeo::forProject($portfolioItem);

// Home / Portfolio / <project>. The Portfolio level is the CMS page behind
// /portfolio, by its own title and its own address, so a rename follows
// through; a site without that page names the module's own overview through
// its route (BreadcrumbTrail::toPage()'s fallback, PortfolioModule::routes()).
// No pages.parent_id is involved: a project is not a page.
$projectName = $portfolioItem !== null && trim((string) $portfolioItem['title']) !== ''
    ? (string) $portfolioItem['title']
    : \App\Service\Language\SiteText::pick(['nl' => 'Project', 'en' => 'Project']);
$breadcrumb = \App\Service\Breadcrumbs\BreadcrumbTrail::home()
    ->toPage('portfolio', 'portfolio')
    ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(
        $portfolioItem === null
            ? \App\Service\Language\SiteText::pick(['nl' => 'Project niet gevonden', 'en' => 'Project not found'])
            : $projectName
    ));

// The way back, only to an overview a visitor may open: the overview page
// while it is published, the module's own overview while there is no page.
$portfolioPage = \App\Service\PortfolioUrls::overviewPage();
if ($portfolioPage === null) {
    $portfolioUrl = \App\Service\Routing\LocalizedUrl::path(\App\Service\PortfolioUrls::OVERVIEW_PATH);
} else {
    $portfolioUrl = \App\Service\PageContent::isPublished($portfolioPage)
        && \App\Service\PageContent::isServedByAnEnabledModule($portfolioPage)
        ? \App\Service\PageContent::publicUrl($portfolioPage)
        : null;
}

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
// This page is not built out of content blocks, so it asks for the site's
// one lightbox itself.
if ($portfolioItem !== null) {
    \App\Service\PageAssets::requireScript('assets/js/lightbox.js');
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'portfolio';
require __DIR__ . '/partials/header.php';
?>

<main id="main">

<?php render_breadcrumb($breadcrumb); ?>

<?php if ($portfolioItem === null): ?>
  <section class="page-hero">
    <div class="container">
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Project niet gevonden', 'en' => 'Project not found']) ?></h1>
      <p class="lead" style="margin-top:1rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Dit project bestaat niet (meer) of is niet zichtbaar.', 'en' => 'This project doesn\'t exist (anymore) or isn\'t visible.']) ?></p>
      <?php if ($portfolioUrl !== null): ?>
      <a href="<?= $h($portfolioUrl) ?>" class="btn" style="margin-top:1.5rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar portfolio', 'en' => 'To portfolio']) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
      <?php endif; ?>
    </div>
  </section>
<?php else: ?>
  <?php
    $zoomLabel = static fn (string $name): string => \App\Service\Language\SiteText::pick(['nl' => 'Vergroot afbeelding: ', 'en' => 'Enlarge image: ']) . $name;
    $hasMainImage = (string) $portfolioItem['image_path'] !== '';
  ?>
  <section class="project-hero" data-lightbox-group>
    <div class="container">
      <div class="project-hero__grid">
        <?php if ($hasMainImage): ?>
        <figure class="project-hero__media" data-reveal>
          <button type="button" class="project-hero__zoom" data-lightbox-trigger
            data-src="/<?= $h($portfolioItem['image_path']) ?>"
            data-alt="<?= $h($portfolioItem['alt']) ?>"
            data-caption="<?= $h($portfolioItem['alt']) ?>"
            aria-label="<?= $h($zoomLabel($projectName)) ?>">
            <img src="/<?= $h($portfolioItem['image_path']) ?>" alt="<?= $h($portfolioItem['alt']) ?>" class="project-hero__image" fetchpriority="high">
          </button>
        </figure>
        <?php endif; ?>

        <div class="project-hero__panel" data-reveal data-reveal-group="project-hero">
          <?php if ($portfolioItem['categories'] !== []): ?>
            <ul class="tag-list">
              <?php foreach ($portfolioItem['categories'] as $category): ?>
                <li class="tag"><?= $h($category['name']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <h1 class="project-hero__title"><?= $h($projectName) ?></h1>

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

          <?php if ($portfolioUrl !== null): ?>
            <a class="project-hero__back" href="<?= $h($portfolioUrl) ?>"><?= \App\Service\Language\SiteText::escaped(['nl' => '← Terug naar portfolio', 'en' => '← Back to portfolio']) ?></a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($portfolioItem['images'] !== []): ?>
      <div class="project-gallery-section">
        <h2 class="visually-hidden"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Meer afbeeldingen', 'en' => 'More images']) ?></h2>
        <div class="project-gallery">
          <?php foreach ($portfolioItem['images'] as $position => $extraImage): ?>
            <?php $photoName = $extraImage['alt'] !== '' ? $extraImage['alt'] : $projectName . ' (' . ($position + 2) . ')'; ?>
            <button type="button" class="project-gallery__item" data-lightbox-trigger
              data-src="/<?= $h($extraImage['image_path']) ?>"
              data-alt="<?= $h($extraImage['alt']) ?>"
              data-caption="<?= $h($extraImage['alt']) ?>"
              aria-label="<?= $h($zoomLabel($photoName)) ?>"
              data-reveal data-reveal-group="project-gallery">
              <img src="/<?= $h($extraImage['thumbnail_path']) ?>" alt="<?= $h($extraImage['alt']) ?>" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php render_lightbox_overlay(); ?>
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
