<?php

/**
 * POST /api/admin/duplicate-page-theme.php
 *
 * Copies one page theme as "<name> (kopie)" with its own unique name and
 * slug (App\Service\PageThemes\PageThemeService::duplicate()), then opens the
 * copy in the editor, where it usually gets a new name. No page uses the copy
 * yet. Same guards and PRG pattern as api/admin/save-page-theme.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageThemes\PageThemeService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('page_themes.manage');

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
$copyId = $id === false ? null : PageThemeService::duplicate($id);

if ($copyId === null) {
    http_response_code(404);
    exit('Page theme not found.');
}

header('Location: /admin/page-theme.php?id=' . $copyId . '&duplicated=1');
exit;
