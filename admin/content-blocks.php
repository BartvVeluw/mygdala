<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_block_library.php';

use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;

/**
 * The Contentblokken library: every content block this site currently has,
 * what it puts on a page, what it is good for, and what it looks like.
 *
 * IT IS DOCUMENTATION, NOT AN EDITOR. Nothing on this screen creates,
 * changes or deletes anything — there is no form on it at all. Blocks are
 * added where they are used, from the picker inside a page
 * (admin/_block_picker.php); this is the place to find out what exists
 * before going there, and the answer to "wat was dat blok ook alweer?".
 *
 * IT READS THE SAME METADATA THE PICKER DOES. Label, description, category,
 * icon, schematic drawing and example uses all come off each block's own
 * App\Service\Blocks\BlockDefinition, and the cards are printed by
 * admin/_block_library.php, which asks the definition for every word. A new
 * block therefore appears on this page, correctly
 * described and with its preview, without this file being touched.
 *
 * "VOORBEELD BEKIJKEN" opens the block with sample content in a dialog: the
 * real block in a frame of its own (admin/block-preview.php), at desktop,
 * tablet or phone width. See PAGE-EDITOR.md.
 *
 * WHAT IS NOT LISTED. Exactly what the CMS does not currently have. A block
 * belonging to a switched-off module is not registered while the module is
 * off (App\Service\Blocks\BlockDefinitions), so with the Shop off the Shop's
 * blocks are simply absent — not greyed out, not "unavailable", absent, the
 * same as everywhere else in this admin.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$definitions = BlockDefinitions::all();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('blocks.contentblokken_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <header class="admin-page-head">
    <div>
      <h1 class="admin-page-head__title"><?= admin_te('blocks.contentblokken') ?></h1>
      <p class="admin-page-head__desc"><?= admin_t('blocks.catalogue_intro', ['count' => count($definitions)]) ?></p>
    </div>
    <a href="/admin/pages.php" class="admin-btn-secondary"><?= admin_te('blocks.pagina_s') ?> &#8594;</a>
  </header>

  <?php block_library($definitions); ?>
</main>
<?php block_library_preview_dialog(); ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/block-library.js') ?>" defer></script>
</body>
</html>
