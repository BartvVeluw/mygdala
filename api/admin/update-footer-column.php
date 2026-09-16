<?php

/**
 * POST /api/admin/update-footer-column.php
 *
 * Saves admin/footer-column.php: the column's title per language and whether
 * it is shown. Only the site's own language is required (MULTILINGUAL.md).
 * Hiding a column keeps every link in it; showing it again brings them back.
 *
 * Same guard order and PRG pattern as api/admin/update-nav-item.php,
 * including the redirect back to the editor with saved=1 for the save bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Language\LocalizedValue;

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

$repository = new FooterRepository();
if ($repository->findColumnById($idParam) === null) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$titleNl = trim((string) ($_POST['title_nl'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));
$isVisible = isset($_POST['is_visible']);

$errors = [];
if (LocalizedValue::ofDutchEnglish($titleNl, $titleEn)->primaryValue() === '') {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
}
if (mb_strlen($titleNl) > 100 || mb_strlen($titleEn) > 100) {
    $errors[] = AdminTranslator::trans('validation.titel_mag_maximaal_100_tekens');
}

$editorUrl = '/admin/footer-column.php?id=' . $idParam;
$old = ['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => $isVisible];

if ($errors !== []) {
    $_SESSION['admin_footer_column_errors'] = $errors;
    $_SESSION['admin_footer_column_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

try {
    $repository->updateColumn($idParam, $old);
} catch (\Throwable $e) {
    error_log('[api/admin/update-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_column_errors'] = [AdminTranslator::trans('footer.column_not_saved')];
    $_SESSION['admin_footer_column_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

header('Location: ' . $editorUrl . '&saved=1');
exit;
