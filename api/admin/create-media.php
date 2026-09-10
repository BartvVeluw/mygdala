<?php

/**
 * POST /api/admin/create-media.php   (multipart/form-data: image, alt_text)
 *
 * The Media Library screen's own upload form. Same work as
 * api/admin/media-upload.php, which the picker uses, but this one redirects
 * back to admin/media.php with a session flash like every other admin form in
 * this project — so the library screen keeps working with JavaScript off.
 *
 * media.view, not media.manage: adding an image is additive and is what every
 * content editor could already do through a block's own upload field. See
 * App\Service\AdminPermissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('media.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$file = $_FILES['image'] ?? null;

if (!is_array($file)) {
    $_SESSION['admin_media_errors'] = ['Geen bestand ontvangen.'];
    header('Location: /admin/media.php');
    exit;
}

try {
    $result = (new MediaService())->upload($file, (string) ($_POST['alt_text'] ?? ''));
} catch (\RuntimeException $e) {
    $_SESSION['admin_media_errors'] = [$e->getMessage()];
    header('Location: /admin/media.php');
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/create-media.php] ' . $e->getMessage());

    $_SESSION['admin_media_errors'] = ['Afbeelding kon niet worden opgeslagen. Probeer het opnieuw.'];
    header('Location: /admin/media.php');
    exit;
}

// Landing on the item itself rather than back on the grid: the next thing an
// editor wants after uploading is to write the alt text, and that is where
// the field is. `reused` is carried through so the screen can say the file
// was already in the library instead of silently opening somebody else's item.
header('Location: /admin/media.php?id=' . $result['item']->id . ($result['reused'] ? '&reused=1' : '&saved=1'));
exit;
