<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_localized_fields.php';
require_once __DIR__ . '/_admin_editor.php';
require_once __DIR__ . '/_product_variants.php';

use App\Repository\ProductSpecificationRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ShopLocalization;
use App\Service\SpecificationLibraryEditor;

/**
 * Shop → Specificaties (Shop Product & Ordering 2.0, MODULES.md
 * "Specificaties"): the library of reusable product properties — Dikte,
 * Hoogte, Breedte, Materiaal, Gewicht — each with its name per website
 * language and an optional short unit. The product editor picks from this
 * list and fills in a value per product; the product page shows them as a
 * list. Presentation only: nothing here filters, searches or compares.
 *
 * ONE LIST, ONE SAVE, no reload (admin/_admin_editor.php): a property is a
 * row, added, moved and removed on screen (admin/assets/row-list.js), and
 * Opslaan stores them all (api/admin/update-product-specifications.php,
 * App\Service\SpecificationLibraryEditor). The names are in the language
 * the shell's switch chose; every other language stays. A row says how many
 * products use it, because removing it takes its value off those products.
 *
 * products.manage, like the product editor that uses the list. It is a Shop
 * permission, held by nobody while the Shop is off.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('products.manage');

$language = admin_localized_language();

try {
    $specifications = (new ProductSpecificationRepository())->all();
    ShopLocalization::preloadSpecifications(array_column($specifications, 'id'));
} catch (\Throwable $e) {
    error_log('[admin/product-specifications.php] ' . $e->getMessage());
    http_response_code(500);
    exit(admin_t('shop.specifications.load_failed'));
}

$errors = $_SESSION['admin_specifications_errors'] ?? [];
unset($_SESSION['admin_specifications_errors']);
$saved = isset($_GET['saved']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/**
 * One property of the library.
 *
 * @param array{name: string, unit: string, usage: int, placeholder: string} $row
 */
function specification_library_row(string $key, array $row): void
{
    $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $id = 'specification-' . $key;
    $isNew = !ctype_digit($key);
    ?>
    <div class="admin-row-card admin-specification-row" data-row-list-row>
      <input type="hidden" name="<?= $h(SpecificationLibraryEditor::field($key, 'present')) ?>" value="1">
      <div class="admin-specification-row__fields">
        <div class="admin-field admin-specification-row__name">
          <label for="<?= $h($id) ?>-name"><?= admin_te('shop.specifications.name') ?><?= $isNew ? ' <span class="admin-badge admin-badge--info">' . admin_te('editor_rows.nieuw') . '</span>' : '' ?></label>
          <input type="text" id="<?= $h($id) ?>-name" name="<?= $h(SpecificationLibraryEditor::field($key, 'name')) ?>" maxlength="<?= SpecificationLibraryEditor::NAME_MAX_LENGTH ?>" value="<?= $h($row['name']) ?>" placeholder="<?= $row['placeholder'] !== '' ? $h($row['placeholder']) : admin_te('shop.specifications.name_placeholder') ?>">
        </div>
        <div class="admin-field admin-specification-row__unit">
          <label for="<?= $h($id) ?>-unit"><?= admin_te('shop.specifications.unit') ?></label>
          <input type="text" id="<?= $h($id) ?>-unit" name="<?= $h(SpecificationLibraryEditor::field($key, 'unit')) ?>" maxlength="<?= SpecificationLibraryEditor::UNIT_MAX_LENGTH ?>" value="<?= $h($row['unit']) ?>" placeholder="<?= admin_te('shop.specifications.unit_placeholder') ?>">
        </div>
        <?php product_variants_row_tools(admin_t('shop.specifications.remove_property'), 'data-specification-remove', true); ?>
      </div>
      <?php if ($row['usage'] > 0): ?>
        <p class="admin-row-card__note"><?= admin_te('shop.specifications.usage', ['count' => (string) $row['usage']]) ?></p>
      <?php endif; ?>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="<?= $h(\App\Service\Language\AdminLocale::current()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('shop.specifications.title_admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/row-list.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('shop.specifications.title') ?></h1>
  <?= admin_info_panel(admin_t('help.shop.specifications')) ?>

  <?php if ($saved): ?>
    <p class="admin-alert admin-alert--success"><?= admin_te('common.saved') ?></p>
  <?php endif; ?>

  <?= admin_editor_summary($errors) ?>

  <form method="post" action="/api/admin/update-product-specifications.php" id="specifications-form" data-admin-editor<?= $errors !== [] ? ' data-admin-editor-unsaved' : '' ?>>
    <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
    <?= admin_localized_input($language) ?>

    <section class="admin-card" data-admin-editor-section="specifications">
      <?php admin_localized_bar($language); ?>
      <div class="admin-alert admin-alert--error" data-admin-editor-errors="specifications" hidden></div>
      <div data-admin-editor-region="specifications">
        <input type="hidden" name="specifications_present" value="1">
        <div class="admin-specification-list" data-row-list="specifications">
          <?php foreach ($specifications as $specification): ?>
            <?php specification_library_row((string) $specification['id'], [
                'name' => ShopLocalization::rawSpecification($specification['id'], $language),
                'placeholder' => ShopLocalization::specificationName($specification['id'], $language),
                'unit' => $specification['unit'],
                'usage' => $specification['usage'],
            ]); ?>
          <?php endforeach; ?>
        </div>
        <p class="admin-text-muted" data-specifications-empty<?= $specifications !== [] ? ' hidden' : '' ?>><?= admin_te('shop.specifications.library_none') ?></p>
        <template data-row-list-template="specifications"><?php specification_library_row('__KEY__', ['name' => '', 'placeholder' => '', 'unit' => '', 'usage' => 0]); ?></template>
        <p class="admin-visually-hidden" role="status" aria-live="polite" data-row-list-status="specifications" data-row-list-moved="<?= admin_te('editor_rows.verplaatst') ?>"></p>
        <div class="admin-option-rows__tools">
          <button type="button" class="admin-btn-secondary" data-row-list-add="specifications" hidden>+ <?= admin_te('shop.specifications.add_property') ?></button>
        </div>
      </div>

      <?php /* For a browser without the editor script only. */ ?>
      <button type="submit" data-admin-editor-fallback><?= admin_te('common.save') ?></button>
    </section>
  </form>
</main>
<?php admin_editor_bar(); ?>
<?= admin_editor_leave_dialog() ?>
<?php admin_editor_script(); ?>
</body>
</html>
