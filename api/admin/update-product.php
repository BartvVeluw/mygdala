<?php

/**
 * POST /api/admin/update-product.php
 *
 * THE ONE SAVE of the product editor (admin/product-form.php): the product's
 * fields, its words in the one language being edited, its SEO fields, its
 * collections, its pool of pictures, and the whole Varianten section — the
 * options with their values and the variants, each variant with its pictures
 * from the pool and its own description (App\Service\ProductVariantEditor,
 * App\Service\ProductGallery, validateVariantDescriptions()). Adding an
 * option, removing a variant or moving a value is typing on the screen; this
 * is where it is stored, and there is no other endpoint for any of it.
 *
 * EVERYTHING IS CHECKED, THEN EVERYTHING IS WRITTEN IN ONE TRANSACTION. A
 * refused save writes nothing at all, and a failure halfway rolls all of it
 * back: never half a product. A section the form did not carry is left alone
 * (`gallery_submitted`, `options_present`, `variants_present`).
 *
 * TWO ANSWERS, ONE SET OF RULES. The editor script asks for JSON and gets
 * App\Service\AdminEditorResponse: 200 when stored, 422 with every message
 * keyed by the field or section it is about, 500 when the database failed;
 * the screen stays as it is and nothing reloads. A form posted without the
 * script gets the PRG redirect and session flash it always got.
 *
 * STOCK (Shop Product & Ordering 2.0): "Voorraad bijhouden" and the
 * product's own stock (App\Service\Inventory\InventoryEditor), a variant's
 * stock in its row (App\Service\ProductVariantEditor). A value the admin did
 * not change is not written; a changed one only over the value the screen
 * showed, so a sale in the meantime is refused as a conflict rather than
 * undone. Once the transaction is committed, everyone waiting for a unit of
 * this product that can be ordered now gets their back-in-stock mail
 * (App\Service\Inventory\StockNotifications).
 *
 * The SEO social image is the one image this endpoint does own, because it
 * lives in the SEO card of this same form — there is deliberately no
 * separate product-SEO save endpoint. It is written only when another library
 * image was chosen or the "remove" box was ticked; an ordinary save leaves it
 * exactly as it was, like every other image on this page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_validation.php';
require_once __DIR__ . '/_shop_share_image.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\AdminEditorResponse;
use App\Service\CollectionContent;
use App\Service\CollectionService;
use App\Service\Csrf;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\InventoryEditor;
use App\Service\Inventory\StockConflictException;
use App\Service\Inventory\StockNotifications;
use App\Service\Language\AdminTranslator;
use App\Service\ProductGallery;
use App\Service\ProductImageUploader;
use App\Service\ProductSeo;
use App\Service\ProductVariantEditor;
use App\Service\ShopLocalization;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('products.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid product id.');
}

$db = Database::connection();
$productRepository = new ProductRepository($db);
$existing = $productRepository->findByIdForAdmin($id);

if ($existing === null) {
    http_response_code(404);
    exit('Product not found.');
}

$json = AdminEditorResponse::wantsJson();

// `false`: an existing product, so the words written are those of the one
// language the form's hidden field names (Multilingual 2.0 phase 5 wave C).
[$errors, $fields] = validateProductInput($_POST, false);

// The pictures section (admin/_product_gallery.php): the product's own pool in
// its new order, and each shown variant's selection from it, by the variant's
// key. Only when the form carried the section at all, so a request without
// it changes no picture. Checked again, token by token, in ProductGallery.
$gallerySubmitted = ($_POST['gallery_submitted'] ?? null) === '1';
$galleryTokens = ProductGallery::tokens($_POST['gallery'] ?? []);
$variantTokens = ProductGallery::variantTokens($_POST['variants_submitted'] ?? [], $_POST['variant_images'] ?? []);
[$variantDescriptions, $fields['variant_descriptions']] = validateVariantDescriptions($_POST, $errors);

// The Varianten section: options, values and variants as they are on screen.
$variantEditor = ProductVariantEditor::fromRequest($_POST, $id, $db);
foreach ($variantEditor->validate() as $field => $message) {
    $errors[$field] = $message;
}

// The Voorraad section: the switch and, without variants, the product's own stock.
$inventoryEditor = InventoryEditor::fromRequest($_POST);
foreach ($inventoryEditor->validate() as $field => $message) {
    $errors[$field] = $message;
}
if ($inventoryEditor->posted()) {
    $fields['track_stock'] = $inventoryEditor->tracking();
    $fields['stock_input'] = is_string($_POST['stock'] ?? null) ? trim($_POST['stock']) : '';
}

// A refused save shows the same pictures and selections again.
if ($gallerySubmitted) {
    $fields['gallery'] = $galleryTokens;
    $fields['variant_images'] = $variantTokens;
}

// The share image: a library image chosen in the shared picker; an old own
// file stays until another image is chosen or it is removed on purpose
// (shop_share_image_choice()). Nothing is uploaded here.
$share = shop_share_image_choice($_POST, $existing);
$fields['og_media_id'] = array_key_exists('og_media_id', $_POST) ? (int) filter_var($_POST['og_media_id'], FILTER_VALIDATE_INT, ['options' => ['default' => 0]]) : null;

if ($share['error'] !== null) {
    $errors['og_media_id'] = $share['error'];
}

if ($errors !== []) {
    if ($json) {
        AdminEditorResponse::invalid($errors, AdminTranslator::trans('editor.invalid'));
    }

    $_SESSION['admin_product_errors'] = AdminEditorResponse::messages($errors);
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php?id=' . $id);
    exit;
}

$inventory = new Inventory($db);

try {
    // Row, words, variants, pictures and collections are ONE transaction, and
    // the words are only this language's: every other translation of this
    // product stays exactly as it is, so switching the editing language
    // cannot overwrite a translation with a stale copy (Multilingual 2.0
    // phase 5 wave C).
    $db->beginTransaction();

    $productRepository->update($id, [
        'price' => $fields['price'],
        'active' => $fields['active'],
        'in_shop' => $fields['in_shop'],
        'in_personalization_catalog' => $fields['in_personalization_catalog'],
        'shipping_profile' => $fields['shipping_profile'],
        'shipping_weight_grams' => $fields['shipping_weight_grams'],
        'requires_parcel' => $fields['requires_parcel'],
    ]);

    // The gallery's transition, in the Afbeeldingen section: NULL follows
    // the Shop's default (App\Service\ProductGalleryTransition). Only when
    // the form carried the field, like every other section here.
    if ($fields['gallery_transition_submitted']) {
        $productRepository->updateGalleryTransition($id, $fields['gallery_transition']);
    }

    ShopLocalization::saveProduct($id, $fields['language_code'], [
        ShopLocalization::NAME => $fields['name'],
        ShopLocalization::DESCRIPTION => (string) $fields['description'],
        ShopLocalization::META_TITLE => $fields['meta_title'],
        ShopLocalization::META_DESCRIPTION => $fields['meta_description'],
    ]);

    // An old own file this save stops using. It is deleted AFTER the commit,
    // never inside the transaction: an unlink cannot be rolled back, so a
    // later failure would leave the database pointing at a file that no
    // longer exists. delete() is a no-op for anything outside
    // assets/images/products/, and a library file is never named here.
    $unreferencedOgImagePath = null;

    if ($share['change']) {
        // NULL: App\Service\ProductSeo falls back to the product's own photo
        // again, and to the site-wide image after that.
        $productRepository->updateOgImagePath($id, $share['media']?->path, $share['media']?->id);
        $unreferencedOgImagePath = $share['old_path'];
    }

    // Options and values first, then the variants; every variant that exists
    // afterwards by the key the screen posted it under — a new one ("new0")
    // has its id only now.
    $variantIds = $variantEditor->save();

    // After the variants: whether the product has any decides where its
    // stock lives (App\Service\Inventory\ProductStock).
    $inventoryEditor->save($id, $inventory->forProduct($id)->hasVariants(), $db);

    if ($gallerySubmitted) {
        $selections = [];
        foreach ($variantTokens as $key => $tokens) {
            if (isset($variantIds[(string) $key])) {
                $selections[$variantIds[(string) $key]] = $tokens;
            }
        }

        (new ProductGallery($db))->save($id, $galleryTokens, $selections);
    }

    // A variant's own description, in this request's one language. Only
    // variants of THIS product: a key the section did not hand back an id for
    // is skipped, whatever the request says.
    foreach ($variantDescriptions as $key => $html) {
        if (isset($variantIds[(string) $key])) {
            ShopLocalization::saveVariantDescription($variantIds[(string) $key], $fields['language_code'], $html);
        }
    }

    // Synchronise this product's collection memberships: ticked collections
    // are added, unticked ones removed, and an already-selected one is left
    // exactly where it is inside that collection's order. Ids are validated
    // against the collections table first, so a forged or stale id is
    // dropped rather than trusted. Only this product's rows are touched —
    // no other product's membership can change here.
    $collectionRepository = new CollectionRepository($db);
    $collectionIds = CollectionService::validateCollectionIds($fields['collection_ids'], $collectionRepository);
    $collectionRepository->setProductCollections($id, $collectionIds);

    $db->commit();
    (new ProductImageUploader())->delete($unreferencedOgImagePath);


    CollectionContent::clearCache();
    ProductSeo::clearCache();
    ShopLocalization::clearCache();
} catch (StockConflictException $e) {
    // A sale (most likely) changed this stock after the screen was drawn:
    // nothing of this save is stored, and the field says what it is now.
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $conflict = [$e->field => AdminTranslator::trans('validation.stock_changed', ['current' => (string) $e->current])];
    if ($json) {
        AdminEditorResponse::invalid($conflict, AdminTranslator::trans('editor.invalid'));
    }

    $_SESSION['admin_product_errors'] = AdminEditorResponse::messages($conflict);
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php?id=' . $id);
    exit;
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-product.php] ' . $e->getMessage());

    if ($json) {
        AdminEditorResponse::failed(AdminTranslator::trans('editor.product_save_failed'));
    }

    $_SESSION['admin_product_errors'] = [AdminTranslator::trans('editor.product_save_failed')];
    $_SESSION['admin_product_old'] = $fields;
    header('Location: /admin/product-form.php?id=' . $id);
    exit;
}

// Back in stock: whoever waits for a unit of this product that can be
// ordered now gets their mail (App\Service\Inventory\StockNotifications). A
// restock, tracking switched off or the product switched on again all make a
// waiting request due, and a mail that failed before is tried again. After
// the commit, and never at the cost of the save: a mail that fails stays due.
try {
    (new StockNotifications())->dispatchForProduct($id);
} catch (\Throwable $e) {
    error_log('[api/admin/update-product.php] back-in-stock mails: ' . $e->getMessage());
}

if ($json) {
    AdminEditorResponse::saved(AdminTranslator::trans('common.saved'));
}

header('Location: /admin/product-form.php?id=' . $id . '&updated=1');
exit;
