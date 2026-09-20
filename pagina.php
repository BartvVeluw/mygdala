<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

/**
 * The generic frontend template for every dynamic CMS page: one file that
 * renders ANY published, non-system page from the `pages` table, composed
 * entirely out of page-builder sections.
 *
 * Routing: .htaccess rewrites a bare single-segment path /<slug> here (see
 * that file's docblock for the two filesystem checks that keep it from ever
 * swallowing a real application route, and App\Service\ReservedRoutes for
 * the save-time half of the same rule). Creating and publishing a page in
 * the admin therefore makes its URL work immediately — no new PHP file, no
 * new RewriteRule, no migration.
 *
 * This replaces informatiepagina.php, which did the same job for the old
 * information_pages table but could only ever render one fixed layout (hero
 * + a single rich-text column). Those pages are now ordinary `pages` rows
 * whose body is a Page Hero section plus a Rich text section, so they render
 * identically here while being freely extendable with any other section
 * type.
 *
 * A draft page, an unknown slug and a deleted page are deliberately
 * indistinguishable to a visitor: all three produce the same 404 below
 * (PageContent::forSlug() only ever returns published pages), so unpublished
 * content can never leak through this route.
 */

$slug = (string) ($_GET['slug'] ?? '');
$page = \App\Service\PageContent::forSlug($slug);

// A system page's own PHP template is its only public entry point; its
// content_key must never also resolve here (that would serve e.g. the shop
// page's sections at /shop without the product grid). In practice .htaccess
// never routes those words here at all, because a matching root-level
// <word>.php file exists — this is the belt-and-braces half of the same rule.
if ($page !== null && \App\Service\PageContent::hasOwnTemplate($page)) {
    $page = null;
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if ($page === null) {
    // No published page owns this slug, so this request is about to become a
    // 404 — the one moment the Redirect Manager may speak. Apache DID route
    // this request (the slug matched .htaccess's CMS-page rule), so its
    // ErrorDocument never fires and 404.php never sees it; this is the only
    // place a redirect can rescue a renamed page's old URL. The lookup runs
    // AFTER the page lookup has failed, which is what makes it impossible for
    // a redirect to shadow a page that does exist. See REDIRECTS.md.
    \App\Service\Redirects\RedirectGate::handleOr404();

    http_response_code(404);
}
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($page === null): ?>
<?php render_page_not_found_head(); ?>
<?php else: ?>
<?php require __DIR__ . '/partials/page-head.php'; ?>
<?php endif; ?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
if ($page !== null) {
    \App\Service\SectionRegistry::collectPageAssets((string) $page['content_key']);
}
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>

<main id="main">

<?php if ($page === null): ?>
  <?php render_page_not_found(); ?>
<?php else: ?>
  <?php
    // Where the visitor is, before the page's own content and independent of
    // it: a page whose header is hidden or missing still says where it sits
    // (App\Service\Breadcrumbs\PageBreadcrumb).
    render_breadcrumb(\App\Service\Breadcrumbs\PageBreadcrumb::forPage($page));

    // The page's whole body is its one ordered list of content blocks,
    // exactly like every other CMS page.
    \App\Service\SectionRegistry::renderPage((string) $page['content_key']);
  ?>
<?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
