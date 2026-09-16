<?php

/**
 * POST /api/admin/update-footer-link.php
 *
 * Updates a footer_links row from admin/footer-link.php: its labels,
 * destination and visibility. The rules are in
 * api/admin/_footer_link_input.php, shared with create-footer-link.php —
 * including keeping a route of a switched-off module that the editor left
 * as it was. The column is never changed here.
 *
 * Same guard order and PRG pattern as api/admin/update-nav-item.php,
 * including the redirect back to the editor with saved=1 for the save bar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_footer_link_input.php';

use App\Repository\FooterRepository;
use App\Repository\PageRepository;
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
$existing = $repository->findLinkById($idParam);
if ($existing === null) {
    http_response_code(404);
    exit('Footer-link niet gevonden.');
}

[$errors, $data, $old] = validateFooterLinkInput($_POST, $existing, new PageRepository());

$editorUrl = '/admin/footer-link.php?id=' . $idParam;

if ($errors !== []) {
    $_SESSION['admin_footer_link_errors'] = $errors;
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

try {
    $repository->updateLink($idParam, $data);
} catch (\Throwable $e) {
    error_log('[api/admin/update-footer-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_link_errors'] = [AdminTranslator::trans('footer.link_not_saved')];
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: ' . $editorUrl);
    exit;
}

header('Location: ' . $editorUrl . '&saved=1');
exit;
