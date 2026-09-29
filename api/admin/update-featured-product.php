<?php

/**
 * POST /api/admin/update-featured-product.php
 *
 * Saves one Uitgelicht product block (admin/featured-product.php?section=
 * <page>:<key>). The same guard order and PRG/session-flash pattern as
 * api/admin/update-media-banner.php, and the same "a page-builder-attached
 * section is valid only when the page AND its content row already exist"
 * gate — an arbitrary page_slug:section_key pair from the request is never
 * trusted beyond that.
 *
 * THE SHOP FIRST. The block belongs to the Shop, but its editor is guarded by
 * the permission of its list (ContentBlockAccess; pages.manage on a page, a
 * Core permission that is still held while the Shop is off);
 * so App\Module\ModuleGuard refuses before anything else when the Shop is
 * off, and nothing is read or written.
 *
 * THE PRODUCT is an id, checked to name a product of the catalogue
 * (ProductRepository::findByIdForAdmin()). Empty is allowed: a block may be
 * saved without a product and set up later; it shows nothing until then. An
 * inactive product may be chosen too — the editor says the block cannot show
 * it — because making it active again is the owner's business, not this
 * block's. Nothing of the product is copied: it is read live on the page.
 *
 * THE CHOICES are words from closed lists (App\Service\FeaturedProductContent):
 * an unknown word is refused at its field, never stored, and a form without
 * the field keeps what is stored. A switch is "1" or absent; anything else is
 * refused.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the intro and the button's label
 * are the words of the language in `language_code`, an active language of the
 * website registry; both are optional. Which fields exist and how long they
 * may be comes from FeaturedProductBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written.
 * The settings are the same in every language. Settings and words are one
 * transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
\App\Module\ModuleGuard::requireApi('shop');

use App\Database;
use App\Repository\FeaturedProductRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FeaturedProductContent;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$slug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new FeaturedProductRepository();

if ($slug === null || $slug === '' || $sectionKey === null || $sectionKey === ''
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($slug) === null
    || ($section = $repository->findBySlugAndKey($slug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$sectionId = (int) $section['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields(FeaturedProductContent::TABLE)) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$fieldErrors = [];
$stored = FeaturedProductContent::settings($section);

// ----------------------------------------------------------------- product
// Empty is no product yet; anything else must name a product of the catalogue.
$productPosted = trim((string) ($_POST['product_id'] ?? ''));
$productId = null;
if ($productPosted !== '') {
    if (ctype_digit($productPosted) && (int) $productPosted > 0
        && (new ProductRepository())->findByIdForAdmin((int) $productPosted) !== null
    ) {
        $productId = (int) $productPosted;
    } else {
        $fieldErrors['product_id'] = AdminTranslator::trans('block_featured_product.error_product');
    }
}

// ------------------------------------------------------------------ choices
// Each a word from its closed list. A form without the field keeps what is
// stored; a word that is not on the list is refused at its field.
$lists = [
    'image_mode' => FeaturedProductContent::IMAGE_MODES,
    'image_position' => FeaturedProductContent::IMAGE_POSITIONS,
    'image_size' => FeaturedProductContent::IMAGE_SIZES,
    'content_align' => FeaturedProductContent::ALIGNMENTS,
    'ordering' => FeaturedProductContent::ORDERINGS,
];
$settings = [];
foreach ($lists as $name => $list) {
    $value = array_key_exists($name, $_POST) ? (string) $_POST[$name] : $stored[$name];
    if (!in_array($value, $list, true)) {
        $fieldErrors[$name] = AdminTranslator::trans('block_featured_product.error_choice');
        $value = $stored[$name];
    }
    $settings[$name] = $value;
}

// ----------------------------------------------------------------- switches
// A checkbox sends "1" or nothing; anything else is refused rather than read
// as "on".
foreach (array_keys(FeaturedProductContent::SWITCHES) as $name) {
    $posted = $_POST[$name] ?? null;
    if ($posted !== null && $posted !== '1') {
        $fieldErrors[$name] = AdminTranslator::trans('block_featured_product.error_switch');
        $settings[$name] = $stored[$name];
        continue;
    }
    $settings[$name] = $posted === '1';
}
$settings['product_id'] = $productId;

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems(FeaturedProductContent::TABLE, $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

array_push($errors, ...array_values($fieldErrors));

// What a refused save hands back: everything as posted, so the editor
// reopens on what was chosen and typed.
$old = ['language_code' => $languageCode] + $words + $settings;
$old['product_id'] = $productPosted;

$redirect = '/admin/featured-product.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_featured_product_errors'] = $errors;
    $_SESSION['admin_featured_product_field_errors'] = $fieldErrors;
    $_SESSION['admin_featured_product_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The block's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->update($sectionId, $settings);
    BlockLocalization::save(FeaturedProductContent::TABLE, $sectionId, $languageCode, $words);

    $db->commit();
    FeaturedProductContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-featured-product.php] ' . $e->getMessage());

    $_SESSION['admin_featured_product_errors'] = [AdminTranslator::trans('block_featured_product.error_save')];
    $_SESSION['admin_featured_product_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
