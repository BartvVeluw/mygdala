<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/../partials/form.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Forms\FormCatalog;
use App\Service\Forms\FormRenderState;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\Routing\RequestLanguage;

/**
 * ONE stored form, drawn the way a visitor's browser draws it: the document
 * inside the Voorbeeld card of the form editor (admin/form.php). See
 * FORMS.md, "Voorbeeld in de formuliereditor".
 *
 * THE REAL RENDERER, NOT A COPY. The form comes from FormCatalog::find(),
 * exactly as the Formulier block gets it, and is printed by render_form() in
 * partials/form.php — the one function every public form goes through. The
 * same labels, types, required marks, widths and twelve-column grid, because
 * it is the same code; there is no markup for a field in this file and no
 * second renderer in JavaScript. Around it is the card the Formulier block
 * puts a form in, with the site's own stylesheets and theme and the block's
 * own stylesheet (App\Service\Blocks\FormBlock::styles()).
 *
 * WHAT IS STORED, IN THE LANGUAGE BEING EDITED. The preview shows the saved
 * definition, not what is being typed on the editor at that moment: saving
 * reloads the editor, and the frame with it. Its words are those of the
 * website language the editor has chosen in the CMS shell
 * (App\Service\Language\ContentEditingLanguage, the language every
 * localized editor shows), with the fallback a visitor gets.
 *
 * WHY A DOCUMENT OF ITS OWN, in an iframe, as admin/block-preview.php is: the
 * site's CSS and the CMS's CSS style the same elements; the site's media
 * query answers to the width of the frame, so the editor can see a phone's
 * stacked layout; and the form's ids live in a document where no admin
 * control can share them, so the public ids stay exactly as they are.
 *
 * WHAT IT NEVER DOES. It writes nothing and nothing in it can be sent: no
 * script runs in it (the Content-Security-Policy below allows none, the frame
 * is sandboxed without allow-scripts, and assets/js/blocks/form.js is never
 * asked for), the policy refuses every form submission, and the frame has no
 * allow-forms. No header or footer, so no visitor is counted and no cookie
 * banner covers the form. It shows a switched-off form too — the editor is
 * told so beside the frame — because that is exactly when someone wants to
 * see it before switching it on. Same guard as the form editor, and no public
 * address: nothing outside /admin/ reads a preview parameter.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('forms.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

// The language the editor is editing (the CMS shell's switch, always an
// active website language), as the request language, so the definition, the
// renderer's own sentences and the document all use it.
RequestLanguage::set(ContentEditingLanguage::current(), false);

$form = ($id === false || $id === null || $id < 1) ? null : FormCatalog::find($id);

if ($form === null) {
    http_response_code(404);
    exit(admin_t('screen.formulier_gevonden'));
}

// Everything the account may do has been decided; the preview needs nothing
// more from the admin session, and never holds its lock while it renders.
session_write_close();

// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: script-src 'none'; form-action 'none'; frame-ancestors 'self'; base-uri 'none'");

// The Formulier block's stylesheets, never its script.
$block = BlockDefinitions::get('form');
foreach ($block === null ? [] : $block->styles() as $style) {
    PageAssets::requireStyle($style);
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= admin_te('forms.preview.document_title', ['form' => $form->name]) ?></title>
<?php PageAssets::renderStyles(); ?>
</head>
<body>
<main id="main">
  <?php if ($form->hasFields()): ?>
    <?php /* The card of partials/section-form.php, without data-reveal: that
             waits for a script, and none runs here. */ ?>
    <section class="form-block">
      <div class="container">
        <div class="form-block__card contact-card">
          <?php render_form($form, FormRenderState::fresh('form-preview-' . $form->id)); ?>
        </div>
      </div>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
