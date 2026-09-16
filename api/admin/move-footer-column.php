<?php

/**
 * POST /api/admin/move-footer-column.php
 *
 * Moves a footer column one place up or down (FooterRepository::moveColumn()).
 * The same one-step-at-a-time pattern as api/admin/move-nav-item.php: two
 * buttons, no JavaScript, and it works with a keyboard and on a phone. The
 * drag-and-drop list and reorder-footer-columns.php stay for a mouse; the
 * stored sort_order decides either way.
 *
 * Only the id and the direction come from the request.
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

$direction = (string) ($_POST['direction'] ?? '');
if (!in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit('Invalid direction.');
}

$repository = new FooterRepository();

if ($repository->findColumnById($idParam) === null) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

try {
    $repository->moveColumn($idParam, $direction);
} catch (\Throwable $e) {
    error_log('[api/admin/move-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('validation.volgorde_kon_opgeslagen');
}

// Back to the column that moved, so the editor keeps their place.
header('Location: /admin/footer.php#footer-column-' . $idParam);
exit;
