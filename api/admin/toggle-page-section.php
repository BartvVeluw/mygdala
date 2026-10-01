<?php

/**
 * POST /api/admin/toggle-page-section.php
 *
 * The page builder's "Hide"/"Show" action (admin/pages.php) — toggles
 * page_sections.is_active only. This is a SEPARATE flag from the section's
 * own dedicated editor's "Actief" checkbox (e.g. feature_grids.is_active):
 * either one hides the section from the public page, and this endpoint
 * never touches the other — hiding a section here does not change what its
 * own editor's checkbox shows, and vice versa (see the page_sections
 * migration's docblock).
 *
 * Through SectionRegistry::setActive(), so hiding the last meaningful block
 * of an owner that must keep one (an article that is not a draft) is refused
 * with the owner's own message (OwnerContentGuard).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\ContentOwners\OwnerContentGuard;
use App\Service\SectionRegistry;

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
$isActive = (string) ($_POST['is_active'] ?? '') === '1';

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

try {
    SectionRegistry::setActive($pageSection, $isActive, $repository);
} catch (\Throwable $e) {
    error_log('[api/admin/toggle-page-section.php] ' . $e->getMessage());

    $_SESSION['admin_pages_error'] = OwnerContentGuard::messageFor($e)
        ?? AdminTranslator::trans('validation.zichtbaarheid_kon_opgeslagen_probeer_opnieuw');
    header('Location: ' . ContentBlockAccess::listUrl($page));
    exit;
}

// Back to the block that was just hidden or shown rather than to the top of
// a long page: the anchor every block row on that screen carries. The same
// small courtesy as the "#blok-<id>" a freshly added block gets.
header('Location: ' . ContentBlockAccess::listUrl($page) . '#blok-' . $id);
exit;
