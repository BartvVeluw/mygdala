<?php

/**
 * POST /api/admin/update-portfolio-item.php
 *
 * Saves everything editable on one Portfolio item's edit page
 * (admin/portfolio-item.php?id=...): Hoofdafbeelding, Basisgegevens,
 * Zichtbaarheid & categorieën and Projectpagina are all one <form> there
 * (same "one form, several visual admin-card sections" layout as
 * admin/product-form.php's main product form), so they're saved together
 * here — same as the old update-gallery-item.php this replaces.
 *
 * Title, alt text, caption and categories are optional, exactly as on
 * create-portfolio-item.php: an editor may empty every word and untick every
 * category, and that is a valid save.
 *
 * ONE LANGUAGE PER REQUEST (Multilingual 2.0 phase 5 wave A). The words
 * written are those of the language `language_code` names, which must be an
 * active website language; every other translation of this item stays exactly
 * as it is, so switching the editing language cannot overwrite a translation
 * with a stale copy. The row, the words, the categories and the page choice
 * are one transaction.
 *
 * THE PROJECT PAGE is one posted id, `page_id`: empty for no page, otherwise a
 * page an item may link to (validatePortfolioPageChoice()). A choice that is
 * neither is refused before a single column is written, never quietly turned
 * into "no page", which would drop a link the editor meant to keep. Only the
 * id is stored (PortfolioGalleryRepository::setItemPage()); the page's
 * address, texts, SEO and publication stay the page's own.
 *
 * WHAT THIS NO LONGER WRITES. has_detail_page, slug, and the item's intro and
 * description words belong to the project page the Portfolio used to own.
 * Nothing posted here reaches them: they keep their values, in every
 * language, for the old address that page still answers at (MODULES.md,
 * "Portfolio").
 *
 * Categories are CMS-managed (App\Repository\PortfolioCategoryRepository) —
 * `categories[]` posts category ids, validated against what actually exists
 * (validatePortfolioCategoryIds()) and persisted via
 * PortfolioGalleryRepository::setItemCategories(), not the legacy
 * `categories` string column.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Repository\PageRepository;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$db = Database::connection();
$repository = new PortfolioGalleryRepository($db);
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$categoryIds = validatePortfolioCategoryIds($_POST['categories'] ?? null, new PortfolioCategoryRepository($db));
$pageId = validatePortfolioPageChoice($_POST['page_id'] ?? null, new PageRepository($db));

// The words of exactly ONE language, the one the form's hidden field names
// (`false`: an existing item, so the language comes from the screen).
[$errors, $language, $words] = validatePortfolioItemWords($_POST, false);

if ($pageId === false) {
    $errors[] = AdminTranslator::trans('validation.portfolio_page_unknown');
}

// A refused save comes back with what was typed, in the language it was typed
// in — nothing is written, and nothing is lost either.
$old = $words + [
    'categories' => is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [],
];

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

$imageProcessor = new PortfolioImageProcessor();
$newImagePath = null;
$newThumbnailPath = null;

$hasNewFile = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

if ($hasNewFile) {
    try {
        $uploadResult = $imageProcessor->store($_FILES['image']);
        $newImagePath = $uploadResult['path'];
        $newThumbnailPath = $uploadResult['thumbnail_path'];
    } catch (\RuntimeException $e) {
        $_SESSION['admin_portfolio_item_errors'] = [$e->getMessage()];
        $_SESSION['admin_portfolio_item_old'] = $old;
        header('Location: /admin/portfolio-item.php?id=' . $itemId);
        exit;
    }
}

$wasFeatured = (int) $item['is_featured'] === 1;
$isFeatured = isset($_POST['is_featured']);

if ($isFeatured && !$wasFeatured) {
    $featuredSortOrder = $repository->nextFeaturedSortOrder((int) $item['portfolio_gallery_id']);
} elseif (!$isFeatured) {
    $featuredSortOrder = null;
} else {
    $featuredSortOrder = $item['featured_sort_order'];
}

$fields = [
    'image_path' => $newImagePath ?? (string) $item['image_path'],
    'thumbnail_path' => $newImagePath !== null ? $newThumbnailPath : ($item['thumbnail_path'] ?? null),
    'is_active' => isset($_POST['is_active']),
    'is_featured' => $isFeatured,
    'featured_sort_order' => $featuredSortOrder,
];

try {
    // Row, words, categories and the page choice are ONE transaction. Only
    // the three fields this form shows are handed to saveItem(), so the old
    // project page's `intro` and `description` in this language keep exactly
    // what they hold, and every OTHER language keeps all of its words.
    $db->beginTransaction();
    $repository->updateItem($itemId, $fields);
    PortfolioLocalization::saveItem($itemId, $language, $words);
    $repository->setItemCategories($itemId, $categoryIds);
    $repository->setItemPage($itemId, $pageId);
    $db->commit();
    PortfolioGalleryContent::clearCache();

    if ($newImagePath !== null) {
        $imageProcessor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-portfolio-item.php] ' . $e->getMessage());
    if ($newImagePath !== null) {
        $imageProcessor->delete($newImagePath, $newThumbnailPath);
    }
    $_SESSION['admin_portfolio_item_errors'] = ['Portfolio-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $itemId . '&updated=1');
exit;
