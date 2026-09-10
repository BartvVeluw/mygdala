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

function render_page_not_found_head(): void
{
    // The one shared renderer, like every other public page — so a 404 gets
    // the same escaping and the same tag set, minus everything a page that
    // does not exist has no business claiming. App\Service\SeoMetadata::notFound()
    // is what decides that: noindex,follow, no canonical, no description and
    // no share image, not even the site-wide defaults.
    $seoMetadata = \App\Service\SeoMetadata::notFound(
        'Pagina niet gevonden — ' . \App\Service\SeoDefaults::siteName(),
        'Page not found — ' . \App\Service\SeoDefaults::siteName()
    );
    require __DIR__ . '/seo-head.php';
}

function render_page_not_found(): void
{
    ?>
  <section class="page-hero">
    <div class="container">
      <div class="breadcrumb">
        <a href="/index.php" data-nl="Home" data-en="Home">Home</a><span>/</span>
        <span data-nl="Pagina niet gevonden" data-en="Page not found">Pagina niet gevonden</span>
      </div>
      <h1 data-nl="Pagina niet gevonden" data-en="Page not found">Pagina niet gevonden</h1>
      <p class="lead" style="margin-top:1rem;" data-nl="Deze pagina bestaat niet (meer) of is niet zichtbaar." data-en="This page doesn't exist (anymore) or isn't visible.">Deze pagina bestaat niet (meer) of is niet zichtbaar.</p>
      <a href="/index.php" class="btn" style="margin-top:1.5rem;" data-nl="Naar de homepage" data-en="To the homepage">Naar de homepage
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
      </a>
    </div>
  </section>
    <?php
}
