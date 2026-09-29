<?php

/**
 * POST /api/admin/update-project-info.php
 *
 * Saves one Projectinformatie block (admin/project-info.php,
 * App\Service\Blocks\ProjectInfoBlock): where the project's picture sits and
 * whether its extra photos follow. Both are words from a closed list or a
 * switch; nothing of the project itself is written here — the block shows
 * the project's own data, live. Same guard order and PRG/session-flash
 * pattern as api/admin/update-spacer.php, after the Portfolio module guard
 * (first, as in api/admin/update-featured-product.php): with the Portfolio
 * off there is no such block to save.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
\App\Module\ModuleGuard::requireApi('portfolio');

use App\Repository\ProjectInfoRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\ProjectInfoContent;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ProjectInfoRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi($pageSlug) === null
    || ($section = $repository->findBySlugAndKey($pageSlug, $sectionKey)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$redirect = '/admin/project-info.php?section=' . urlencode($sectionParam);
$position = (string) ($_POST['image_position'] ?? '');
$showGallery = (string) ($_POST['show_gallery'] ?? '0') === '1';

if (!in_array($position, ProjectInfoContent::POSITIONS, true)) {
    $_SESSION['admin_project_info_errors'] = [AdminTranslator::trans('block_project_info.error_position')];
    header('Location: ' . $redirect);
    exit;
}

try {
    $repository->updateSettings((int) $section['id'], $position, $showGallery);
    ProjectInfoContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-project-info.php] ' . $e->getMessage());

    $_SESSION['admin_project_info_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
