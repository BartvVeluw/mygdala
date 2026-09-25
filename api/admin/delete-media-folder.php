<?php

/**
 * POST /api/admin/delete-media-folder.php   (folder_id, return_*)
 *
 * Deletes a virtual folder and NOTHING IN IT: its items go back to "Geen
 * map" (App\Service\Media\MediaFolderService::delete()). No media row, no
 * file and no reference is removed, so there is nothing to refuse and no
 * "in use" check: a folder is never used by a page, only its items are, and
 * they stay. The screen asks first in the shared confirmation dialog and
 * says exactly that.
 *
 * Answers like create-media-folder.php; afterwards the library shows "Geen
 * map", where the released items now are.
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
    $result = (new MediaFolderService())->delete($folderId);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-media-folder.php] ' . $e->getMessage());
    $result = ['ok' => false, 'released' => 0, 'error' => AdminTranslator::trans('media.folder.failed')];
}

if (!$result['ok']) {
    $_SESSION['admin_media_errors'] = [(string) $result['error']];
    header('Location: ' . media_return_url(''));
    exit;
}

$_SESSION['admin_media_notice'] = $result['released'] === 1
    ? AdminTranslator::trans('media.folder.deleted_one')
    : AdminTranslator::trans('media.folder.deleted', ['count' => $result['released']]);
header('Location: ' . media_return_url(MediaFolderService::NONE));
exit;
