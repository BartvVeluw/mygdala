<?php

/**
 * GET /api/admin/media-list.php?q=<term>&page=<n>
 *
 * One page of the Media Library as JSON — what the picker modal
 * (admin/_media_picker.php) renders, and the only read endpoint the library
 * has. Returns the items, the total, and whether there is more.
 *
 * READ-ONLY, so no CSRF token: this changes nothing, and a token on a GET
 * would only invite somebody to put one in a URL. It is behind
 * media.view all the same — the library lists the names of a site's images,
 * which is not public information.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Repository\MediaRepository;
use App\Service\AdminAuth;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('media.view');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed');
}

header('Content-Type: application/json; charset=utf-8');
// A listing of this site's own media has no business in a shared cache, and
// the picker always wants what is there now.
header('Cache-Control: no-store');

$term = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

try {
    $service = new MediaService();
    $result = $service->browse($term, $page);
} catch (\Throwable $e) {
    error_log('[api/admin/media-list.php] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode(['error' => AdminTranslator::trans('validation.mediabibliotheek_kon_geladen')], JSON_UNESCAPED_SLASHES);
    exit;
}

$perPage = MediaRepository::PAGE_SIZE;

echo json_encode([
    'items' => array_map(static function (MediaItem $item): array {
        return [
            'id' => $item->id,
            'name' => $item->displayName(),
            'alt' => $item->altText,
            'url' => $item->publicPath(),
            'thumbnail' => $item->displayPath(),
            'width' => $item->width,
            'height' => $item->height,
            // The picker greys out an item whose file is gone rather than
            // rendering a broken thumbnail and pretending all is well.
            'missing' => !$item->fileExists(),
        ];
    }, $result['items']),
    'total' => $result['total'],
    'page' => $page,
    'per_page' => $perPage,
    'has_more' => $page * $perPage < $result['total'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
