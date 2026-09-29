<?php

/**
 * GET /api/admin/order-field-upload.php?id=<upload id>[&variant=thumb][&mode=download]
 *
 * The ONLY way to read a customer's picture for an "Afbeelding uploaden"
 * order question (Shop Admin UX & Order Fields 2.0), from the order screen:
 * the thumbnail it shows, the picture itself to look at, and a download.
 * The same shape as api/admin/order-personalization-file.php.
 *
 * WHO: a logged-in account with orders.view, checked on every request.
 * WHAT: only a CLAIMED upload — one that belongs to an order line. A
 * temporary upload of a visitor who has not ordered has no route at all.
 *
 * PATH SAFETY: the request names an upload RECORD by integer id, never a
 * file. The filename is rebuilt from the row's random storage name and its
 * extension (OrderFieldUploadStorage::path(), which accepts nothing else),
 * and `variant` is a two-value allow-list, so no request value reaches the
 * path.
 *
 * RESPONSE: the stored MIME type only when it is one of the three raster
 * types the upload was verified as (else octet-stream), nosniff, private and
 * never cached, a sandboxing CSP, and the customer's filename stripped of
 * anything that could break the header. `mode=download` is an attachment,
 * otherwise the picture opens inline — safe for a verified JPEG, PNG or WebP.
 *
 * A read-only GET, so no CSRF token (nothing changes), like
 * api/admin/invoice-download.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
\App\Module\ModuleGuard::requireApi('shop');

use App\Repository\OrderFieldUploadRepository;
use App\Service\AdminAuth;
use App\Service\OrderFields\OrderFieldUploadPolicy;
use App\Service\OrderFields\OrderFieldUploadStorage;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    exit('Invalid id.');
}

$kind = (($_GET['variant'] ?? '') === 'thumb') ? OrderFieldUploadStorage::THUMBNAIL : OrderFieldUploadStorage::ORIGINAL;
$download = ($_GET['mode'] ?? '') === 'download' && $kind === OrderFieldUploadStorage::ORIGINAL;

try {
    $upload = (new OrderFieldUploadRepository())->findClaimedForAdmin($id);
} catch (\Throwable $e) {
    error_log('[api/admin/order-field-upload.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Bestand kon niet worden geladen.');
}

if ($upload === null) {
    http_response_code(404);
    exit('Bestand niet gevonden.');
}

$path = (new OrderFieldUploadStorage())->path((string) $upload['storage_name'], (string) $upload['extension'], $kind);
if ($path === null || !is_file($path)) {
    error_log('[api/admin/order-field-upload.php] missing file for upload ' . $id);
    http_response_code(404);
    exit('Bestand niet gevonden.');
}

$mime = in_array((string) $upload['mime_type'], array_column(OrderFieldUploadPolicy::FORMATS, 1), true)
    ? (string) $upload['mime_type']
    : 'application/octet-stream';

$filename = str_replace(['"', '\\', '/', "\r", "\n", ';'], '', (string) $upload['original_filename']);
if ($filename === '') {
    $filename = 'afbeelding-' . $id . '.' . $upload['extension'];
}
$asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
    . '; filename="' . $asciiFilename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('Referrer-Policy: no-referrer');
readfile($path);
exit;
