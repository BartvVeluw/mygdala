<?php

/**
 * POST /api/admin/delete-portfolio-item-image.php
 *
 * Permanently deletes one Projectafbeelding (DB row + image/thumbnail files
 * on disk via PortfolioImageProcessor::delete()). Same shape as
 * delete-variant-image.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
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

$imageId = filter_input(INPUT_POST, 'image_id', FILTER_VALIDATE_INT);

if ($imageId === false || $imageId === null || $imageId < 1) {
    http_response_code(400);
    exit('Invalid image id.');
}

$imageRepository = new PortfolioItemImageRepository();
$image = $imageRepository->findById($imageId);

if ($image === null) {
    http_response_code(404);
    exit('Image not found.');
}

$portfolioItemId = (int) $image['portfolio_item_id'];

try {
    $imageRepository->delete($imageId);
    (new PortfolioImageProcessor())->delete((string) $image['image_path'], $image['thumbnail_path'] ?? null);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-portfolio-item-image.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_item_errors'] = ['Foto kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId);
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId . '&updated=1');
exit;
