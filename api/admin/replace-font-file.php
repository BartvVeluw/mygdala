<?php

/**
 * POST /api/admin/replace-font-file.php
 *
 * Gives one variant of a Font Library family a new file (Vervangen): the
 * weight and style stay, the new file is checked like any upload and stored
 * under a NEW generated name, and only then is the old file deleted. Pages
 * pick the new file up at once: the name changed, so no cache holds the old
 * one. Same guards and flash pattern as api/admin/add-font-files.php.
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
$upload = is_array($_FILES['font_file'] ?? null) ? $_FILES['font_file'] : ['error' => UPLOAD_ERR_NO_FILE];
$errors = FontLibrary::replaceVariant((int) $fileId, $upload);

if ($errors !== []) {
    $_SESSION['admin_font_errors'] = $errors;
    header('Location: /admin/font-family.php?id=' . $familyId . '#varianten');
    exit;
}

header('Location: /admin/font-family.php?id=' . $familyId . '&done=replaced#varianten');
exit;
