<?php

/**
 * POST /api/admin/update-portfolio-item.php
 *
 * Saves everything editable on one Portfolio item's dedicated edit page
 * (admin/portfolio-item.php?id=...): Basisgegevens, Hoofdafbeelding,
 * Zichtbaarheid & categorieën and Projectpagina are all one <form> there
 * (same "one form, several visual admin-card sections" layout as
 * admin/product-form.php's main product form), so they're saved together
 * here — same as the old update-gallery-item.php this replaces, just
 * extended with the has_detail_page/slug/intro/description fields.
 *
 * Additional project images (Projectafbeeldingen) are a separate concern
 * with their own endpoints (add/update/delete/reorder-portfolio-item-
 * images.php), same split as product photos vs. the main product form.
 *
 * Categories are CMS-managed (App\Repository\PortfolioCategoryRepository) —
 * `categories[]` posts category ids, validated against what actually exists
 * (validatePortfolioCategoryIds()) and persisted via
 * PortfolioGalleryRepository::setItemCategories(), not the legacy
 * `categories` string column. Introtekst/Projectbeschrijving are rich text:
 * sanitized through RichTextSanitizer before ever reaching the database —
 * see that class for the exact allowlist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
use App\Service\RichTextSanitizer;
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
$introNlRaw = trim((string) ($_POST['intro_nl'] ?? ''));
$introEnRaw = trim((string) ($_POST['intro_en'] ?? ''));
$descriptionNlRaw = trim((string) ($_POST['description_nl'] ?? ''));
$descriptionEnRaw = trim((string) ($_POST['description_en'] ?? ''));
$hasDetailPage = isset($_POST['has_detail_page']);
$slugInput = trim((string) ($_POST['slug'] ?? ''));

$errors = [];
if ($altNl === '') {
    $errors[] = AdminTranslator::trans('validation.alt_tekst_nl_verplicht');
} elseif (mb_strlen($altNl) > 255 || mb_strlen($altEn) > 255) {
    $errors[] = AdminTranslator::trans('validation.alt_tekst_mag_maximaal_255');
}
if ($titleNl === '') {
    $errors[] = AdminTranslator::trans('validation.titel_nl_verplicht');
} elseif (mb_strlen($titleNl) > 150 || mb_strlen($titleEn) > 150) {
    $errors[] = AdminTranslator::trans('validation.titel_mag_maximaal_150_tekens');
}
if ($subtitleNl === '') {
    $errors[] = AdminTranslator::trans('validation.onderschrift_nl_verplicht');
} elseif (mb_strlen($subtitleNl) > 150 || mb_strlen($subtitleEn) > 150) {
    $errors[] = AdminTranslator::trans('validation.onderschrift_mag_maximaal_150_tekens');
}
if ($categoryIds === []) {
    $errors[] = AdminTranslator::trans('validation.kies_minstens_n_categorie');
}
if (mb_strlen($introNlRaw) > 20000 || mb_strlen($introEnRaw) > 20000) {
    $errors[] = 'Introtekst is te lang.';
}
if (mb_strlen($descriptionNlRaw) > 20000 || mb_strlen($descriptionEnRaw) > 20000) {
    $errors[] = 'Projectbeschrijving is te lang.';
}

// Slug is only meaningful (and only validated) once a detail page is
// enabled — see AddDetailPageFieldsToPortfolioGalleryItems's docblock.
$slug = (string) ($item['slug'] ?? '');
if ($hasDetailPage) {
    if ($slugInput !== '') {
        $sanitized = sanitizePortfolioItemSlug($slugInput);
        if ($sanitized === '') {
            $errors[] = AdminTranslator::trans('validation.slug_bevat_geldige_tekens');
        } elseif ($repository->slugExists($sanitized, $itemId)) {
            $errors[] = AdminTranslator::trans('validation.slug_al_gebruik_door_ander');
        } else {
            $slug = $sanitized;
        }
    } elseif ($slug === '') {
        // First time detail mode is enabled with no slug typed yet: derive
        // one from the Dutch title automatically (still editable afterwards).
        $slug = generatePortfolioItemSlug($repository, $titleNl, $itemId);
    }
}

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

// The rich-text editor's toolbar (admin/_richtext_field.php) only ever
// produces a small set of tags via document.execCommand, but the actual
// security boundary is here: RichTextSanitizer allowlists every tag/
// attribute before anything reaches the database, exactly like
// DescriptionSanitizer does for product descriptions. Never trust the
// posted HTML as-is.
$introNl = RichTextSanitizer::sanitize($introNlRaw) ?? '';
$introEn = RichTextSanitizer::sanitize($introEnRaw) ?? '';
$descriptionNl = RichTextSanitizer::sanitize($descriptionNlRaw) ?? '';
$descriptionEn = RichTextSanitizer::sanitize($descriptionEnRaw) ?? '';

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
    'has_detail_page' => $hasDetailPage,
    'slug' => $slug,
    'intro_nl' => $introNl,
    'intro_en' => $introEn,
    'description_nl' => $descriptionNl,
    'description_en' => $descriptionEn,
];

try {
    $repository->updateItem($itemId, $fields);
    $repository->setItemCategories($itemId, $categoryIds);
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
