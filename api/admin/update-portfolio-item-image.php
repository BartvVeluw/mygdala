<?php

/**
 * POST /api/admin/update-portfolio-item-image.php
 *
 * Edits one Projectafbeelding's alt text (NL/EN) only — never touches or
 * replaces the underlying uploaded file. Same shape as
 * update-variant-image.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
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

$portfolioItemId = filter_input(INPUT_POST, 'portfolio_item_id', FILTER_VALIDATE_INT);

$altNl = trim((string) ($_POST['alt_nl'] ?? ''));
$altEn = trim((string) ($_POST['alt_en'] ?? ''));

if (mb_strlen($altNl) > 255 || mb_strlen($altEn) > 255) {
    $_SESSION['admin_portfolio_item_errors'] = [AdminTranslator::trans('validation.alt_tekst_mag_maximaal_255')];
    header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId);
    exit;
}

$imageRepository->updateMeta($imageId, $altNl !== '' ? $altNl : null, $altEn !== '' ? $altEn : null);

header('Location: /admin/portfolio-item.php?id=' . $portfolioItemId . '&updated=1');
exit;
