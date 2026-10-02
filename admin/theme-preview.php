<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\PageContent;
use App\Service\Routing\LocalizedUrl;
use App\Service\SectionRegistry;
use App\Service\Theme\PageThemeCss;
use App\Service\Theme\ThemeRegistry;

/**
 * The website's start page in a Global Theme that is not necessarily the
 * stored one: the document inside the preview frame on the tab Thema of
 * admin/theme.php. See THEMING.md, "Preview".
 *
 * THE REAL SITE, ONE STYLESHEET SWAPPED. The start page renders the way
 * index.php renders it — its own blocks and their assets, its page theme if
 * it has one, the site header and footer — with the site's palette, fonts
 * and button styles, because the owner has to judge the theme WITH their own
 * design, not instead of it. The one difference is the Global Theme's slot
 * in the head: PageAssets::renderStyles() is handed the requested definition
 * for this one call. Nothing is switched on a class, in the session, in a
 * cookie or in storage, so no other request — a visitor's, the next admin
 * screen's, a test's — can ever see it, and no body class or attribute names
 * the theme: the stylesheet is the whole difference, as it is on the site.
 *
 * THE KEY. `?theme=` is only ever looked up in the closed list
 * (ThemeRegistry::find()); an unknown key is a 404, never a fallback that
 * would show legacy under another name, and never a path. `legacy` is
 * previewable like any other key, so Klassiek can be compared while the
 * site is on another theme. Nothing outside /admin/ reads this parameter:
 * the public site has no theme override of any kind.
 *
 * WHAT IT NEVER DOES. It writes nothing: no theme_settings row, no session
 * value (the admin session is closed before anything renders), no page or
 * block. Visitor statistics skip /admin paths
 * (App\Service\Analytics\PageViewTracker). Nothing in it can be sent or take
 * the editor anywhere: the Content-Security-Policy refuses every form
 * submission, the frame's sandbox (admin/theme.php) has no allow-forms, no
 * popups, no top navigation and no allow-same-origin, and
 * assets/js/theme-preview.js stops link clicks and submits before a block's
 * own script sees them. The cookie notice's script is left out (its
 * storage has no place in an opaque frame; the notice itself is hidden
 * markup until that script shows it), as are the head's SEO tags.
 *
 * Guarded like the screen that frames it: signed in, settings.manage.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$themeParam = filter_input(INPUT_GET, 'theme');
$theme = is_string($themeParam) ? ThemeRegistry::find($themeParam) : null;

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if ($theme === null) {
    http_response_code(404);
    exit(admin_t('themes.preview_not_found'));
}

// Everything the account may do has been decided; the preview needs nothing
// more from the admin session, and never holds its lock while it renders.
session_write_close();

// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: form-action 'none'; frame-ancestors 'self'; base-uri 'none'");

$page = PageContent::forContentKey('index');
PageThemeCss::declareForPage($page);
SectionRegistry::collectPageAssets('index');
PageAssets::requireStyle('assets/css/theme-preview.css');
PageAssets::requireScript('assets/js/theme-preview.js');

$themeLabel = admin_registry_label('globaltheme.' . $theme->key . '.label', $theme->label);

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>" data-url-prefix="<?= $h(LocalizedUrl::prefix()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= admin_te('themes.preview_document_title', ['name' => $themeLabel]) ?></title>
<?php PageAssets::renderStyles($theme); ?>
</head>
<body>
<?php
$activeNav = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<main id="main"<?= PageThemeCss::mainAttribute() ?>>
  <?php SectionRegistry::renderPage('index'); ?>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>

<?php /* After the page, so it comes last in the reading order. Pinned on
         screen by assets/css/theme-preview.css. */ ?>
<aside class="theme-preview-bar" aria-label="<?= admin_te('themes.preview_bar_label') ?>">
  <strong class="theme-preview-bar__badge"><?= admin_te('themes.preview_badge') ?></strong>
  <span><?= admin_te('themes.preview_bar', ['name' => $themeLabel]) ?></span>
</aside>

<?php require dirname(__DIR__) . '/partials/page-scripts.php'; ?>
</body>
</html>
