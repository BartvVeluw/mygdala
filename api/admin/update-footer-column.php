<?php

/**
 * POST /api/admin/update-footer-column.php
 *
 * Saves admin/footer-column.php: the column's title in ONE website language
 * (`language_code`, which must be an active website language; every other
 * language's title stays as it is, App\Service\FooterLocalization) and
 * whether it is shown. The title is required only in the default language.
 * Hiding a column keeps every link in it; showing it again brings them back.
 *
 * Same guard order and PRG pattern as api/admin/update-nav-item.php,
 * including the redirect back to the editor with saved=1 for the save bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FooterLocalization;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

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

$idParam = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($idParam === false || $idParam === null || $idParam < 1) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$db = Database::connection();
$repository = new FooterRepository($db);
if ($repository->findColumnById($idParam) === null) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$title = trim((string) ($_POST['title'] ?? ''));
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$isVisible = isset($_POST['is_visible']);

$errors = [];
if ($languageCode === '' || !SiteLanguages::isActive($languageCode)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    $problems = FooterLocalization::columns()->problems($languageCode, [FooterLocalization::TITLE => $title], [FooterLocalization::TITLE]);
    if (($problems[FooterLocalization::TITLE] ?? null) === 'missing') {
        $errors[] = AdminTranslator::trans('validation.titel_verplicht');
    }
    if (($problems[FooterLocalization::TITLE] ?? null) === 'too_long') {
        $errors[] = AdminTranslator::trans('validation.titel_mag_maximaal_100_tekens');
    }
}

$editorUrl = '/admin/footer-column.php?id=' . $idParam;
$old = ['language_code' => $languageCode, 'title' => $title, 'is_visible' => $isVisible];

if ($errors !== []) {
    $_SESSION['admin_footer_column_errors'] = $errors;
    $_SESSION['admin_footer_column_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

try {
    $db->beginTransaction();
    $repository->updateColumn($idParam, ['is_visible' => $isVisible]);
    FooterLocalization::saveColumnTitle($idParam, $languageCode, $title);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/update-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_column_errors'] = [AdminTranslator::trans('footer.column_not_saved')];
    $_SESSION['admin_footer_column_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

header('Location: ' . $editorUrl . '&saved=1');
exit;
