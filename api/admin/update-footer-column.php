<?php

/**
 * POST /api/admin/update-footer-column.php — admin/footer-column.php's edit form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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
if ($titleNl === '' || mb_strlen($titleNl) > 100) {
    $errors[] = 'Titel (NL) is verplicht (max. 100 tekens).';
}
if ($titleEn === '' || mb_strlen($titleEn) > 100) {
    $errors[] = 'Titel (EN) is verplicht (max. 100 tekens).';
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
