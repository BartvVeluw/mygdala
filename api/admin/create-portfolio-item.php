<?php

/**
 * POST /api/admin/create-portfolio-item.php
 *
 * Creates a new Portfolio item from the admin "Nieuw portfolio-item" form
 * (admin/portfolio-item.php with no ?id=) — the minimal starter fields only
 * (image, alt/title/subtitle NL+EN, categories). Detail-page fields
 * (slug, intro, description, additional images) are edited afterwards on the
 * item's own edit page, same as how a new product's variants/extra photos
 * are only added after the product itself exists.
 *
 * There is exactly one Portfolio catalogue row (see
 * App\Repository\PortfolioGalleryRepository::ensureCatalogue()), so unlike
 * the old create-gallery-item.php this endpoint looks it up itself instead
 * of taking a gallery_id from the form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
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

$repository = new PortfolioGalleryRepository();
$galleryId = (int) $repository->ensureCatalogue()['id'];

$altNl = trim((string) ($_POST['alt_nl'] ?? ''));
$altEn = trim((string) ($_POST['alt_en'] ?? ''));
$titleNl = trim((string) ($_POST['title_nl'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));
$subtitleNl = trim((string) ($_POST['subtitle_nl'] ?? ''));
$subtitleEn = trim((string) ($_POST['subtitle_en'] ?? ''));
$categoryIds = validatePortfolioCategoryIds($_POST['categories'] ?? null, new PortfolioCategoryRepository());

$errors = [];
if ($altNl === '') {
    $errors[] = 'Alt-tekst (NL) is verplicht.';
} elseif (mb_strlen($altNl) > 255 || mb_strlen($altEn) > 255) {
    $errors[] = 'Alt-tekst mag maximaal 255 tekens zijn.';
}
if ($titleNl === '') {
    $errors[] = 'Titel (NL) is verplicht.';
} elseif (mb_strlen($titleNl) > 150 || mb_strlen($titleEn) > 150) {
    $errors[] = 'Titel mag maximaal 150 tekens zijn.';
}
if ($subtitleNl === '') {
    $errors[] = 'Onderschrift (NL) is verplicht.';
} elseif (mb_strlen($subtitleNl) > 150 || mb_strlen($subtitleEn) > 150) {
    $errors[] = 'Onderschrift mag maximaal 150 tekens zijn.';
}
if ($categoryIds === []) {
    $errors[] = 'Kies minstens één categorie.';
}

$old = [
    'alt_nl' => $altNl, 'alt_en' => $altEn,
    'title_nl' => $titleNl, 'title_en' => $titleEn,
    'subtitle_nl' => $subtitleNl, 'subtitle_en' => $subtitleEn,
    'categories' => is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [],
];

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

$imageProcessor = new PortfolioImageProcessor();

try {
    $uploadResult = $imageProcessor->store($_FILES['image'] ?? []);
} catch (\RuntimeException $e) {
    $_SESSION['admin_portfolio_item_errors'] = [$e->getMessage()];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

$imagePath = $uploadResult['path'];
$thumbnailPath = $uploadResult['thumbnail_path'];

try {
    $itemId = $repository->createItem($galleryId, [
        'image_path' => $imagePath,
        'thumbnail_path' => $thumbnailPath,
        'alt_nl' => $altNl,
        'alt_en' => $altEn,
        'title_nl' => $titleNl,
        'title_en' => $titleEn,
        'subtitle_nl' => $subtitleNl,
        'subtitle_en' => $subtitleEn,
    ]);
    $repository->setItemCategories($itemId, $categoryIds);
    PortfolioGalleryContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-portfolio-item.php] ' . $e->getMessage());
    $imageProcessor->delete($imagePath, $thumbnailPath);
    $_SESSION['admin_portfolio_item_errors'] = ['Portfolio-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $itemId . '&created=1');
exit;
