<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';
// This route belongs to the Portfolio module. With it switched off the file is
// still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as an
// unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('portfolio');

// The overview's old address (/portfolio.php, and /<lang>/portfolio.php)
// moved to the module root for good: a permanent redirect to /portfolio, the
// query string kept, before anything is read or printed. A POST is answered
// here as it always was. See App\Service\PortfolioUrls.
$movedTo = \App\Service\PortfolioUrls::legacyOverviewRedirectUrl(
    (string) ($_SERVER['REQUEST_URI'] ?? ''),
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);
if ($movedTo !== null) {
    header('Location: ' . $movedTo, true, \App\Service\Redirects\Redirect::STATUS_PERMANENT);
    exit;
}

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
require_once __DIR__ . '/partials/page-not-found.php';
require_once __DIR__ . '/partials/breadcrumb.php';

// Resolved BEFORE a single byte of HTML: http_response_code() below is only
// honoured while no output has been sent yet (same reason pagina.php looks
// its page up at the very top).
//
// Title, meta description, canonical and Open Graph tags all come from this
// page's own row in the CMS `pages` table (see App\Service\PageContent and
// partials/page-head.php).
//
// This is an ordinary content page that merely happens to be served from its
// own file, so the administrator may set it to Concept or delete it
// altogether (App\Service\PageContent::isProtected()). When that happens this
// URL must really stop resolving instead of answering 200 with an empty page
// — a draft, a deleted and a never-existing page look the same to a visitor.
$page = \App\Service\PageContent::forContentKey('portfolio');
if ($page === null || !\App\Service\PageContent::isPublished($page)) {
    $page = null;
    http_response_code(404);
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-url-prefix="<?= htmlspecialchars(\App\Service\Routing\LocalizedUrl::prefix(), ENT_QUOTES, 'UTF-8') ?>">
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
\App\Service\SectionRegistry::collectPageAssets('portfolio');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'portfolio';
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <?php if ($page === null): ?>
    <?php render_page_not_found(); ?>
  <?php else: ?>
    <?php \App\Service\SectionRegistry::renderPage('portfolio', \App\Service\Breadcrumbs\PageBreadcrumb::forPage($page)); ?>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
