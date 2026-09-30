<?php

/**
 * POST /api/admin/linked-image-preview.php
 *
 * The picture an item of the site shows as a gallery item, for the focus
 * frame of the editor while it is being chosen (admin/assets/gallery-source.js,
 * CONTENT-BLOCKS.md "Detailsectie 2.0"): the SAME live resolution the website
 * uses (App\Service\Media\LinkedImages::resolve(), the module's own
 * linkedImages() provider), so the frame shows before a save exactly what the
 * visitor will see after it, as its small preview (`preview_path`: the Media
 * Library thumbnail where there is one, never a full-size original only to
 * fill a small frame). Writes nothing and stores no path: the gallery row
 * keeps only the kind, the id and its own focus point and zoom.
 *
 * Body: csrf_token, section (<page content_key>:<section_key>, the block list
 * the editor is on), kind (a LinkedImages kind), id.
 *
 * GUARDS. Login, then at least one block permission (ContentBlockAccess),
 * POST, CSRF; then the block list's own permission through its `pages` row, so
 * only who may edit that list asks. The kind only ever hits or misses the
 * closed list of LinkedImages; it never names a class or a table.
 *
 * NOTHING LEAKS. What a visitor cannot open (a draft, an inactive product, a
 * hidden project, a gone item, a module that is off) answers
 * {"src": "", "available": false}, exactly as the website leaves it out;
 * there is no admin-only picture here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\Csrf;
use App\Service\Media\LinkedImages;

AdminAuth::requireLoginForApi();
ContentBlockAccess::requireAnyForApi();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or missing CSRF token.']);
    exit;
}

[$pageSlug] = array_pad(explode(':', (string) ($_POST['section'] ?? ''), 2), 1, null);

if (ContentBlockAccess::pageForKeyForApi($pageSlug) === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown section.']);
    exit;
}

$kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!LinkedImages::isAvailable($kind)) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown kind.']);
    exit;
}

$image = $id === false ? null : LinkedImages::resolve($kind, (int) $id);

echo json_encode([
    'src' => $image['preview_path'] ?? '',
    'available' => $image !== null,
]);
