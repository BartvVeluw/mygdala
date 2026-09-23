<?php

/**
 * POST /api/admin/media-upload.php   (multipart/form-data: file, alt_text, name)
 *
 * Adds one image to the Media Library and answers with the resulting item as
 * JSON. Two callers, one file per request each: the "upload new" half of the
 * picker modal (admin/_media_picker.php), and the upload queue on the library
 * screen (admin/assets/media-upload.js), which sends a batch one file at a
 * time so every file gets its own answer and no request outgrows
 * post_max_size. The library screen's form without JavaScript posts to
 * api/admin/create-media.php instead, which redirects like every other admin
 * form; this one exists because both callers must stay on the page they are.
 *
 * `name` is optional: the name an editor gave the file in the queue, without
 * its extension. App\Service\Media\MediaService refuses one that could not
 * be a filename before anything is stored.
 *
 * PERMISSION. media.view rather than media.manage, deliberately: adding an
 * image is what every content editor could already do through any block's
 * own file field, and it takes nothing away from anybody. Changing and
 * deleting what is already in the shared library is the half behind
 * media.manage. See App\Service\AdminPermissions.
 *
 * All the actual validation is App\Service\Media\MediaUploader's — real
 * image header, an image extension in the name, random filename, size cap,
 * one writable folder. Nothing here trusts a filename, an extension or a
 * Content-Type.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('media.view');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => AdminTranslator::trans('validation.ongeldig_ontbrekend_beveiligingstoken_ververs_pa')]);
    exit;
}

$file = $_FILES['file'] ?? null;

if (!is_array($file)) {
    http_response_code(400);
    echo json_encode(['error' => 'Geen bestand ontvangen.']);
    exit;
}

// The kind of field the upload is for (the picker behind an image field
// sends "image", a share image "social_image"): anything else is refused,
// not stored. An unknown or missing kind means any kind the library takes,
// which is what the library screen's own queue sends.
$kind = (string) ($_POST['kind'] ?? '');
$kind = MediaType::isPickerFilter($kind) ? $kind : null;

try {
    $result = (new MediaService())->upload(
        $file,
        (string) ($_POST['alt_text'] ?? ''),
        (string) ($_POST['name'] ?? ''),
        $kind
    );
} catch (\RuntimeException $e) {
    // The uploader's own messages are Dutch and meant for an editor.
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/media-upload.php] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['error' => AdminTranslator::trans('validation.afbeelding_kon_opgeslagen_probeer_opnieuw')]);
    exit;
}

$item = $result['item'];

echo json_encode([
    'item' => [
        'id' => $item->id,
        'name' => $item->displayName(),
        'alt' => $item->altText,
        'url' => $item->publicPath(),
        'thumbnail' => $item->displayPath(),
        'kind' => $item->kind(),
        'type' => $item->typeLabel(),
        'width' => $item->width,
        'height' => $item->height,
        'missing' => !$item->fileExists(),
    ],
    // True when this exact file was already in the library and the existing
    // item was handed back instead of a second copy being written. The picker
    // says so, so an editor is not left wondering why the "new" upload has
    // somebody else's alt text.
    'reused' => $result['reused'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
