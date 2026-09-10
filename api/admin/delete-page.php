<?php

/**
 * POST /api/admin/delete-page.php
 *
 * Permanently deletes one CMS content page and everything on it. Same guard
 * order as every other destructive admin endpoint: session login, POST-only,
 * CSRF — never a GET-triggerable action; the admin UI additionally asks for
 * a JS confirm() first.
 *
 * The actual rules live in App\Service\PageService::delete(), so they hold
 * regardless of which UI (or forged request) reaches this endpoint:
 *   - a protected system page is refused outright;
 *   - a page still referenced by navigation or footer links is refused, with
 *     a message naming what to unlink first, rather than silently leaving
 *     holes in the menu (nav_items/footer_links additionally carry an
 *     ON DELETE RESTRICT foreign key as the database-level net);
 *   - otherwise every attached section is removed through the page
 *     builder's own SectionRegistry::delete() — content rows, child rows and
 *     uploaded media included — before the page row itself goes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\PageService;
use App\Repository\PageRepository;

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

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Invalid page id.');
}

$page = (new PageRepository())->findById($id);

if ($page === null) {
    http_response_code(404);
    exit('Page not found.');
}

try {
    PageService::delete($page);
} catch (\RuntimeException $e) {
    // A refusal (system page / still referenced) — the message is written
    // for the admin, so show it as-is.
    $_SESSION['admin_page_errors'] = [$e->getMessage()];
    header('Location: /admin/page.php?id=' . $id);
    exit;
} catch (\Throwable $e) {
    error_log('[api/admin/delete-page.php] ' . $e->getMessage());
    $_SESSION['admin_page_errors'] = ['Pagina kon niet worden verwijderd. Probeer het opnieuw.'];
    header('Location: /admin/page.php?id=' . $id);
    exit;
}

header('Location: /admin/pages.php?deleted=1');
exit;
