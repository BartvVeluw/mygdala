<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Theme\ColorPaletteService;
use App\Service\Theme\PageAppearance;
use App\Service\Theme\PageThemeCss;
use App\Service\Theme\ThemeSettings;

/**
 * A small sample of the website in one colour palette: the document inside
 * the preview frame of the palette editor (admin/color-palette.php). See
 * THEMING.md, "Kleurenpaletten".
 *
 * THE REAL STYLESHEET, THE REAL TOKENS. core.css, the site's font pairing
 * and button shape come first, exactly as on a page (App\Service\PageAssets);
 * the palette is then printed as the complete token set through the same
 * path a page theme takes (PageAppearance + PageThemeCss onto a
 * <main data-page-theme>): the five colours, every tint of
 * ThemePalette::derive(), and the alpha derivations core.css recomputes on
 * that element. One token model for the website, a page theme and this
 * preview; nothing here styles a colour of its own.
 *
 * LIVE, WITHOUT A REQUEST. The query string carries the palette's colours
 * for the first paint, each validated like a save
 * (ColorPaletteService::previewColors()). After that the editor's script
 * sets the same properties straight on this document's <main> for every
 * change (admin/assets/color-palette-admin.js), computed from the same
 * recipe (ThemePalette::recipe()): no reload and no server round trip per
 * colour. That is why the frame is sandboxed with `allow-same-origin` and
 * nothing else — the parent may reach into this document, but this document
 * runs no script at all: its Content-Security-Policy refuses every script
 * and every form, and it contains neither. Nothing is read from a palette
 * row and nothing is written.
 *
 * WHY A DOCUMENT OF ITS OWN, in an iframe: the site's CSS and the CMS's CSS
 * style the same elements, so one on the other's page breaks both — the
 * reason admin/page-theme-preview.php and admin/block-preview.php are one.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$colors = ColorPaletteService::previewColors($_GET);

session_write_close();

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'");

PageThemeCss::declare(PageAppearance::fromTheme('palette-preview', $colors, ThemeSettings::get('font_pairing')));
PageAssets::requireStyle('assets/css/color-palette-preview.css');

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= admin_te('palettes.preview_frame') ?></title>
<?php PageAssets::renderStyles(); ?>
</head>
<body>
<main id="main" class="palette-preview"<?= PageThemeCss::mainAttribute() ?>>
  <section>
    <div class="container container--narrow">
      <h1><?= admin_te('palettes.sample_h1') ?></h1>
      <div class="rich-content">
        <p><?= admin_te('palettes.sample_text') ?> <a href="#voorbeeld"><?= admin_te('palettes.sample_link') ?></a>.</p>
      </div>
      <h2><?= admin_te('palettes.sample_h2') ?></h2>
      <p class="palette-preview__buttons">
        <a class="btn" href="#voorbeeld"><?= admin_te('palettes.sample_button') ?></a>
        <a class="btn btn--ghost" href="#voorbeeld"><?= admin_te('palettes.sample_button_secondary') ?></a>
      </p>
      <div class="feature-card">
        <h3 class="feature-card__title"><?= admin_te('palettes.sample_card_title') ?></h3>
        <p><?= admin_te('palettes.sample_card_text') ?></p>
      </div>
      <div class="form-field">
        <label for="voorbeeld-veld"><?= admin_te('palettes.sample_field') ?></label>
        <input type="text" id="voorbeeld-veld" placeholder="<?= admin_te('palettes.sample_placeholder') ?>">
      </div>
    </div>
  </section>
  <div class="palette-preview__band">
    <div class="container container--narrow">
      <p><?= admin_te('palettes.sample_band') ?></p>
    </div>
  </div>
</main>
</body>
</html>
