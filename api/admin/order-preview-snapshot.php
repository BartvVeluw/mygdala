<?php

/**
 * GET /api/admin/order-preview-snapshot.php?id=<snapshot_id>[&mode=download]
 *
 * Streams the COMPOSED preview of one personalization view — the picture of
 * the finished product as the customer saw it — to an authenticated admin,
 * and never exposes the storage/ filesystem path. Same shape, same guards and
 * same reasoning as api/admin/order-personalization-file.php.
 *
 * `mode=download` sends it as an attachment, which is what the "Download
 * preview" action in the CMS order screen uses; without it the file is served
 * inline so it can be opened in a tab.
 *
 * Path safety: the request names a snapshot RECORD by integer id, never a
 * file. The filename comes from the database row and is resolved through
 * PersonalizationUploadStorage::path(), which basename()s it. There is no
 * request value anywhere in the resulting path.
 *
 * Only a CLAIMED snapshot is served: findClaimedForAdmin() joins through
 * order_items, so an unclaimed draft some visitor posted a minute ago is a
 * 404 here rather than something an admin URL can enumerate.
 *
 * Read-only GET, so no CSRF token is needed — same reasoning as
 * invoice-download.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Service\AdminAuth;
use App\Service\Personalization\PersonalizationUploadStorage;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid id.');
}

try {
    $snapshot = (new PersonalizationPreviewSnapshotRepository())->findClaimedForAdmin($id);
} catch (\Throwable $e) {
    error_log('[api/admin/order-preview-snapshot.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Voorbeeld kon niet worden geladen.');
}

if ($snapshot === null) {
    http_response_code(404);
    exit('Voorbeeld niet gevonden.');
}

$path = (new PersonalizationUploadStorage())->path((string) $snapshot['stored_filename']);

if (!is_file($path)) {
    // A missing file degrades to a clean 404 for the admin — never a fatal
    // error, and never a page that leaks where files live. The order's
    // structured personalization is untouched and still renders.
    error_log('[api/admin/order-preview-snapshot.php] missing file for snapshot ' . $id);
    http_response_code(404);
    exit('Voorbeeld niet gevonden.');
}

/**
 * A filename the owner can recognise in their downloads folder: the order,
 * the product and which side of it. Built only from server-side values, and
 * stripped of anything that could break out of the quoted header.
 */
$parts = [
    'personalisatie',
    'order-' . (int) $snapshot['order_id'],
    (string) $snapshot['view_key'],
];
$name = preg_replace('/[^A-Za-z0-9._-]+/', '-', implode('-', $parts)) ?? 'personalisatie-voorbeeld';
$filename = trim($name, '-') . '.png';

$disposition = (($_GET['mode'] ?? '') === 'download') ? 'attachment' : 'inline';

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
