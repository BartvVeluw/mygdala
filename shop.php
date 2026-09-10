<?php
require_once __DIR__ . '/vendor/autoload.php';

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
// This route belongs to a module. With that module switched off the file
// is still on disk and still reachable, so the URL must stop answering:
// App\Module\ModuleGuard renders the site's own 404 and exits, exactly as
// an unknown slug does. Nothing below runs.
\App\Module\ModuleGuard::requirePublicRoute('shop');

?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// Title, meta description, canonical and Open Graph tags all come from
// this page's own row in the CMS `pages` table (see
// App\Service\PageContent and partials/page-head.php) — they used to be
// hardcoded here, which is why this page had no editable SEO title or
// meta description before.
$page = \App\Service\PageContent::forContentKey('shop');
require __DIR__ . '/partials/page-head.php';
?>
<?php
// Frontend assets for this page: App\Service\PageAssets always puts Core
// and the site shell first, and this page adds whatever it needs on top.
\App\Service\SectionRegistry::collectPageAssets('shop');
require __DIR__ . '/partials/page-assets.php';
?>
</head>
<body>
<?php
$activeNav = 'shop';
require __DIR__ . '/partials/header.php';
?>


<main id="main">

  <?php \App\Service\SectionRegistry::renderPage('shop'); ?>

</main>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
