<?php

declare(strict_types=1);

/**
 * A COMPLETE 404 document for a route that does not resolve — the same head,
 * header, body and footer pagina.php renders for an unknown slug, in one file
 * a route can require and exit on.
 *
 * pagina.php builds its 404 inline because it also has to build the found
 * case; a route that has already decided it cannot answer has nothing else to
 * render, and copying twenty lines of shell into every such route is how the
 * two versions start to differ. Used by App\Module\ModuleGuard when a route
 * belongs to a module that is switched off: a disabled module's URL then
 * answers exactly like a page that was never created, which is the property
 * this project already wanted for drafts and deleted pages.
 *
 * The caller sends the status code; this file only renders. Requires nothing
 * of its own beyond the autoloader, so it is safe to pull in from any route.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/page-not-found.php';

?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>" data-primary-lang="<?= htmlspecialchars(\App\Service\Language\SiteText::documentLanguage(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_page_not_found_head(); ?>
<?php require __DIR__ . '/page-assets.php'; ?>
</head>
<body>
<?php require __DIR__ . '/header.php'; ?>

<main id="main">
  <?php render_page_not_found(); ?>
</main>

<?php require __DIR__ . '/footer.php'; ?>

<?php require __DIR__ . '/page-scripts.php'; ?>
</body>
</html>
