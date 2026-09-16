<?php

/**
 * POST /api/admin/toggle-footer-column.php
 *
 * Verbergen/Tonen on a column of admin/footer.php. Hiding takes the column
 * and its links off the website and keeps all of them here; showing it again
 * brings back exactly what was there. Back to the same row.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FooterRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

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

$isVisible = ($_POST['is_visible'] ?? '0') === '1';

try {
    (new FooterRepository())->setColumnVisible($idParam, $isVisible);
} catch (\Throwable $e) {
    error_log('[api/admin/toggle-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.zichtbaarheid_kon_opgeslagen');
}

header('Location: /admin/footer.php#footer-column-' . $idParam);
exit;
