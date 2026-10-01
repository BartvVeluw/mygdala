<?php

/**
 * POST /api/admin/delete-page-section.php
 *
 * The page builder's "Delete section" action (admin/page.php) — confirmed
 * client-side in the CMS's shared dialog (admin_confirm_dialog(), ADMIN-UI.md),
 * which is a courtesy and never a guard, then permanently
 * removes both the page_sections attachment and its underlying content
 * (including any uploaded media and child rows) via
 * App\Service\SectionRegistry::delete(), inside one DB transaction so a
 * failure partway through can never leave a dangling page_sections
 * reference. Rejects a non-deletable type (e.g. Homepage Hero) rather than
 * silently no-op-ing, so a forged request against a protected type surfaces
 * as an error instead of appearing to succeed. The last meaningful block of
 * an owner that must keep one (an article that is not a draft) is refused
 * with the owner's own message (OwnerContentGuard).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\ContentOwners\OwnerContentGuard;

AdminAuth::requireLoginForApi();
ContentBlockAccess::requireAnyForApi();

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
$page = (new PageRepository())->findById($pageId);

if ($page === null) {
    http_response_code(404);
    exit('Unknown section.');
}

// The block's own list decides the permission (a product's, a project's or a page's).
ContentBlockAccess::requirePageForApi($page);

if (!SectionRegistry::isDeletable((string) $pageSection['section_type'])) {
    http_response_code(400);
    exit('This section type cannot be deleted.');
}

try {
    SectionRegistry::delete($pageSection, $repository);
} catch (\Throwable $e) {
    error_log('[api/admin/delete-page-section.php] ' . $e->getMessage());

    $_SESSION['admin_pages_error'] = OwnerContentGuard::messageFor($e)
        ?? AdminTranslator::trans('validation.sectie_kon_verwijderd_probeer_opnieuw');
    header('Location: ' . ContentBlockAccess::listUrl($page));
    exit;
}

header('Location: ' . ContentBlockAccess::listUrl($page) . '&deleted=1');
exit;
