<?php

require_once __DIR__ . '/vendor/autoload.php';

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
require_once __DIR__ . '/partials/page-not-found.php';

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
$page = \App\Service\PageContent::forContentKey('diensten');
if ($page === null || !\App\Service\PageContent::isPublished($page)) {
    $page = null;
    http_response_code(404);
}

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
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
\App\Service\SectionRegistry::collectPageAssets('diensten');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'diensten';
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <?php if ($page === null): ?>
    <?php render_page_not_found(); ?>
  <?php else: ?>
    <?php \App\Service\SectionRegistry::renderPage('diensten'); ?>
  <?php endif; ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
