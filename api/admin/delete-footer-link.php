<?php

/**
 * POST /api/admin/delete-footer-link.php
 *
 * Deletes one footer link. Asked first in the CMS's own dialog on
 * admin/footer.php and admin/footer-link.php (admin_confirm_attributes());
 * that dialog is a courtesy, never the guard. Back to the column it was in.
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
    exit('Footer-link niet gevonden.');
}

$repository = new FooterRepository();
$link = $repository->findLinkById($idParam);

if ($link === null) {
    http_response_code(404);
    exit('Footer-link niet gevonden.');
}

$columnAnchor = '#footer-column-' . (int) $link['column_id'];

try {
    $repository->deleteLink($idParam);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-footer-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = AdminTranslator::trans('footer.link_not_deleted');
    header('Location: /admin/footer.php' . $columnAnchor);
    exit;
}

header('Location: /admin/footer.php?deleted=link' . $columnAnchor);
exit;
