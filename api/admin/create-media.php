<?php

/**
 * POST /api/admin/create-media.php   (multipart/form-data: files[], or image for one file)
 *
 * The Media Library screen's upload form when JavaScript does not run. The
 * same work as api/admin/media-upload.php — which the upload queue and the
 * picker use, one file per request — but for every file the form carried at
 * once, answered with a redirect back to admin/media.php and a session flash
 * like every other admin form in this project.
 *
 * ONE REFUSED FILE DOES NOT STOP THE REST. Every file becomes an item of its
 * own (App\Service\Media\MediaService::uploadMany()), so a selection with an
 * .exe in it adds the images and says which file it refused and why. Nothing
 * about adding one image depends on the next, and a refused file leaves
 * nothing behind.
 *
 * A browser that sends more at once than post_max_size allows arrives with no
 * files and no token at all, and is stopped by the CSRF check below. The
 * screen states the size limit, and the script-driven queue never puts more
 * than one file in a request.
 *
 * media.view, not media.manage: adding an image is additive and is what every
 * content editor could already do through a block's own upload field. See
 * App\Service\AdminPermissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaService;
use App\Service\Media\MediaUploader;

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

// `files[]` is the library screen's field; `image` still takes the single
// file this endpoint used to receive.
$files = MediaUploader::filesFrom($_FILES['files'] ?? $_FILES['image'] ?? null);

if ($files === []) {
    $_SESSION['admin_media_errors'] = [AdminTranslator::trans('media.upload.nothing_chosen')];
    header('Location: /admin/media.php');
    exit;
}

$results = (new MediaService())->uploadMany($files);

$added = array_values(array_filter($results, static fn (array $result): bool => $result['item'] !== null));
$refused = array_values(array_filter($results, static fn (array $result): bool => $result['item'] === null));

// One file, and it made it: land on the item, as this form always did. The
// next thing to do with a new image is to write its alt text, and that is
// where the field is. `reused` says the file was already in the library
// rather than silently opening somebody else's item.
if (count($results) === 1 && $added !== []) {
    header('Location: /admin/media.php?id=' . $added[0]['item']->id . ($added[0]['reused'] ? '&reused=1' : '&saved=1'));
    exit;
}

if ($added !== []) {
    $_SESSION['admin_media_notice'] = count($added) === 1
        ? AdminTranslator::trans('media.upload.done_one')
        : AdminTranslator::trans('media.upload.done', ['count' => count($added)]);
}

if ($refused !== []) {
    $_SESSION['admin_media_errors'] = array_map(
        static fn (array $result): string => AdminTranslator::trans('media.upload.refused_file', [
            'name' => $result['name'],
            'reason' => (string) $result['error'],
        ]),
        $refused
    );
}

header('Location: /admin/media.php');
exit;
