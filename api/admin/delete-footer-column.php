<?php

/**
 * POST /api/admin/delete-footer-column.php — deletes the column and, via
 * the footer_links.column_id FK's ON DELETE CASCADE, every link in it. See
 * db/migrations/20260907220000_create_footer_tables.php for why this is
 * safe (unlike nav_items' parent/child, a footer link never makes sense
 * detached from its column, so there is no "move first" step to force).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
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

try {
    (new FooterRepository())->deleteColumn($idParam);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.kolom_kon_verwijderd');
    header('Location: /admin/footer.php');
    exit;
}

header('Location: /admin/footer.php?deleted=1');
exit;
