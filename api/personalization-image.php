<?php

/**
 * GET /api/personalization-image.php?token=<32 hex>
 *
 * Streams the PREVIEW copy of one customer personalization upload — the
 * downscaled, GD re-encoded image (see
 * App\Service\Personalization\PersonalizationUploadValidator), never the
 * original file. It exists so the customer can see their own upload in the
 * live product preview, in the cart and in the checkout summary.
 *
 * Security model:
 *   - the ONLY handle is the upload's random 32-hex token, which is checked
 *     for shape before any database or filesystem access, so nothing
 *     path-like can ever reach a filename;
 *   - the filename served comes from the database row, and is resolved
 *     through PersonalizationUploadStorage::path(), which basename()s it —
 *     the request can never name a file;
 *   - the ORIGINAL upload is deliberately NOT reachable here. It is order
 *     data and is only ever served by the authenticated admin endpoint
 *     api/admin/order-personalization-file.php;
 *   - the response is served with a fixed image content type from the
 *     database plus `nosniff`, so a stored file can never be interpreted as
 *     anything but an image.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('personalization');


use App\Repository\PersonalizationUploadRepository;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationUploadStorage;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$token = $_GET['token'] ?? null;

if (!PersonalizationRules::isValidUploadToken($token)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid token.');
}

try {
    $upload = (new PersonalizationUploadRepository())->findByToken((string) $token);
} catch (\Throwable $e) {
    error_log('[api/personalization-image.php] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image unavailable.');
}

if ($upload === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image not found.');
}

$path = (new PersonalizationUploadStorage())->path((string) $upload['preview_filename']);

if (!is_file($path)) {
    // A missing file must fail as a plain 404, never as a stack trace or a
    // page that reveals where files live.
    error_log('[api/personalization-image.php] missing preview file for upload ' . (int) $upload['id']);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image not found.');
}

// Only the two content types this feature can ever produce, taken from the
// stored row rather than from anything in the request.
$mime = in_array((string) $upload['mime_type'], ['image/png', 'image/jpeg'], true)
    ? (string) $upload['mime_type']
    : 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=600');
readfile($path);
exit;
