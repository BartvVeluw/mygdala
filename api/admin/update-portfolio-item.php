<?php

/**
 * POST /api/admin/update-portfolio-item.php
 *
 * Saves everything editable on one Portfolio item's edit page
 * (admin/portfolio-item.php?id=...): Hoofdafbeelding, Basisgegevens,
 * Categorieën, Zichtbaarheid, Projectpagina, Galerij and a legacy linked page
 * are all one <form> there (same "one form, several visual admin-card
 * sections" layout as admin/product-form.php's main product form), so they're
 * saved together here.
 *
 * Title, alt text, caption, the project page's texts and categories are
 * optional, exactly as on create-portfolio-item.php: an editor may empty every
 * word and untick every category, and that is a valid save.
 *
 * ONE LANGUAGE PER REQUEST (Multilingual 2.0 phase 5 wave A). The words
 * written are those of the language `language_code` names, which must be an
 * active website language; every other translation of this item stays exactly
 * as it is, so switching the editing language cannot overwrite a translation
 * with a stale copy. Everything this form saves is one transaction.
 *
 * THE PROJECT PAGE IS THE ITEM'S OWN (Portfolio 2.0, MODULES.md "Portfolio"):
 * `has_detail_page` switches /portfolio/<slug> on, `slug` names it
 * (validatePortfolioProjectPage(): normalised, unique across the Portfolio,
 * made from the default-language title when left empty), and `intro` and
 * `description` are its words in this language, sanitized on the way in. No
 * `pages` row is created, ever. Renaming a public project page records a 301
 * from the old address in every language (PortfolioSlug::recordRename()).
 *
 * THE LEGACY LINKED PAGE (phase 4B) can only be kept or unlinked
 * (portfolioLegacyPageAfterSave()); `page_id` is no longer read, so no new
 * link to an ordinary page can be made. Unlinking never touches that page.
 *
 * THE PICTURE is a Media Library item, chosen in the shared picker and posted
 * as `media_id` (portfolioLibraryImage()). The same id, or none, keeps the
 * picture the item has — also an old one on Portfolio's own path. Another
 * library image replaces it; an old own file is then removed, because it was
 * Portfolio's alone, while a library file is never removed here (it may be
 * used elsewhere).
 *
 * THE GALLERY is the ordered `gallery[]` tokens of the project page's extra
 * photos (App\Service\PortfolioProjectGallery), sent only when the section
 * was on the form (`gallery_submitted`), so a request without it never empties
 * the gallery. A removed photo loses its RELATION: a library file is never
 * deleted, and only an old photo on Portfolio's own path (media_id NULL), which
 * was this item's alone, has its file removed after the commit.
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
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PortfolioImageProcessor;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\PortfolioProjectGallery;
use App\Service\PortfolioSlug;
use App\Repository\PortfolioCategoryRepository;
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

$db = Database::connection();
$repository = new PortfolioGalleryRepository($db);
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$imageRepository = new PortfolioItemImageRepository($db);
$categoryIds = validatePortfolioCategoryIds($_POST['categories'] ?? null, new PortfolioCategoryRepository($db));
$pageId = portfolioLegacyPageAfterSave($_POST, $item);

// The words of exactly ONE language, the one the form's hidden field names
// (`false`: an existing item, so the language comes from the screen), the
// project page's texts included.
[$errors, $language, $words] = validatePortfolioItemWords($_POST, false, true);

// A slug left empty is made from the title in the DEFAULT language, so it
// never depends on which language the editor happened to be writing in.
$defaultTitle = $language === PortfolioLocalization::defaultLanguage()
    ? $words[PortfolioLocalization::TITLE]
    : PortfolioLocalization::rawItemValue($itemId, PortfolioLocalization::TITLE, PortfolioLocalization::defaultLanguage());
$currentSlug = ($item['slug'] ?? null) !== null ? (string) $item['slug'] : null;
[$projectErrors, $hasDetailPage, $slug] = validatePortfolioProjectPage(
    $_POST,
    $repository,
    $itemId,
    (int) ($item['has_detail_page'] ?? 0) === 1,
    $currentSlug,
    $defaultTitle
);
$errors = array_merge($errors, $projectErrors);

$gallerySubmitted = ($_POST['gallery_submitted'] ?? '') === '1';
$galleryTokens = array_values(array_filter(
    is_array($_POST['gallery'] ?? null) ? $_POST['gallery'] : [],
    'is_string'
));

// A refused save comes back with what was typed, in the language it was typed
// in — nothing is written, and nothing is lost either.
$old = $words + [
    'categories' => is_array($_POST['categories'] ?? null) ? $_POST['categories'] : [],
    // The picture chosen in the picker, so a refused save shows it again.
    'media_id' => (int) filter_var($_POST['media_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]),
    'is_active' => isset($_POST['is_active']),
    'is_featured' => isset($_POST['is_featured']),
    'has_detail_page' => $hasDetailPage,
    'slug' => is_string($_POST['slug'] ?? null) ? trim($_POST['slug']) : '',
    'unlink_page' => ($_POST['unlink_page'] ?? '') === '1',
    'gallery' => $gallerySubmitted ? $galleryTokens : null,
];

if ($errors !== []) {
    $_SESSION['admin_portfolio_item_errors'] = $errors;
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

// The picture: another library image replaces it; the same one, or none
// posted, keeps what the item has.
$currentMediaId = (int) ($item['media_id'] ?? 0);
$media = portfolioLibraryImage($_POST['media_id'] ?? null);
$replacesImage = $media !== null && $media->id !== $currentMediaId;
$mainMediaId = $replacesImage ? $media->id : ($currentMediaId > 0 ? $currentMediaId : null);

$wasFeatured = (int) $item['is_featured'] === 1;
$isFeatured = isset($_POST['is_featured']);
$isActive = isset($_POST['is_active']);

if ($isFeatured && !$wasFeatured) {
    $featuredSortOrder = $repository->nextFeaturedSortOrder((int) $item['portfolio_gallery_id']);
} elseif (!$isFeatured) {
    $featuredSortOrder = null;
} else {
    $featuredSortOrder = $item['featured_sort_order'];
}

$fields = ($replacesImage ? portfolioImageColumns($media) : [
    'media_id' => $currentMediaId > 0 ? $currentMediaId : null,
    'image_path' => (string) $item['image_path'],
    'thumbnail_path' => $item['thumbnail_path'] ?? null,
]) + [
    'is_active' => $isActive,
    'is_featured' => $isFeatured,
    'featured_sort_order' => $featuredSortOrder,
];

$removedPhotos = [];

try {
    $db->beginTransaction();
    $repository->updateItem($itemId, $fields);
    PortfolioLocalization::saveItem($itemId, $language, $words);
    $repository->setItemCategories($itemId, $categoryIds);
    $repository->setItemPage($itemId, $pageId);
    $repository->setItemProjectPage($itemId, $hasDetailPage, $slug);

    if ($gallerySubmitted) {
        $removedPhotos = $imageRepository->replaceForItem($itemId, PortfolioProjectGallery::resolve(
            $galleryTokens,
            $imageRepository->findByPortfolioItemId($itemId),
            $mainMediaId
        ));
    }

    PortfolioSlug::recordRename(
        PortfolioSlug::isPublic((int) $item['is_active'] === 1, (int) ($item['has_detail_page'] ?? 0) === 1, $currentSlug),
        $currentSlug,
        PortfolioSlug::isPublic($isActive, $hasDetailPage, $slug),
        $slug
    );
    $db->commit();
    PortfolioGalleryContent::clearCache();

    // Files that were this item's alone go after the commit, never before: a
    // rolled-back save must still find them. A library file never goes.
    $imageProcessor = new PortfolioImageProcessor();

    if ($replacesImage && $currentMediaId === 0) {
        $imageProcessor->delete((string) $item['image_path'], $item['thumbnail_path'] ?? null);
    }

    foreach ($removedPhotos as $photo) {
        if ((int) ($photo['media_id'] ?? 0) === 0) {
            $imageProcessor->delete((string) $photo['image_path'], $photo['thumbnail_path'] ?? null);
        }
    }
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-portfolio-item.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_item_errors'] = ['Portfolio-item kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_portfolio_item_old'] = $old;
    header('Location: /admin/portfolio-item.php?id=' . $itemId);
    exit;
}

header('Location: /admin/portfolio-item.php?id=' . $itemId . '&updated=1');
exit;
