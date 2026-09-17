<?php

/**
 * POST /api/admin/update-detail-section-point.php
 *
 * Saves one "kenmerk" (point) card of a Detailsectie.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the title and body are the words
 * of the language named in `language_code`, which must be an active language
 * of the website registry, and are required only in the default language
 * (DetailSectionBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization). Only that language is written, so
 * saving the Dutch words never removes an English or German translation. The
 * point keeps its id; is_active is the same in every language and is saved
 * in the same transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\DetailSectionContent;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Repository\DetailSectionRepository;

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

$pointId = filter_input(INPUT_POST, 'point_id', FILTER_VALIDATE_INT);
if ($pointId === false || $pointId === null || $pointId < 1) {
    http_response_code(400);
    exit('Invalid point id.');
}

$repository = new DetailSectionRepository();
$point = $repository->findPointById($pointId);

if ($point === null) {
    http_response_code(404);
    exit('Point not found.');
}

$section = $repository->findById((int) $point['section_id']);
if ($section === null) {
    http_response_code(404);
    exit('Section not found.');
}

$redirect = '/admin/detail-section.php?section=' . urlencode((string) $section['page_slug'] . ':' . (string) $section['section_key']);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('detail_section_points')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('detail_section_points', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

if ($errors !== []) {
    $_SESSION['admin_detail_section_point_errors'] = $errors;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    $repository->updatePoint($pointId, ['is_active' => isset($_POST['is_active'])]);
    BlockLocalization::save('detail_section_points', $pointId, $languageCode, $words);

    $db->commit();
    DetailSectionContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-detail-section-point.php] ' . $e->getMessage());
    $_SESSION['admin_detail_section_point_errors'] = ['Kenmerk kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
