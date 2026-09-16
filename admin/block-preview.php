<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockSamples;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\SectionRegistry;

/**
 * ONE content block with sample content, drawn the way a visitor's browser
 * draws it: the document inside the preview dialog of the Contentblokken
 * library (admin/content-blocks.php). See PAGE-EDITOR.md.
 *
 * THE REAL BLOCK, NOT A PICTURE OF IT. The block's own definition hands its
 * own partial sample content (BlockDefinition::renderSample(), words from
 * App\Service\Blocks\BlockSamples), and the page asks for the block's own
 * stylesheets and scripts the way a public page does
 * (SectionRegistry::collectBlockAssets()), after the site shell and with
 * this installation's theme. There is no markup for any block in this file.
 *
 * WHY A DOCUMENT OF ITS OWN, shown in an iframe. The site's CSS and the
 * CMS's CSS style the same elements, so one on the other's page breaks both;
 * the site's media queries answer to the width of the frame, which is what
 * lets the dialog show a tablet or a phone; and a block's script reads the
 * whole document, which is then this one.
 *
 * WHAT IT NEVER DOES. It reads no page, no block row and no site content and
 * writes nothing: the sample is in memory. No header and no footer, so no
 * visitor is counted (App\Service\Analytics\PageViewTracker runs in the
 * header) and no cookie banner covers the block. Nothing in it can be sent:
 * the Content-Security-Policy below refuses every form submission, the
 * library's iframe has no allow-forms, assets/js/block-preview.js stops a
 * submit before a block's own script sees it, and the sample form has a key
 * no stored form can have (BlockSamples::FORM_KEY). A link goes nowhere
 * either. Same guard as the page builder, and no public address: nothing
 * outside /admin/ reads a preview parameter.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

// The type only ever hits or misses a key of the registry, which holds the
// blocks of enabled modules alone. A switched-off module's block, a block
// without a sample and anything else are the same answer.
$typeParam = filter_input(INPUT_GET, 'type');
$definition = is_string($typeParam) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $typeParam) === 1
    ? BlockDefinitions::get($typeParam)
    : null;
$sample = $definition?->sampleContent(new BlockSamples());

header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if ($definition === null || $sample === null) {
    http_response_code(404);
    exit(admin_t('blocks.preview_not_found'));
}

// Everything the account may do has been decided; the preview needs nothing
// more from the admin session, and never holds its lock while it renders.
session_write_close();

// Enforced by the browser, whatever the markup below contains.
header("Content-Security-Policy: form-action 'none'; frame-ancestors 'self'");

SectionRegistry::collectBlockAssets($definition);
PageAssets::requireStyle('assets/css/block-preview.css');
PageAssets::requireScript('assets/js/block-preview.js');

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>" data-primary-lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $h(admin_t('blocks.preview_document_title', ['block' => $definition->label()])) ?></title>
<?php PageAssets::renderStyles(); ?>
</head>
<body>
<main id="main" class="block-preview">
  <?php $definition->renderSample($sample, $definition->type() . '-preview'); ?>
</main>
<?php require dirname(__DIR__) . '/partials/page-scripts.php'; ?>
</body>
</html>
