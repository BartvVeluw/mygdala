<?php

/**
 * POST /api/admin/add-portfolio-item-images.php
 *
 * Adds one or more "Projectafbeeldingen" (additional gallery photos) to an
 * existing Portfolio item's detail page (multipart/form-data, `images[]`).
 * Same normalizeMultiFileInput() pattern as add-variant-images.php, adapted
 * to portfolio_item_images — uploads go through App\Service\
 * PortfolioImageProcessor (validate + store via SectionImageUploader, then
 * optimize + thumbnail), not SectionImageUploader directly.
 *
 * Two response modes, chosen by the `ajax` POST field:
 * - Absent (plain browser form submit, no JS): the original PRG behavior —
 *   redirect back to admin/portfolio-item.php with a session-flash error/
 *   success message. This is the no-JS fallback and still accepts a native
 *   multi-file selection in one request, same as before.
 * - `ajax=1`: JSON response instead of a redirect. Used by
 *   admin/assets/admin.js's sequential per-file upload (one file per
 *   request), added to fix a bug where selecting several large photos at
 *   once combined them into a single multipart POST that exceeded PHP's
 *   post_max_size (see MAIN.MD, "Portfolio: upload van meerdere
 *   projectafbeeldingen faalt bij een grote gecombineerde POST"). Each
 *   ajax request still only ever contains one file via `images[]`, so this
 *   endpoint's per-file logic below is unchanged either way.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_product_image_helpers.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\PortfolioItemImageRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

$isAjax = ($_POST['ajax'] ?? '') === '1';

/**
 * @param list<string> $errors
 */
function respondPortfolioImagesError(bool $isAjax, int $portfolioItemId, int $statusCode, array $errors): never
{
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        echo json_encode(['ok' => false, 'error' => implode(' ', $errors)]);
        exit;
    }

    $_SESSION['admin_portfolio_item_errors'] = $errors;
    header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
        exit;
    }

    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$portfolioItemId = filter_input(INPUT_POST, 'portfolio_item_id', FILTER_VALIDATE_INT);

if ($portfolioItemId === false || $portfolioItemId === null || $portfolioItemId < 1) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid portfolio item id.']);
        exit;
    }

    http_response_code(400);
    exit('Invalid portfolio item id.');
}

$item = (new PortfolioGalleryRepository())->findItemById($portfolioItemId);

if ($item === null) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Portfolio item not found.']);
        exit;
    }

    http_response_code(404);
    exit('Portfolio item not found.');
}

$fileEntries = normalizeMultiFileInput($_FILES['images'] ?? null);

if ($fileEntries === []) {
    respondPortfolioImagesError($isAjax, $portfolioItemId, 400, ["Geen foto('s) geselecteerd."]);
}

$imageProcessor = new PortfolioImageProcessor();
$uploadedImages = [];
$errors = [];

foreach ($fileEntries as $fileEntry) {
    try {
        $uploadedImages[] = $imageProcessor->store($fileEntry);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    foreach ($uploadedImages as $uploaded) {
        $imageProcessor->delete($uploaded['path'], $uploaded['thumbnail_path']);
    }

    respondPortfolioImagesError($isAjax, $portfolioItemId, 400, $errors);
}

try {
    $imageRepository = new PortfolioItemImageRepository();
    foreach ($uploadedImages as $uploaded) {
        $imageRepository->create($portfolioItemId, $uploaded['path'], null, null, $uploaded['thumbnail_path']);
    }
} catch (\Throwable $e) {
    error_log('[api/admin/add-portfolio-item-images.php] ' . $e->getMessage());
    foreach ($uploadedImages as $uploaded) {
        $imageProcessor->delete($uploaded['path'], $uploaded['thumbnail_path']);
    }

    respondPortfolioImagesError($isAjax, $portfolioItemId, 500, ["Foto('s) konden niet worden opgeslagen. Probeer het opnieuw."]);
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId . '&updated=1');
exit;
