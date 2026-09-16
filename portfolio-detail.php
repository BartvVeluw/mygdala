<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
// An old project address belongs to the Portfolio module: with it switched off
// every /portfolio/<slug> answers the site's own 404, like a project that never
// existed (App\Module\ModuleGuard). Nothing below runs — the redirect neither,
// since it reads the module's own tables.
\App\Module\ModuleGuard::requirePublicRoute('portfolio');
require_once __DIR__ . '/partials/breadcrumb.php';

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

if ($portfolioItem === null) {
    http_response_code(404);
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
        'Project niet gevonden — ' . \App\Service\SeoDefaults::siteName(),
        'Project not found — ' . \App\Service\SeoDefaults::siteName()
    )
    : \App\Service\SeoMetadata::create(
        titleNl: $portfolioItem['title_nl'] . ' | Portfolio — ' . \App\Service\SeoDefaults::siteName(),
        titleEn: $portfolioItem['title_en'] . ' | Portfolio — ' . \App\Service\SeoDefaults::siteName(),
        descriptionNl: (string) $portfolioItem['subtitle_nl'],
        descriptionEn: (string) $portfolioItem['subtitle_en'],
        canonical: \App\Service\PortfolioGalleryContent::canonicalUrlForSlug((string) $portfolioItem['slug']),
        // og:type stays "website", the value this page has always emitted —
        // the same call product.php makes, for the same reason (SEO.md).
        socialImage: (string) ($portfolioItem['image_path'] ?? ''),
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
          ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current('Project niet gevonden', 'Project not found'))
  ); ?>
  <section class="page-hero">
    <div class="container">
      <h1 data-nl="Project niet gevonden" data-en="Project not found">Project niet gevonden</h1>
      <p class="lead" style="margin-top:1rem;" data-nl="Dit project bestaat niet (meer) of is niet zichtbaar. Bekijk de rest van het portfolio hieronder." data-en="This project doesn't exist (anymore) or isn't visible. Browse the rest of the portfolio below.">Dit project bestaat niet (meer) of is niet zichtbaar. Bekijk de rest van het portfolio hieronder.</p>
      <a href="/portfolio.php" class="btn" style="margin-top:1.5rem;" data-nl="Naar portfolio" data-en="To portfolio">Naar portfolio
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </section>
<?php else: ?>
  <section class="project-hero">
    <div class="container">
      <a class="project-hero__back" href="/portfolio.php" data-nl="&larr; Terug naar portfolio" data-en="&larr; Back to portfolio">&larr; Terug naar portfolio</a>

      <div class="project-hero__grid">
        <figure class="project-hero__media" data-reveal>
          <button type="button" class="project-hero__zoom" data-project-lightbox-trigger
            data-src="/<?= $h($portfolioItem['image_path']) ?>"
            data-alt-nl="<?= $h($portfolioItem['alt_nl']) ?>" data-alt-en="<?= $h($portfolioItem['alt_en']) ?>"
            aria-label="Bekijk in groot formaat" data-nl-aria="Bekijk in groot formaat" data-en-aria="View full size">
            <img src="/<?= $h($portfolioItem['image_path']) ?>" alt="<?= $h($portfolioItem['alt_nl']) ?>" data-nl-alt="<?= $h($portfolioItem['alt_nl']) ?>" data-en-alt="<?= $h($portfolioItem['alt_en']) ?>" class="project-hero__image" fetchpriority="high">
          </button>
        </figure>

        <div class="project-hero__panel" data-reveal data-reveal-group="project-hero">
          <?php if ($portfolioItem['categories'] !== []): ?>
            <ul class="tag-list">
              <?php foreach ($portfolioItem['categories'] as $category): ?>
                <li class="tag" data-nl="<?= $h($category['name_nl']) ?>" data-en="<?= $h($category['name_en']) ?>"><?= $h($category['name_nl']) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <h1 class="project-hero__title" data-nl="<?= $h($portfolioItem['title_nl']) ?>" data-en="<?= $h($portfolioItem['title_en']) ?>"><?= $h($portfolioItem['title_nl']) ?></h1>

          <?php if ($portfolioItem['subtitle_nl'] !== ''): ?>
            <p class="lead project-hero__subtitle" data-nl="<?= $h($portfolioItem['subtitle_nl']) ?>" data-en="<?= $h($portfolioItem['subtitle_en']) ?>"><?= $h($portfolioItem['subtitle_nl']) ?></p>
          <?php endif; ?>

          <span class="project-hero__divider" aria-hidden="true"></span>

          <?php if ($portfolioItem['intro_nl'] !== ''): ?>
            <?php
              // intro_nl/en are already sanitized HTML (RichTextSanitizer, both at
              // save time and again in PortfolioGalleryContent::itemForDetailPage())
              // — rendered here as real markup, never escaped back to plain text.
              // $h() below is for the data-nl/data-en ATTRIBUTE (a different
              // escaping context, so assets/js/core.js's language switch gets the
              // exact same HTML back out on an NL/EN toggle. data-lang-html marks
              // this as genuinely HTML: applyLang() re-renders a marked element
              // with innerHTML, where a plain-text field now gets textContent.
            ?>
            <div class="rich-content rich-content--intro" data-lang-html data-nl="<?= $h($portfolioItem['intro_nl']) ?>" data-en="<?= $h($portfolioItem['intro_en']) ?>"><?= $portfolioItem['intro_nl'] ?></div>
          <?php endif; ?>
          <?php if ($portfolioItem['description_nl'] !== ''): ?>
            <div class="rich-content" data-lang-html data-nl="<?= $h($portfolioItem['description_nl']) ?>" data-en="<?= $h($portfolioItem['description_en']) ?>"><?= $portfolioItem['description_nl'] ?></div>
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
            data-alt-nl="<?= $h($extraImage['alt_nl']) ?>" data-alt-en="<?= $h($extraImage['alt_en']) ?>"
            aria-label="Bekijk in groot formaat" data-nl-aria="Bekijk in groot formaat" data-en-aria="View full size"
            data-reveal data-reveal-group="project-gallery">
            <img src="/<?= $h($extraImage['thumbnail_path']) ?>" alt="<?= $h($extraImage['alt_nl']) ?>" data-nl-alt="<?= $h($extraImage['alt_nl']) ?>" data-en-alt="<?= $h($extraImage['alt_en']) ?>" loading="lazy">
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>

