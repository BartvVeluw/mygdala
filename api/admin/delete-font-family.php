<?php

/**
 * POST /api/admin/delete-font-family.php
 *
 * Deletes a Font Library family and every file of it, only when nothing
 * uses it. A family the website (headings or body text) or a page theme
 * uses is refused with the plain-language list of who uses it
 * (App\Service\Theme\FontLibrary::usageSentence()); the foreign keys are
 * RESTRICT, so the database refuses as well. There is no silent switch to
 * another font as a side effect of tidying up.
 *
 * `from` says where the button was: `editor` goes back to the family's
 * editor with a refusal, anything else to the overview on Vormgeving,
 * tab Lettertypen. Same guards and PRG pattern as
 * api/admin/delete-color-palette.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
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

$fromEditor = ($_POST['from'] ?? '') === 'editor';

try {
    $result = FontLibrary::deleteFamily($id);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-font-family.php] ' . $e->getMessage());
    $result = ['deleted' => false, 'reason' => null];
}

if ($result['deleted']) {
    header('Location: /admin/theme.php?fonts=deleted#lettertypen');
    exit;
}

$reason = $result['reason'] ?? AdminTranslator::trans('fonts.error_delete');
if ($fromEditor) {
    $_SESSION['admin_font_errors'] = [$reason];
    header('Location: /admin/font-family.php?id=' . $id);
    exit;
}

$_SESSION['admin_fonts_error'] = $reason;
header('Location: /admin/theme.php#lettertypen');
exit;
