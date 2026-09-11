<?php

/**
 * POST /api/admin/media-upload.php   (multipart/form-data: file, alt_text)
 *
 * Adds one image to the Media Library and answers with the resulting item as
 * JSON — the "upload new" half of the picker modal
 * (admin/_media_picker.php). The library screen's own upload form posts to
 * api/admin/create-media.php instead, which redirects like every other admin
 * form; this one exists because the picker must stay on the page the editor
 * is editing.
 *
 * PERMISSION. media.view rather than media.manage, deliberately: adding an
 * image is what every content editor could already do through any block's
 * own file field, and it takes nothing away from anybody. Changing and
 * deleting what is already in the shared library is the half behind
 * media.manage. See App\Service\AdminPermissions.
 *
 * All the actual validation is App\Service\Media\MediaUploader's — real
 * image header, random filename, size cap, one writable folder. Nothing here
 * trusts a filename, an extension or a Content-Type.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Media\MediaService;

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

try {
    $result = (new MediaService())->upload($file, (string) ($_POST['alt_text'] ?? ''));
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
