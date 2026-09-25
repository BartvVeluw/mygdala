<?php

/**
 * POST /api/admin/rename-media-folder.php   (folder_id, name, return_*)
 *
 * Gives a virtual folder a new name. Only media_folders.name changes: no
 * item, no file and no URL follows a folder name, because none of them
 * contains it (MEDIA.md, "Mappen"). The same checks as a new folder, through
 * App\Service\Media\MediaFolderService, and the same answer as
 * create-media-folder.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_media_return.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaFolderService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('media.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$folderId = filter_input(INPUT_POST, 'folder_id', FILTER_VALIDATE_INT);

if ($folderId === false || $folderId === null || $folderId < 1) {
    http_response_code(400);
    exit('Invalid folder id.');
}

try {
    $result = (new MediaFolderService())->rename($folderId, (string) ($_POST['name'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/rename-media-folder.php] ' . $e->getMessage());
    $result = ['ok' => false, 'error' => AdminTranslator::trans('media.folder.failed'), 'reason' => 'failed'];
}

if (!$result['ok']) {
    $_SESSION['admin_media_errors'] = [(string) $result['error']];
    header('Location: ' . media_return_url());
    exit;
}

$_SESSION['admin_media_notice'] = AdminTranslator::trans('media.folder.renamed');
header('Location: ' . media_return_url((string) $folderId));
exit;
