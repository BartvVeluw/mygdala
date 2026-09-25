<?php

/**
 * POST /api/admin/delete-portfolio-item.php
 *
 * Permanently deletes one Portfolio item: its own row (DB, plus its main
 * image/thumbnail files via PortfolioImageProcessor::delete() only when that
 * picture is an old one on Portfolio's own path — a Media Library picture
 * stays in the library, only the reference goes), plus every
 * portfolio_item_images row — the FK's ON DELETE CASCADE removes those rows
 * automatically, but the files they reference on disk are only removed by
 * this loop first (the database cascade doesn't touch the filesystem).
 *
 * ITS WORDS GO WITH IT, in every language, and so do its photos' alt texts:
 * portfolio_item_translations and portfolio_item_image_translations both hang
 * off their row with ON DELETE CASCADE (Multilingual 2.0 phase 5 wave A).
 * Unlike a block's words in the polymorphic block_translations, these need no
 * PHP step before the DELETE — the database has a real foreign key here.
 *
 * A page the item links to is not the item's: it is an ordinary CMS page, and
 * deleting the item leaves it exactly as it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

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

$imageProcessor = new PortfolioImageProcessor();

try {
    $imageRepository = new PortfolioItemImageRepository();
    $extraImages = $imageRepository->findByPortfolioItemId($itemId);

    $repository->deleteItem($itemId);

    // A picture from the Media Library is the library's: deleting the item
    // only removes its reference. Only an old picture on Portfolio's own path
    // (media_id NULL) was this item's alone and goes with it.
    if ((int) ($item['media_id'] ?? 0) === 0) {
        $imageProcessor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
    }

    foreach ($extraImages as $extraImage) {
        $imageProcessor->delete((string) $extraImage['image_path'], $extraImage['thumbnail_path'] ?? null);
    }

    PortfolioGalleryContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/delete-portfolio-item.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_errors'] = ['Portfolio-item kon niet worden verwijderd.'];
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

header('Location: /admin/portfolio.php?deleted=1');
exit;
