<?php

/**
 * POST /api/admin/create-portfolio-item.php
 *
 * Creates a new Portfolio item from the admin "Nieuw portfolio-item" form
 * (admin/portfolio-item.php with no ?id=). The image IS the item: title, alt
 * text, caption and categories are all optional, in every language, and an
 * item with none of them is valid — the gallery then shows the picture alone,
 * without an empty caption over it (partials/section-item-gallery.php). Only
 * their length is checked here. An empty alt text is a real answer too: it
 * marks a decorative image, and the page renders alt="" for it rather than
 * inventing one from the file name.
 *
 * ONE LANGUAGE, THE DEFAULT ONE (Multilingual 2.0 phase 5 wave A). A new item
 * is born in the website's default language, like a new page and every new
 * child row, whatever language the screen was showing; translating it happens
 * on the item afterwards. Row, words and categories are one transaction.
 *
 * THE PICTURE IS A MEDIA LIBRARY ITEM (Media Library 2.0): the editor chooses
 * or uploads it in the shared picker, and this endpoint receives its id
 * (portfolioLibraryImage()) — never a file. image_path and thumbnail_path are
 * written along with it, so every public reader keeps reading what it always
 * read.
 *
 * Detail-page fields (slug, intro, description, additional images) are edited
 * afterwards on the item's own edit page, same as how a new product's
 * variants/extra photos are only added after the product itself exists.
 *
 * There is exactly one Portfolio catalogue row (see
 * App\Repository\PortfolioGalleryRepository::ensureCatalogue()), so unlike
 * the old create-gallery-item.php this endpoint looks it up itself instead
 * of taking a gallery_id from the form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_portfolio_validation.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
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

$db = Database::connection();
$repository = new PortfolioGalleryRepository($db);
$galleryId = (int) $repository->ensureCatalogue()['id'];

$categoryIds = validatePortfolioCategoryIds($_POST['categories'] ?? null, new PortfolioCategoryRepository($db));

// A new item is written in the DEFAULT language, whatever language the screen
// was showing — hence `true`.
[$errors, $language, $words] = validatePortfolioItemWords($_POST, true);

$old = $words + [
    'categories' => is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [],
    // The picture chosen in the picker, so a refused save shows it again.
    'media_id' => (int) filter_var($_POST['media_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]),
];

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

// The one thing an item cannot do without: a picture from the library.
$media = portfolioLibraryImage($_POST['media_id'] ?? null);

if ($media === null) {
    $_SESSION['admin_portfolio_item_errors'] = [AdminTranslator::trans('portfolio.image_required')];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

try {
    // Row, words and categories are ONE transaction: an item is never in the
    // catalogue without the words that were typed for it.
    $db->beginTransaction();
    $itemId = $repository->createItem($galleryId, portfolioImageColumns($media));
    PortfolioLocalization::saveItem($itemId, $language, $words);
    $repository->setItemCategories($itemId, $categoryIds);
    $db->commit();
    PortfolioGalleryContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-portfolio-item.php] ' . $e->getMessage());
    // The library item stays: it is the library's, not this item's.
    $_SESSION['admin_portfolio_item_errors'] = ['Portfolio-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php');
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $itemId . '&created=1');
exit;
