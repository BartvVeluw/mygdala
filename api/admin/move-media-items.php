<?php

/**
 * POST /api/admin/move-media-items.php   (media_ids[], target_folder, return_*[, ajax])
 *
 * Files a selection of media items under a virtual folder, or under none
 * (target_folder "none"). Only media.folder_id changes
 * (App\Service\Media\MediaFolderService::move()): no file moves, no path, no
 * URL and no media_id reference changes, so no page can notice.
 *
 * A folder id that does not exist is refused, never created and never read
 * as "no folder". The selection is capped like a delete
 * (MediaFolderService::MAX_MOVE_AT_ONCE).
 *
 * TWO ANSWERS, like delete-media-items.php, whose form this shares on the
 * grid (the button carries formaction): a redirect back to the grid with a
 * session flash, or JSON when media-library.js sends it.
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

$isAjax = ($_POST['ajax'] ?? '') === '1';
$submitted = $_POST['media_ids'] ?? [];
$target = (string) ($_POST['target_folder'] ?? '');

try {
    $result = $target === ''
        ? ['ok' => false, 'moved' => 0, 'error' => AdminTranslator::trans('media.folder.move_choose')]
        : (new MediaFolderService())->move(is_array($submitted) ? array_values($submitted) : [], $target);
} catch (\Throwable $e) {
    error_log('[api/admin/move-media-items.php] ' . $e->getMessage());
    $result = ['ok' => false, 'moved' => 0, 'error' => AdminTranslator::trans('media.folder.failed')];
}

$message = $result['ok']
    ? ($result['moved'] === 1 ? AdminTranslator::trans('media.folder.moved_one') : AdminTranslator::trans('media.folder.moved', ['count' => $result['moved']]))
    : (string) $result['error'];

if ($isAjax) {
    http_response_code($result['ok'] ? 200 : 422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => $result['ok'], 'message' => $message, 'moved' => $result['moved']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$_SESSION[$result['ok'] ? 'admin_media_notice' : 'admin_media_errors'] = $result['ok'] ? $message : [$message];
header('Location: ' . media_return_url());
exit;
