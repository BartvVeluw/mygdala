<?php

/**
 * POST /api/admin/delete-page-theme.php
 *
 * Deletes a page theme that no page uses. A theme in use is NOT deleted: the
 * overview (admin/page-themes.php) shows which pages use it, each with a link
 * to its editor, so the editor can give them another theme first. The
 * database refuses it as well (`pages.page_theme_id`, ON DELETE RESTRICT).
 * Never a cascade and never "those pages go back to the site theme": a page
 * changing its look because somebody tidied up a list is not a delete.
 * Same guards and PRG pattern as api/admin/save-page-theme.php.
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
$theme = $id === false ? null : PageThemeService::theme($id);

if ($theme === null) {
    http_response_code(404);
    exit('Page theme not found.');
}

$result = PageThemeService::delete((int) $theme['id']);

if (!$result['deleted'] && $result['pages'] === []) {
    $_SESSION['admin_page_themes_error'] = \App\Service\Language\AdminTranslator::trans('pagethemes.error_delete');
    header('Location: /admin/page-themes.php');
    exit;
}

if (!$result['deleted']) {
    $_SESSION['admin_page_themes_refusal'] = [
        'name' => (string) $theme['name'],
        'pages' => $result['pages'],
    ];
    header('Location: /admin/page-themes.php');
    exit;
}

header('Location: /admin/page-themes.php?done=deleted');
exit;
