<?php

declare(strict_types=1);

/**
 * The <head> SEO block for a CMS page. Include inside <head>, having first
 * set:
 *
 *   $page (array|null) a `pages` row — normally from
 *                      App\Service\PageContent::forContentKey() on one of the
 *                      six system page templates, or ::forSlug() in
 *                      pagina.php. null renders a safe minimal head.
 *
 * A handful of lines, because it does exactly two things: ask
 * App\Service\PageSeo what this page's effective metadata is, and hand the
 * answer to partials/seo-head.php, which renders it the same way it renders
 * a product's, a collection's or the cart's. Every rule this file used to
 * carry — the title fallback, the description fallback, the canonical URL,
 * the Open Graph copy — moved into those two, so there is one hierarchy and
 * one renderer instead of one per content type.
 *
 * $page === null only happens when the page row is missing or the database
 * is unreachable; the head then degrades to the site name alone. That is
 * acceptable precisely because in that same situation the body renders no
 * sections either (all page content is database-backed) — there is no
 * scenario where a visitor sees a complete page with a wrong title. A route
 * that has decided it cannot answer is a different case and renders
 * render_page_not_found_head() (partials/page-not-found.php) instead, which
 * adds the noindex a 404 needs.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\PageSeo;

$seoMetadata = PageSeo::forPage($page ?? null);
require __DIR__ . '/seo-head.php';