<?php if ($portfolioItem !== null): ?>
<div class="lightbox" data-project-lightbox aria-hidden="true">
  <button class="lightbox__close" data-lightbox-close aria-label="Sluiten" data-nl-aria="Sluiten" data-en-aria="Close">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button class="lightbox__nav lightbox__nav--prev" data-lightbox-prev aria-label="Vorige" data-nl-aria="Vorige" data-en-aria="Previous">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
  </button>
  <button class="lightbox__nav lightbox__nav--next" data-lightbox-next aria-label="Volgende" data-nl-aria="Volgende" data-en-aria="Next">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
  </button>
  <div class="lightbox__inner">
    <img src="" alt="">
    <p class="lightbox__caption"></p>
    <p class="lightbox__counter" data-lightbox-counter></p>
  </div>
</div>
<?php endif; ?>

  <?php if ($cta['state'] !== \App\Service\CtaBandContent::STATE_HIDDEN): ?>
  <section>
    <div class="container">
      <div class="cta-band cta-band--card" data-reveal>
        <p class="eyebrow" data-nl="<?= $h($cta['eyebrow_nl']) ?>" data-en="<?= $h($cta['eyebrow_en']) ?>"><?= $h($cta['eyebrow_nl']) ?></p>
        <h2 data-nl="<?= $h($cta['title_nl']) ?>" data-en="<?= $h($cta['title_en']) ?>"><?= $h($cta['title_nl']) ?></h2>
        <?php if ($cta['lead_nl'] !== ''): ?>
        <p class="lead" data-nl="<?= $h($cta['lead_nl']) ?>" data-en="<?= $h($cta['lead_en']) ?>"><?= $h($cta['lead_nl']) ?></p>
        <?php endif; ?>
        <div class="cta-band__actions">
          <a href="<?= $h($cta['primary_url']) ?>" class="btn" data-nl="<?= $h($cta['primary_label_nl']) ?>" data-en="<?= $h($cta['primary_label_en']) ?>"><?= $h($cta['primary_label_nl']) ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
          </a>
          <?php if ($cta['secondary_label_nl'] !== ''): ?>
          <a href="<?= $h($cta['secondary_url']) ?>" class="btn btn--ghost" data-nl="<?= $h($cta['secondary_label_nl']) ?>" data-en="<?= $h($cta['secondary_label_en']) ?>"><?= $h($cta['secondary_label_nl']) ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
