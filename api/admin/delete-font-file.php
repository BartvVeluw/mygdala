<?php

/**
 * POST /api/admin/delete-font-file.php
 *
 * Removes one variant of a Font Library family, and its file. Refused for
 * the last variant of a family the website or a page theme uses (that would
 * leave them pointing at a family without a file), with a message that says
 * who uses it (App\Service\Theme\FontLibrary::removeVariant()). Same guards
 * and flash pattern as api/admin/delete-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\FontLibraryRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Theme\FontLibrary;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$fileId = filter_var($_POST['file_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$file = $fileId === false ? null : (new FontLibraryRepository())->file($fileId);
if ($file === null) {
    http_response_code(404);
    exit('Font file not found.');
}

$familyId = (int) $file['font_family_id'];
$refused = FontLibrary::removeVariant((int) $fileId);

if ($refused !== null) {
    $_SESSION['admin_font_errors'] = [$refused];
    header('Location: /admin/font-family.php?id=' . $familyId . '#varianten');
    exit;
}

header('Location: /admin/font-family.php?id=' . $familyId . '&done=variant_deleted#varianten');
exit;
