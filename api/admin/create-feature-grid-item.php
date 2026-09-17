<?php

/**
 * POST /api/admin/create-feature-grid-item.php
 *
 * Adds a new card to a Feature grid. Same guard order / PRG pattern as
 * api/admin/create-product-option.php.
 *
 * A NEW CARD IS WRITTEN IN THE DEFAULT LANGUAGE (Multilingual 2.0), like a
 * new page: the words the form sends are stored as the website's default
 * language, where the title and text are required, and every other language
 * is added afterwards on the card itself (update-feature-grid-item.php). The
 * icon is the same in every language. The row and its words are one
 * transaction, so a card never exists without its words or the other way
 * round.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\FeatureGridContent;
use App\Repository\FeatureGridRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$gridId = filter_input(INPUT_POST, 'grid_id', FILTER_VALIDATE_INT);
if ($gridId === false || $gridId === null || $gridId < 1) {
    http_response_code(400);
    exit('Invalid grid id.');
}

$repository = new FeatureGridRepository();
$grid = $repository->findById($gridId);

if ($grid === null) {
    http_response_code(404);
    exit('Grid not found.');
}

$sectionKey = $grid['page_slug'] . ':' . $grid['section_key'];

$iconKey = is_string($_POST['icon_key'] ?? null) ? $_POST['icon_key'] : '';
$settings = [
    'icon_key' => array_key_exists($iconKey, FeatureGridContent::ICON_KEYS) ? $iconKey : array_key_first(FeatureGridContent::ICON_KEYS),
];

$defaultLanguage = BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('feature_grid_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];
foreach (BlockLocalization::messageKeys(BlockLocalization::problems('feature_grid_items', $defaultLanguage, $words)) as $key) {
    $errors[] = AdminTranslator::trans($key);
}

if ($errors !== []) {
    $_SESSION['admin_feature_grid_item_errors'] = $errors;
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $itemId = $repository->createItem($gridId, $settings);
    BlockLocalization::save('feature_grid_items', $itemId, $defaultLanguage, $words);

    $db->commit();
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/create-feature-grid-item.php] ' . $e->getMessage());
    $_SESSION['admin_feature_grid_item_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
