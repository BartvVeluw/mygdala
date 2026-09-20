<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';

// A failed form submission is redirected back to this page, and the
// answers the visitor typed are waiting in the public session. Reading
// them needs session_start(), which refuses once output has begun — so
// it happens HERE, before the first byte of HTML, exactly like
// SectionRegistry::collectPageAssets() reads the block list before the
// <head>. On an ordinary page view it does nothing at all.
\App\Service\Forms\PublicFormSession::prime();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
<?php
// Title, meta description, canonical and Open Graph tags all come from
// this page's own row in the CMS `pages` table (see
// App\Service\PageContent and partials/page-head.php) — they used to be
// hardcoded here, which is why this page had no editable SEO title or
// meta description before.
$page = \App\Service\PageContent::forContentKey('index');
require __DIR__ . '/partials/page-head.php';
?>
    <?php
    // Frontend assets for this page: App\Service\PageAssets always puts Core
    // and the site shell first, and this page adds whatever it needs on top.
    \App\Service\SectionRegistry::collectPageAssets('index');
    require __DIR__ . '/partials/page-assets.php';
    ?>
  </head>
  <body>
    <?php
$activeNav = 'home';
require __DIR__ . '/partials/header.php';
?>


    <main id="main">
      <!-- The page renders exactly the blocks the CMS has attached to it,
           in the CMS's own order — one page, one ordered list. See
           App\Service\SectionRegistry's renderPage; the services carousel
           and portfolio teaser that used to be hardcoded here are now
           positioned blocks too (partials/section-services-carousel.php,
           partials/section-portfolio-teaser.php). -->
      <?php \App\Service\SectionRegistry::renderPage('index'); ?>
    </main>

    <?php require __DIR__ . '/partials/footer.php'; ?>

    <?php require __DIR__ . '/partials/page-scripts.php'; ?>
  </body>
</html>
