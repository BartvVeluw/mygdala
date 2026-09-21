<?php

/**
 * The 404 head tags and body every CMS page route shares — extracted from
 * pagina.php when Diensten, Portfolio, Over mij and Contact stopped being
 * protected pages (docs/content-blocks/DECISIONS.md).
 *
 * Those four are ordinary content pages that merely happen to be served from
 * their own PHP file, so the administrator may now set them to Concept or
 * delete them. That is only honest if their URL then really stops resolving:
 * without this, /diensten.php would keep answering 200 with an empty page.
 * A draft page, a deleted page and an unknown URL are deliberately
 * indistinguishable to a visitor.
 */

require_once __DIR__ . '/breadcrumb.php';

function render_page_not_found_head(): void
{
    // The one shared renderer, like every other public page — so a 404 gets
    // the same escaping and the same tag set, minus everything a page that
    // does not exist has no business claiming. App\Service\SeoMetadata::notFound()
    // is what decides that: noindex,follow, no canonical, no description and
    // no share image, not even the site-wide defaults.
    $seoMetadata = \App\Service\SeoMetadata::notFound(
        \App\Service\Language\SiteText::pick(['nl' => 'Pagina niet gevonden', 'en' => 'Page not found']) . ' — ' . \App\Service\SeoDefaults::siteName()
    );
    require __DIR__ . '/seo-head.php';
}

function render_page_not_found(): void
{
    // A 404 says where a visitor is too — the one level it can honestly name
    // is that this page is not there. The homepage link above it is the way
    // out, which is the whole reason the trail is here.
    render_breadcrumb(
        \App\Service\Breadcrumbs\BreadcrumbTrail::home()
            ->to(\App\Service\Breadcrumbs\BreadcrumbItem::current(\App\Service\Language\SiteText::pick(['nl' => 'Pagina niet gevonden', 'en' => 'Page not found'])))
    );
    ?>
  <section class="page-hero">
    <div class="container">
      <h1><?= \App\Service\Language\SiteText::escaped(['nl' => 'Pagina niet gevonden', 'en' => 'Page not found']) ?></h1>
      <p class="lead" style="margin-top:1rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Deze pagina bestaat niet (meer) of is niet zichtbaar.', 'en' => 'This page doesn\'t exist (anymore) or isn\'t visible.']) ?></p>
      <a href="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::path('/'), ENT_QUOTES, 'UTF-8') ?>" class="btn" style="margin-top:1.5rem;"><?= \App\Service\Language\SiteText::escaped(['nl' => 'Naar de homepage', 'en' => 'To the homepage']) ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </section>
    <?php
}
