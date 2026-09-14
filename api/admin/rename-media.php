<?php

/**
 * POST /api/admin/rename-media.php   (media_id, name[, ajax])
 *
 * Gives a media item a new name: the label an editor reads, searches for and
 * recognises an image by. MEDIA.md, "Bestandsnaam".
 *
 * WHY THIS CANNOT BREAK A PAGE. Every feature points at the item's id, and the
 * stored file keeps the random name App\Service\Media\MediaUploader gave it,
 * so there is no path to follow and nothing to rewrite. No file is moved. The
 * extension is not even part of what is sent: MediaService::rename() keeps
 * the item's own one, and App\Service\Media\MediaFilename refuses a name that
 * could not be a filename.
 *
 * media.manage, like update-media.php: an item's name is shown by every place
 * that lists it, so changing it reaches beyond the screen an editor is on.
 *
 * TWO ANSWERS, chosen by `ajax`, the way add-portfolio-item-images.php does
 * it: a redirect back to the item with a session flash for the form on the
 * item screen, and JSON for the rename dialog on the grid
 * (admin/assets/media-library.js), which must not reload a page that may
 * still hold an upload queue.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaService;

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

$respondJson = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$mediaId = filter_input(INPUT_POST, 'media_id', FILTER_VALIDATE_INT);

if ($mediaId === false || $mediaId === null || $mediaId < 1) {
    http_response_code(400);
    exit('Invalid media id.');
}

try {
    $result = (new MediaService())->rename($mediaId, (string) ($_POST['name'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/rename-media.php] ' . $e->getMessage());

    $message = AdminTranslator::trans('media.rename.failed');

    if ($isAjax) {
        $respondJson(500, ['ok' => false, 'error' => $message]);
    }

    $_SESSION['admin_media_errors'] = [$message];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

if ($result['reason'] === 'not_found') {
    if ($isAjax) {
        $respondJson(404, ['ok' => false, 'error' => AdminTranslator::trans('media.rename.not_found')]);
    }

    http_response_code(404);
    exit('Media not found.');
}

if (!$result['renamed']) {
    // An unusable or taken name: the editor's own input, said back with what
    // to change. Nothing was stored.
    if ($isAjax) {
        $respondJson(422, ['ok' => false, 'error' => (string) $result['message']]);
    }

    $_SESSION['admin_media_errors'] = [(string) $result['message']];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

$item = $result['item'];

if ($isAjax) {
    $respondJson(200, [
        'ok' => true,
        'item' => [
            'id' => $item->id,
            'name' => $item->displayName(),
        ],
    ]);
}

header('Location: /admin/media.php?id=' . $mediaId . '&renamed=1');
exit;
