<?php

/**
 * POST /api/admin/update-shop-listing.php
 *
 * Saves the optional head of one Productgrid or Collectie-tegels block
 * (admin/shop-listing.php?section=<page>:<key>): an eyebrow, a title and a
 * text in one website language (App\Service\Blocks\BlockHead). The same guard
 * order and PRG/session-flash pattern as api/admin/update-featured-product.php,
 * and the same "valid only when the page AND its content row already exist"
 * gate: an arbitrary page_slug:section_key pair is never trusted beyond that.
 *
 * THE SHOP FIRST. Both blocks belong to the Shop, but the editor is guarded by
 * the permission of the list the block is on (ContentBlockAccess, a Core
 * permission still held while the Shop is off), so App\Module\ModuleGuard
 * refuses before anything else when the Shop is off.
 *
 * WHICH BLOCK. The row must have been placed by one of the two listing types
 * (page_sections.section_type, or a draft of one), never by a name from the
 * request. Nothing else is written: no product, no collection, no setting —
 * the block has none — and no style (Extra vormgeving has its own endpoint).
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): only the language in
 * `language_code`, an active language of the website registry, is written,
 * through App\Service\Blocks\BlockLocalization against the block's own
 * declaration. Plain text: the partial escapes it, nothing is stored as HTML.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
\App\Module\ModuleGuard::requireApi('shop');

use App\Database;
use App\Repository\ShopListingRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockHead;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\ContentBlockDrafts;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\SectionRegistry;
use App\Service\ShopListingContent;

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
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$section = ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === '')
    ? null
    : (new ShopListingRepository())->findBySlugAndKey($pageSlug, $sectionKey);

$type = null;
foreach (['product_grid', 'shop_collections'] as $candidate) {
    if ($section !== null && SectionRegistry::exists($candidate) && ContentBlockDrafts::belongsTo($candidate, (int) $section['id'])) {
        $type = $candidate;
        break;
    }
}

if ($type === null || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi((string) $pageSlug) === null) {
    http_response_code(404);
    exit('Unknown section.');
}

$sectionId = (int) $section['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

// Exactly the head's three fields, never a name taken from the request.
$words = [];
foreach (BlockHead::KEYS as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems(ShopListingRepository::TABLE, $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

$back = '/admin/shop-listing.php?section=' . urlencode($sectionParam);

if ($errors !== []) {
    $_SESSION['admin_shop_listing_errors'] = $errors;
    $_SESSION['admin_shop_listing_old'] = ['language_code' => $languageCode] + $words;
    header('Location: ' . $back);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::save(ShopListingRepository::TABLE, $sectionId, $languageCode, $words);

    // A new block joins its page now, in this save's transaction, and the
    // site search's copy of its words with it (ContentBlockDrafts::place()).
    $placed = ContentBlockDrafts::place($type, $sectionId);
    $db->commit();
    ShopListingContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-shop-listing.php] ' . $e->getMessage());

    $_SESSION['admin_shop_listing_errors'] = [\App\Service\ContentOwners\OwnerContentGuard::messageFor($e) ?? AdminTranslator::trans('validation.shop_listing_not_saved')];
    $_SESSION['admin_shop_listing_old'] = ['language_code' => $languageCode] + $words;
    header('Location: ' . $back);
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, $back));
exit;
