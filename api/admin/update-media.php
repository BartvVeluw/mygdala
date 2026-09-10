<?php

/**
 * POST /api/admin/update-media.php   (media_id, alt_text)
 *
 * Changes the one piece of metadata an editor owns: the media item's default
 * alt text.
 *
 * media.manage, not media.view. An alt text on a shared item is read by every
 * place that uses it, so rewriting it reaches beyond the screen the editor is
 * looking at — which is exactly the line this project draws between the two
 * media permissions (App\Service\AdminPermissions).
 *
 * Nothing else about a media item is editable here. The path, the dimensions,
 * the size and the checksum describe the file that is actually on disk; a
 * form that let somebody type them would only let them make the row lie.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
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

$mediaId = filter_input(INPUT_POST, 'media_id', FILTER_VALIDATE_INT);

if ($mediaId === false || $mediaId === null || $mediaId < 1) {
    http_response_code(400);
    exit('Invalid media id.');
}

try {
    $updated = (new MediaService())->updateAltText($mediaId, (string) ($_POST['alt_text'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/update-media.php] ' . $e->getMessage());

    $_SESSION['admin_media_errors'] = ['Alt-tekst kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

if (!$updated) {
    http_response_code(404);
    exit('Media not found.');
}

header('Location: /admin/media.php?id=' . $mediaId . '&saved=1');
exit;
