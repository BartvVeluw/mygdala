<?php

/**
 * POST /api/admin/update-footer-column.php — admin/footer-column.php's edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\LocalizedValue;
use App\Repository\FooterRepository;

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
// Only the SITE'S OWN language is required. The other one is a translation,
// and a translation is optional by definition: App\Service\Language\LocalizedValue
// falls back to the primary language wherever one is missing. Requiring both
// was harmless while every editor printed both fields; now that a
// single-language site shows one, it would make this form impossible to
// submit at all (MULTILINGUAL.md).
if (LocalizedValue::ofDutchEnglish($titleNl, $titleEn)->primaryValue() === '') {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
}
if (mb_strlen($titleNl) > 100 || mb_strlen($titleEn) > 100) {
    $errors[] = AdminTranslator::trans('validation.titel_mag_maximaal_100_tekens');
}

if ($errors !== []) {
    $_SESSION['admin_footer_column_errors'] = $errors;
    $_SESSION['admin_footer_column_old'] = ['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => $isVisible];
    header('Location: /admin/footer-column.php?id=' . $idParam);
    exit;
}

try {
    $repository->updateColumn($idParam, ['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => $isVisible]);
} catch (\Throwable $e) {
    error_log('[api/admin/update-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_column_errors'] = ['Kolom kon niet worden opgeslagen.'];
    header('Location: /admin/footer-column.php?id=' . $idParam);
    exit;
}

header('Location: /admin/footer.php?saved=1');
exit;
