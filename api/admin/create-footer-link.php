<?php

/**
 * POST /api/admin/create-footer-link.php
 *
 * Creates a footer_links row from admin/footer-link.php, at the end of its
 * column. The rules are in api/admin/_footer_link_input.php, shared with
 * update-footer-link.php, and they are the footer's copy of the menu's
 * (api/admin/_nav_item_input.php).
 *
 * Same guard order and PRG/session-flash pattern as
 * api/admin/create-nav-item.php; a successful save lands on the new link's
 * own editor with saved=1, so the save bar there can say it was saved.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_footer_link_input.php';

use App\Database;
use App\Repository\FooterRepository;
use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\FooterLocalization;
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

$db = Database::connection();
$repository = new FooterRepository($db);

$column = footerLinkTargetColumn($_POST, $repository);
if ($column === null) {
    http_response_code(404);
    exit('Footer-kolom niet gevonden.');
}

$columnId = (int) $column['id'];
$formUrl = '/admin/footer-link.php?column_id=' . $columnId;

[$errors, $data, $old] = validateFooterLinkInput($_POST, null, new PageRepository());

if ($errors !== []) {
    $_SESSION['admin_footer_link_errors'] = $errors;
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: ' . $formUrl);
    exit;
}

try {
    // The row and its label in the default language are one save.
    $db->beginTransaction();
    $id = $repository->createLink(['column_id' => $columnId] + $data);
    FooterLocalization::saveLinkLabel($id, $data['language_code'], $data['label']);
    $db->commit();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[api/admin/create-footer-link.php] ' . $e->getMessage());
    $_SESSION['admin_footer_link_errors'] = [AdminTranslator::trans('footer.link_not_created')];
    $_SESSION['admin_footer_link_old'] = $old;
    header('Location: ' . $formUrl);
    exit;
}

header('Location: /admin/footer-link.php?id=' . $id . '&saved=1');
exit;
