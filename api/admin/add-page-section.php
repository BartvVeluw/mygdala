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
 *
 * A PRODUCT OR PROJECT instead of a page (Product & Portfolio Content Pages
 * 1.0): the picker on its Pagina-inhoud tab posts `content_owner` (a kind
 * from App\Service\ContentOwners, never a class) and `content_owner_id`
 * rather than a page id. The owner must belong to a module that is on and
 * must exist. Its content page is made here, with the first block and only
 * after that block passed the same checks — validated against a stand-in for
 * the page it will be (ContentPages::placeholder()) — so an owner nobody adds
 * a block to never gets one. From there on it is the same request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\SectionRegistry;
use App\Service\ContentOwners\ContentOwners;
use App\Service\ContentOwners\ContentPages;
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

// A picker card that is a PRESET of a block (App\Service\Blocks\OffersPickerPresets)
// posts `section_preset` = "<type>:<preset>" instead: the same type, started
// with one setting chosen. Both halves are checked below against the same
// closed lists the picker drew its cards from; the shape alone is checked here.
$preset = null;
$presetChoice = $_POST['section_preset'] ?? null;

if ($presetChoice !== null) {
    if (!is_string($presetChoice) || preg_match('/^([a-z0-9_]{1,64}):([a-z0-9_]{1,64})$/', $presetChoice, $choice) !== 1) {
        http_response_code(400);
        exit('This section type cannot be added to this page.');
    }

    [, $sectionType, $preset] = $choice;
}

$ownerKind = (string) ($_POST['content_owner'] ?? '');
$ownerId = (int) ($_POST['content_owner_id'] ?? 0);
$owner = null;

if ($ownerKind !== '') {
    $owner = ContentOwners::getEnabled($ownerKind);

    if ($owner === null || !$owner->exists($ownerId)) {
        http_response_code(404);
        exit('Unknown page.');
    }

    $page = ContentPages::pageFor($ownerKind, $ownerId) ?? ContentPages::placeholder($ownerKind, $ownerId);
} else {
    $page = $pageId > 0 ? (new PageRepository())->findById($pageId) : null;
}

if ($page === null) {
    http_response_code(404);
    exit('Unknown page.');
}

$repository = new PageSectionRepository();
$available = SectionRegistry::availableForPage($page, $repository);

if (!array_key_exists($sectionType, $available)
    || ($preset !== null && !SectionRegistry::offersPreset($sectionType, $preset))) {
    http_response_code(400);
    exit('This section type cannot be added to this page.');
}

try {
    // The owner's content page, made now for its first block.
    if ($owner !== null && (int) $page['id'] === 0) {
        $page = ContentPages::ensure($ownerKind, $ownerId);
    }

    [$sectionId, $sectionKey] = SectionRegistry::create($sectionType, (string) $page['content_key'], $preset);
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
    header('Location: ' . ((int) $page['id'] > 0 ? '/admin/page.php?id=' . (int) $page['id'] : $owner->editUrl($ownerId)));
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
