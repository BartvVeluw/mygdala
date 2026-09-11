<?php

/**
 * POST /api/admin/add-page-section.php
 *
 * The page builder's "+ Contentblok toevoegen" action (admin/page.php).
 * Creates a brand-new, empty/default content row for the chosen block type
 * (App\Service\SectionRegistry::create()) and attaches it to the bottom of
 * the chosen page's one ordered block list — right where the picker's button
 * sits — then redirects straight into that block's own dedicated editor,
 * never a generic page-builder-owned form, per the "reuse existing admin
 * forms" convention every other block type already follows.
 *
 * ONE CLICK IN THE PICKER IS ONE REQUEST HERE. Each card in the block picker
 * is a submit button carrying its own section_type, so choosing and adding
 * are the same action; nothing about this endpoint changed for that, which
 * is the point.
 *
 * The page is addressed by its numeric pages.id (never a slug), and
 * section_type is validated against SectionRegistry::availableForPage()
 * (never trusted to build a class/table name directly), which rejects an
 * unknown type, a fixed block nobody may add by hand, a type not allowed on
 * this page, and one instance too many of a capped type. That is the same
 * list the picker drew its cards from, so a type that was not on screen is
 * refused here even when it is posted by hand.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Repository\PageRepository;
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

$pageId = (int) ($_POST['page_id'] ?? 0);
$sectionType = (string) ($_POST['section_type'] ?? '');

$page = $pageId > 0 ? (new PageRepository())->findById($pageId) : null;

if ($page === null) {
    http_response_code(404);
    exit('Unknown page.');
}

$repository = new PageSectionRepository();
$available = SectionRegistry::availableForPage($page, $repository);

if (!array_key_exists($sectionType, $available)) {
    http_response_code(400);
    exit('This section type cannot be added to this page.');
}

try {
    [$sectionId, $sectionKey] = SectionRegistry::create($sectionType, (string) $page['content_key']);
    $newId = $repository->create(
        (int) $page['id'],
        (string) $page['content_key'],
        $sectionType,
        $sectionKey,
        $sectionId
    );
} catch (\Throwable $e) {
    error_log('[api/admin/add-page-section.php] ' . $e->getMessage());

    $_SESSION['admin_pages_error'] = AdminTranslator::trans('validation.sectie_kon_toegevoegd_probeer_opnieuw');
    header('Location: /admin/page.php?id=' . (int) $page['id']);
    exit;
}

$newPageSection = $repository->findById($newId);
$editUrl = $newPageSection !== null ? SectionRegistry::editUrl($newPageSection) : null;

// Straight into the new block's own editor, which is where the editor wants
// to be after choosing a block. A block with no editor of its own goes back
// to the page builder naming the row that appeared, so the new block is never
// something they have to go and find.
header('Location: ' . ($editUrl ?? '/admin/page.php?id=' . (int) $page['id'] . '&added=' . $newId . '#blok-' . $newId));
exit;
