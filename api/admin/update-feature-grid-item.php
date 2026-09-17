<?php

/**
 * POST /api/admin/update-feature-grid-item.php
 *
 * Edits one Feature grid card's content, icon and visibility.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title and text are the words of
 * the language named in `language_code`, which must be an active language of
 * the website registry, and are required only in the default language
 * (FeatureGridBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch words never removes an English or German translation. The
 * card keeps its id; its icon and is_active are the same in every language
 * and are saved in the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
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

$itemId = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
if ($itemId === false || $itemId === null || $itemId < 1) {
    http_response_code(400);
    exit('Invalid item id.');
}

$repository = new FeatureGridRepository();
$item = $repository->findItemById($itemId);

if ($item === null) {
    http_response_code(404);
    exit('Item not found.');
}

$grid = $repository->findById((int) $item['feature_grid_id']);
if ($grid === null) {
    http_response_code(404);
    exit('Grid not found.');
}

$sectionKey = $grid['page_slug'] . ':' . $grid['section_key'];

$iconKey = is_string($_POST['icon_key'] ?? null) ? $_POST['icon_key'] : '';
$settings = [
    'icon_key' => array_key_exists($iconKey, FeatureGridContent::ICON_KEYS) ? $iconKey : array_key_first(FeatureGridContent::ICON_KEYS),
    'is_active' => isset($_POST['is_active']),
];

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('feature_grid_items')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('feature_grid_items', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_feature_grid_item_errors'] = $errors;
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updateItem($itemId, $settings);
    BlockLocalization::save('feature_grid_items', $itemId, $languageCode, $words);

    $db->commit();
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-feature-grid-item.php] ' . $e->getMessage());
    $_SESSION['admin_feature_grid_item_errors'] = ['Kaart kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey));
    exit;
}

header('Location: /admin/feature-grid.php?section=' . urlencode($sectionKey) . '&saved=1');
exit;
