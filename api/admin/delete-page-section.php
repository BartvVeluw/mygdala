<?php

/**
 * POST /api/admin/delete-page-section.php
 *
 * The page builder's "Delete section" action (admin/page.php) — confirmed
 * client-side (see admin/page.php's onsubmit confirm()), then permanently
 * removes both the page_sections attachment and its underlying content
 * (including any uploaded media and child rows) via
 * App\Service\SectionRegistry::delete(), inside one DB transaction so a
 * failure partway through can never leave a dangling page_sections
 * reference. Rejects a non-deletable type (e.g. Homepage Hero) rather than
 * silently no-op-ing, so a forged request against a protected type surfaces
 * as an error instead of appearing to succeed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\PageSectionRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$id = (int) ($_POST['id'] ?? 0);

$repository = new PageSectionRepository();
$pageSection = $id > 0 ? $repository->findById($id) : null;

if ($pageSection === null) {
    http_response_code(404);
    exit('Unknown section.');
}

$pageId = (int) $pageSection['page_id'];

if (!SectionRegistry::isDeletable((string) $pageSection['section_type'])) {
    http_response_code(400);
    exit('This section type cannot be deleted.');
}

try {
    SectionRegistry::delete($pageSection, $repository);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-page-section.php] ' . $e->getMessage());

    $_SESSION['admin_pages_error'] = 'De sectie kon niet worden verwijderd. Probeer het opnieuw.';
    header('Location: /admin/page.php?id=' . $pageId);
    exit;
}

header('Location: /admin/page.php?id=' . $pageId . '&deleted=1');
exit;
