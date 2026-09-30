<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Theme\ButtonStyleCss;
use App\Service\Theme\ButtonStyles;

/**
 * The sample buttons inside the preview frame of the button style editor
 * (admin/button-style.php): one style in every state a visitor can meet —
 * normal, under the mouse, with keyboard focus, disabled, with a longer
 * text — and on a light and a dark ground. See THEMING.md, "Knopstijlen".
 *
 * THE REAL STYLESHEET, THE REAL TOKENS. core.css, the website's theme, its
 * fonts and its button block come first, exactly as on a page
 * (App\Service\PageAssets), so a sample is a real .btn in the active palette.
 * The style's --btn-* properties are then set on the samples: printed here
 * for the first paint (the stored style for ?id=, else what a new style
 * starts with), and after that by the editor's script straight on each
 * sample for every change (admin/assets/button-style-admin.js), composed from
 * the same recipe (ButtonStyleCss::recipe()). No reload and no request per
 * change — which is why the frame is sandboxed with `allow-same-origin` and
 * nothing else: the parent may reach into this document, but this document
 * runs no script at all (its Content-Security-Policy refuses every script
 * and every form). Nothing is written.
 *
 * "Under the mouse" and "with keyboard focus" cannot be forced on a real
 * element, so assets/css/button-style-preview.css draws those two samples
 * from the same properties core.css's :hover and :focus-visible use.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('settings.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$style = is_int($id) ? ButtonStyles::find($id) : null;
$style ??= ButtonStyles::startingValues();

session_write_close();

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'");

PageAssets::requireStyle('assets/css/button-style-preview.css');

$declarations = '';
foreach (ButtonStyleCss::declarations($style) as $property => $cssValue) {
    $declarations .= '  ' . $property . ': ' . $cssValue . ";\n";
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** One sample: the button, and what it shows under it. */
$sample = static function (string $caption, string $state = '', string $label = '') use ($h): string {
    $label = $label !== '' ? $label : admin_t('buttons.sample_label');
    $button = $state === 'disabled'
        ? '<button type="button" class="btn" data-button-sample disabled>' . $h($label) . '</button>'
        : '<a class="btn' . ($state !== '' ? ' is-preview-' . $h($state) : '') . '" href="#voorbeeld" data-button-sample tabindex="-1">' . $h($label) . '</a>';

    return '<figure class="button-preview__sample">' . $button . '<figcaption>' . $h($caption) . '</figcaption></figure>';
};
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= admin_te('buttons.preview_frame') ?></title>
<?php PageAssets::renderStyles(); ?>
<style id="button-preview-style">
.button-preview [data-button-sample]{
<?= $declarations ?>}
</style>
</head>
<body>
<main id="main" class="button-preview">
  <section class="button-preview__stage">
    <div class="button-preview__grid">
      <?= $sample(admin_t('buttons.sample_normal')) ?>
      <?= $sample(admin_t('buttons.sample_hover'), 'hover') ?>
      <?= $sample(admin_t('buttons.sample_focus'), 'focus') ?>
      <?= $sample(admin_t('buttons.sample_disabled'), 'disabled') ?>
    </div>
    <div class="button-preview__grid button-preview__grid--wide">
      <?= $sample(admin_t('buttons.sample_long_caption'), '', admin_t('buttons.sample_long')) ?>
    </div>
  </section>
  <section class="button-preview__stage button-preview__stage--light">
    <p class="button-preview__heading"><?= admin_te('buttons.sample_light') ?></p>
    <div class="button-preview__grid">
      <?= $sample(admin_t('buttons.sample_normal')) ?>
      <?= $sample(admin_t('buttons.sample_hover'), 'hover') ?>
    </div>
  </section>
  <section class="button-preview__stage button-preview__stage--dark">
    <p class="button-preview__heading"><?= admin_te('buttons.sample_dark') ?></p>
    <div class="button-preview__grid">
      <?= $sample(admin_t('buttons.sample_normal')) ?>
      <?= $sample(admin_t('buttons.sample_hover'), 'hover') ?>
    </div>
  </section>
</main>
</body>
</html>
