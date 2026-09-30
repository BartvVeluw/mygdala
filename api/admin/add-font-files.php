<?php

/**
 * POST /api/admin/add-font-files.php
 *
 * Adds variants to an existing Font Library family: font_files[] plus the
 * variant picked for each (variants[]). All or nothing, like a new family;
 * a variant the family already has is refused with the advice to use
 * Vervangen on it (api/admin/replace-font-file.php), so a file is never
 * swapped by accident. Same guards and flash pattern as
 * api/admin/save-font-family.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || FontLibrary::family($id) === null) {
    http_response_code(404);
    exit('Font family not found.');
}

$errors = FontLibrary::addVariants(
    $id,
    FontLibrary::uploadsFromRequest($_FILES['font_files'] ?? null, $_POST['variants'] ?? null)
);

if ($errors !== []) {
    $_SESSION['admin_font_errors'] = $errors;
    header('Location: /admin/font-family.php?id=' . $id . '#varianten');
    exit;
}

header('Location: /admin/font-family.php?id=' . $id . '&done=added#varianten');
exit;
