<?php

/**
 * POST /api/admin/create-media-folder.php   (name, return_q, return_type)
 *
 * Makes a virtual folder in the Media Library (MEDIA.md, "Mappen"). A folder
 * is a row with a name: nothing is created on disk, and the name never
 * becomes a path. App\Service\Media\MediaFolderService checks the name
 * (empty, invisible characters, too long, already taken).
 *
 * The same guards and answer as delete-media-items.php: a redirect back to
 * the library with a session flash. A new folder is opened straight away, so
 * the next upload lands in it; a refused name goes back to where the editor
 * was, with the reason.
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

try {
    $result = (new MediaFolderService())->create((string) ($_POST['name'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/create-media-folder.php] ' . $e->getMessage());
    $result = ['ok' => false, 'error' => AdminTranslator::trans('media.folder.failed'), 'id' => null];
}

if (!$result['ok']) {
    $_SESSION['admin_media_errors'] = [(string) $result['error']];
    header('Location: ' . media_return_url());
    exit;
}

$_SESSION['admin_media_notice'] = AdminTranslator::trans('media.folder.created');
header('Location: ' . media_return_url((string) $result['id']));
exit;
