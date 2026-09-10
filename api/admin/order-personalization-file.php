<?php

/**
 * GET /api/admin/order-personalization-file.php?id=<order_item_personalization_id>[&variant=preview][&mode=download]
 *
 * The ONLY way to read a customer's personalization upload. Streams it to an
 * authenticated admin and never exposes the storage/ filesystem path itself —
 * exactly like api/admin/invoice-download.php and
 * api/admin/contact-request-attachment.php.
 *
 * `variant=original` (the default) serves the untouched file the customer
 * uploaded — the one that is actually useful for production. `variant=preview`
 * serves the downscaled re-encoded copy, which is what the CMS order page
 * embeds in its reconstructed preview.
 *
 * Path safety: the request names a personalization RECORD by integer id, never
 * a file. The filename comes from the database row and is resolved through
 * PersonalizationUploadStorage::path(), which basename()s it. `variant` is
 * matched against a two-value allow-list. There is therefore no request value
 * anywhere in the resulting path, and no traversal component can survive.
 *
 * Read-only GET, so no CSRF token is needed (nothing is mutated) — same
 * reasoning as invoice-download.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\OrderItemPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\Personalization\PersonalizationUploadStorage;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid id.');
}

$variant = (($_GET['variant'] ?? 'original') === 'preview') ? 'preview' : 'original';

try {
    $record = (new OrderItemPersonalizationRepository())->findWithUploadForAdmin($id);
} catch (\Throwable $e) {
    error_log('[api/admin/order-personalization-file.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Bestand kon niet worden geladen.');
}

if ($record === null || $record['upload_id'] === null) {
    http_response_code(404);
    exit('Bestand niet gevonden.');
}

$storedFilename = (string) ($variant === 'preview' ? $record['preview_filename'] : $record['stored_filename']);

if ($storedFilename === '') {
    http_response_code(404);
    exit('Bestand niet gevonden.');
}

$path = (new PersonalizationUploadStorage())->path($storedFilename);

if (!is_file($path)) {
    // A missing file must degrade to a clean 404 for the admin, never a
    // fatal error or a page that leaks where files live.
    error_log('[api/admin/order-personalization-file.php] missing file for personalization ' . $id);
    http_response_code(404);
    exit('Bestand niet gevonden.');
}

$mime = in_array((string) $record['mime_type'], ['image/png', 'image/jpeg'], true)
    ? (string) $record['mime_type']
    : 'application/octet-stream';

// Strip characters that could break out of the quoted filename in the
// Content-Disposition header; the original filename was already stripped of
// control characters and path components at upload time.
$safeFilename = str_replace(['"', '\\', "\r", "\n"], '', (string) $record['original_filename']);
if ($safeFilename === '') {
    $safeFilename = 'personalisatie-' . $id;
}

$disposition = (($_GET['mode'] ?? '') === 'download') ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
