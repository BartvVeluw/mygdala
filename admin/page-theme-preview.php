<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\PageThemes\PageThemeService;
use App\Service\Theme\PageAppearance;
use App\Service\Theme\PageThemeCss;

/**
 * A small sample page in one page theme, drawn the way a visitor's browser
 * draws a themed page: the document inside the preview frame of the theme
 * editor (admin/page-theme.php). See THEMING.md, "Paginathema's".
 *
 * THE REAL STYLESHEET, NOT A SKETCH. The site shell's own core.css and the
 * site theme come first, exactly as on a page (App\Service\PageAssets), and
 * the theme is printed by the same App\Service\Theme\PageThemeCss that
 * prints it on the website, onto a <main data-page-theme> like the one a
 * template prints. Ground, headings, text, a link, a button, a card and a
 * form field — the things a theme changes — and no header or footer: those
 * keep the site theme, and a visitor is never counted (the statistics run in
 * the header).
 *
 * THE VALUES ARE THE EDITOR'S, NOT YET SAVED: the query string carries the
 * five colours and the font pairing. Each is validated exactly as a save
 * validates it (PageThemeService::previewValues()) and one that would be
 * refused shows the site theme's value instead, so the preview never shows
 * what cannot be stored. Nothing is read from a page and nothing is written.
 *
 * WHY A DOCUMENT OF ITS OWN, in an iframe: the site's CSS and the CMS's CSS
 * style the same elements, so one on the other's page breaks both — the
 * reason admin/block-preview.php is one. The frame is sandboxed with nothing
 * allowed, and this document refuses scripts and form submissions itself.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('page_themes.manage');

$values = PageThemeService::previewValues($_GET);

session_write_close();

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'");

PageThemeCss::declare(PageAppearance::fromTheme('preview', $values, $values['font_pairing']));
PageAssets::requireStyle('assets/css/page-theme-preview.css');

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= admin_te('pagethemes.preview_frame') ?></title>
<?php PageAssets::renderStyles(); ?>
</head>
<body>
<main id="main" class="page-theme-preview"<?= PageThemeCss::mainAttribute() ?>>
  <section>
    <div class="container container--narrow">
      <h1><?= admin_te('pagethemes.sample_h1') ?></h1>
      <div class="rich-content">
        <p><?= admin_te('pagethemes.sample_text') ?> <a href="#voorbeeld"><?= admin_te('pagethemes.sample_link') ?></a>.</p>
      </div>
      <h2><?= admin_te('pagethemes.sample_h2') ?></h2>
      <p><a class="btn" href="#voorbeeld"><?= admin_te('pagethemes.sample_button') ?></a></p>
      <div class="feature-card">
        <h3 class="feature-card__title"><?= admin_te('pagethemes.sample_card_title') ?></h3>
        <p><?= admin_te('pagethemes.sample_card_text') ?></p>
      </div>
      <div class="form-field">
        <label for="voorbeeld-veld"><?= admin_te('pagethemes.sample_field') ?></label>
        <input type="text" id="voorbeeld-veld" placeholder="<?= admin_te('pagethemes.sample_placeholder') ?>">
      </div>
    </div>
  </section>
</main>
</body>
</html>
