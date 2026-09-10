<?php

/**
 * POST /api/admin/delete-media.php   (media_id)
 *
 * Removes a media item — the row, the file the library itself created, and
 * that file's generated thumbnail.
 *
 * ONLY WHEN NOTHING USES IT. App\Service\Media\MediaService::delete() asks
 * every usage provider first and refuses outright when the answer is not
 * "nowhere", or when a provider could not answer at all. There is no force
 * flag and no "delete everywhere" in V1: a button that silently blanks the
 * logo of a site and the images of four pages is not a feature.
 *
 * A refusal comes back as the list of places that use it, so the editor can
 * go and unpick them.
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
    $result = (new MediaService())->delete($mediaId);
} catch (\RuntimeException $e) {
    // "I could not find out where this is used" — never rounded down to
    // "nothing uses it".
    $_SESSION['admin_media_errors'] = [$e->getMessage()];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/delete-media.php] ' . $e->getMessage());

    $_SESSION['admin_media_errors'] = ['Afbeelding kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

if ($result['reason'] === 'not_found') {
    http_response_code(404);
    exit('Media not found.');
}

if ($result['reason'] === 'in_use') {
    $_SESSION['admin_media_errors'] = [
        'Deze afbeelding wordt nog gebruikt en is daarom niet verwijderd. Haal hem eerst weg op de plekken hieronder.',
    ];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

if ($result['warning'] !== null) {
    // The row is gone but the file is not. Said out loud rather than hidden:
    // somebody has to go and remove it, and pretending otherwise would leave
    // the site quietly wrong.
    $_SESSION['admin_media_errors'] = [$result['warning']];
    header('Location: /admin/media.php');
    exit;
}

header('Location: /admin/media.php?deleted=1');
exit;
