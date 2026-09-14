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
 * THE PROJECT PAGE is one posted id, `page_id`: empty for no page, otherwise a
 * page an item may link to (validatePortfolioPageChoice()). A choice that is
 * neither is refused before a single column is written, never quietly turned
 * into "no page", which would drop a link the editor meant to keep. Only the
 * id is stored (PortfolioGalleryRepository::setItemPage()); the page's
 * address, texts, SEO and publication stay the page's own.
 *
 * WHAT THIS NO LONGER WRITES. has_detail_page, slug, intro_* and description_*
 * belong to the project page the Portfolio used to own. Nothing posted here
 * reaches them: they keep their values for the old address that page still
 * answers at (MODULES.md, "Portfolio").
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

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
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

$repository = new PortfolioGalleryRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$altNl = trim((string) ($_POST['alt_nl'] ?? ''));
$altEn = trim((string) ($_POST['alt_en'] ?? ''));
$titleNl = trim((string) ($_POST['title_nl'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));
$subtitleNl = trim((string) ($_POST['subtitle_nl'] ?? ''));
$subtitleEn = trim((string) ($_POST['subtitle_en'] ?? ''));
$categoryIds = validatePortfolioCategoryIds($_POST['categories'] ?? null, new PortfolioCategoryRepository());
$pageId = validatePortfolioPageChoice($_POST['page_id'] ?? null, new PageRepository());

$errors = [];
if (mb_strlen($altNl) > 255 || mb_strlen($altEn) > 255) {
    $errors[] = AdminTranslator::trans('validation.alt_tekst_mag_maximaal_255');
}
if (mb_strlen($titleNl) > 150 || mb_strlen($titleEn) > 150) {
    $errors[] = AdminTranslator::trans('validation.titel_mag_maximaal_150_tekens');
}
if (mb_strlen($subtitleNl) > 150 || mb_strlen($subtitleEn) > 150) {
    $errors[] = AdminTranslator::trans('validation.onderschrift_mag_maximaal_150_tekens');
}
if ($pageId === false) {
    $errors[] = AdminTranslator::trans('validation.portfolio_page_unknown');
}

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
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
    'alt_nl' => $altNl,
    'alt_en' => $altEn,
    'title_nl' => $titleNl,
    'title_en' => $titleEn,
    'subtitle_nl' => $subtitleNl,
    'subtitle_en' => $subtitleEn,
    'is_active' => isset($_POST['is_active']),
    'is_featured' => $isFeatured,
    'featured_sort_order' => $featuredSortOrder,
];

try {
    $repository->updateItem($itemId, $fields);
    $repository->setItemCategories($itemId, $categoryIds);
    $repository->setItemPage($itemId, $pageId);
    PortfolioGalleryContent::clearCache();

    if ($newImagePath !== null) {
        $imageProcessor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/update-portfolio-item.php] ' . $e->getMessage());
    if ($newImagePath !== null) {
        $imageProcessor->delete($newImagePath, $newThumbnailPath);
    }
    $_SESSION['admin_portfolio_item_errors'] = ['Portfolio-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $itemId . '&updated=1');
exit;
