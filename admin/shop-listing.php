<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This screen belongs to the Shop. It is guarded like every block editor, by
// the permission of the list the block is on (ContentBlockAccess), so the Shop
// itself is checked first: with the Shop off its blocks are not registered
// and there is nothing to edit (App\Module\ModuleGuard).
\App\Module\ModuleGuard::requireAdmin('shop');

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_save_bar.php';
require_once __DIR__ . '/_block_editor.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_block_head_fields.php';

use App\Repository\ShopListingRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\Csrf;
use App\Service\SectionRegistry;

/**
 * Editor for one Productgrid or Collectie-tegels block
 * (?section=<page content_key>:<section_key>): the block's optional head —
 * Bovenkop, Titel, Tekst — in one website language at a time
 * (admin/_block_head_fields.php, App\Service\Blocks\BlockHead).
 *
 * ONE SCREEN FOR BOTH. They share their row (`shop_listing_blocks`,
 * App\Service\Blocks\ShopListingBlock), and the page section that placed a row
 * says which of the two it is; a row of neither is an unknown section.
 *
 * WHAT IT LISTS IS NOT EDITED HERE: products under Producten, collections
 * under Collecties, which the screen links to. Whether the block shows is the
 * page builder's eye, like for the other blocks without a switch of their own;
 * how the section looks (background, lines, room, an effect) is its Extra
 * vormgeving in the block list, never a field here.
 */

AdminAuth::requireLogin();
\App\Service\ContentOwners\ContentBlockAccess::requireAny();

$sectionParam = (string) ($_GET['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$page = ($pageSlug === null || $pageSlug === '') ? null : \App\Service\ContentOwners\ContentBlockAccess::pageForKey($pageSlug);
$section = ($page === null || $sectionKey === null || $sectionKey === '')
    ? null
    : (new ShopListingRepository())->findBySlugAndKey($pageSlug, $sectionKey);

// Which of the two blocks placed this row: the closed list of the types that
// share the table, never a name from the request.
$type = null;
foreach (['product_grid', 'shop_collections'] as $candidate) {
    if ($section !== null && SectionRegistry::exists($candidate) && ContentBlockDrafts::belongsTo($candidate, (int) $section['id'])) {
        $type = $candidate;
        break;
    }
}

if ($type === null) {
    http_response_code(404);
    exit(admin_t('screen.onbekende_sectie'));
}

$errors = $_SESSION['admin_shop_listing_errors'] ?? [];
$old = $_SESSION['admin_shop_listing_old'] ?? null;
unset($_SESSION['admin_shop_listing_errors'], $_SESSION['admin_shop_listing_old']);

$saved = isset($_GET['saved']);
$editLanguage = admin_localized_language();
$sectionId = (int) $section['id'];
$oldInThisLanguage = is_array($old) && ($old['language_code'] ?? null) === $editLanguage;

/** The words of one field on screen: handed back in this language, else stored in it. */
$word = static function (string $field) use ($old, $oldInThisLanguage, $sectionId, $editLanguage): string {
    if ($oldInThisLanguage) {
        return (string) ($old[$field] ?? '');
    }

    return BlockLocalization::raw(ShopListingRepository::TABLE, $sectionId, $field, $editLanguage);
};
$placeholder = admin_localized_placeholder_attr($editLanguage);

$pageName = \App\Service\PageLocalization::name((int) $page['id']);
$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h(SectionRegistry::label($type)) ?> <?= admin_t('block_shop_listing.admin', ['v1' => $h($pageName)]) ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <?php if (!block_editor_draft_notice($type, $csrfToken)): ?>
    <p class="admin-text-muted"><a href="<?= $h(\App\Service\ContentOwners\ContentBlockAccess::listUrl($page)) ?>"><?= admin_t('block_shop_listing.back', ['v1' => $h($pageName)]) ?></a></p>
  <?php endif; ?>
  <h1><?= $h(SectionRegistry::label($type)) ?></h1>
  <p class="admin-text-muted"><?= admin_t($type === 'product_grid' ? 'block_shop_listing.intro_products' : 'block_shop_listing.intro_collections', ['v1' => $h($pageName)]) ?></p>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="admin-alert admin-alert--error">
      <ul class="admin-error-list">
        <?php foreach ($errors as $error): ?>
          <li><?= $h((string) $error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="admin-card">
    <form method="post" action="/api/admin/update-shop-listing.php" class="admin-product-form"<?= is_array($old) ? ' data-save-bar-unsaved' : '' ?>>
      <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
      <input type="hidden" name="section" value="<?= $h($sectionParam) ?>">
      <?= admin_localized_input($editLanguage) ?>

      <h2><?= admin_te('block_shop_listing.content') ?></h2>
      <p class="admin-text-muted"><?= admin_te('block_shop_listing.content_hint') ?></p>
      <?php admin_block_head_fields($word, $placeholder, $editLanguage); ?>

      <button type="submit"><?= admin_te('common.save') ?></button>
    </form>
  </section>
</main>
<?php save_bar(); ?>
<?php save_bar_script(); ?>
</body>
</html>
