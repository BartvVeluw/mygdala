<?php

/**
 * POST /api/admin/update-block-appearance.php
 *
 * Saves the "Extra vormgeving" of ONE block instance (CONTENT-BLOCKS.md,
 * "Extra vormgeving"): its background, lines, room and decorative effect,
 * from the panel in the block list (admin/_block_appearance.php) that the
 * page, the product and the project editors share. One endpoint for every
 * block type, keyed by the page_sections id, instead of the same four fields
 * in twenty block endpoints.
 *
 * Same guard order and owner-aware permission as
 * api/admin/toggle-page-section.php: the row is looked up by its id, its page
 * decides which permission applies (a page's, a product's or a project's
 * list), and an id of a row that does not exist is a 404 before anything is
 * written. A draft has no page_sections row yet, so a block gets its look
 * only once it is on the page (ContentBlockDrafts).
 *
 * Every value is checked by App\Service\Blocks\BlockAppearance::validate()
 * against its closed list AND against what this block type supports
 * (BlockDefinition::appearanceSupport()): a forged value, or an effect the
 * block does not offer, is refused with a message and nothing is written.
 * No value from the request ever becomes CSS; it is one of a few words.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockAppearance;
use App\Service\Blocks\BlockDefinitions;
use App\Service\ContentOwners\ContentBlockAccess;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;

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

$page = (new PageRepository())->findById((int) $pageSection['page_id']);

if ($page === null) {
    http_response_code(404);
    exit('Unknown section.');
}

// The block's own list decides the permission (a product's, a project's or a page's).
ContentBlockAccess::requirePageForApi($page);

$type = (string) $pageSection['section_type'];
$support = BlockDefinitions::has($type) ? BlockDefinitions::get($type)->appearanceSupport() : null;

if ($support === null || $support->isEmpty()) {
    http_response_code(422);
    exit('This block has no styling.');
}

// Only the five fields, by their own names; anything else in the request is ignored.
$input = [];
foreach (BlockAppearance::FIELDS as $field) {
    if (array_key_exists($field, $_POST)) {
        $input[$field] = $_POST[$field];
    }
}

$checked = BlockAppearance::validate($input, $support);
$listUrl = ContentBlockAccess::listUrl($page);

if ($checked['values'] === null) {
    $_SESSION['admin_pages_error'] = implode(' ', $checked['errors']);
    header('Location: ' . $listUrl . '#blok-' . $id);
    exit;
}

try {
    $repository->updateAppearance($id, $checked['values']);
} catch (\Throwable $e) {
    error_log('[api/admin/update-block-appearance.php] ' . $e->getMessage());

    $_SESSION['admin_pages_error'] = AdminTranslator::trans('appearance.save_failed');
    header('Location: ' . $listUrl . '#blok-' . $id);
    exit;
}

// Back to the block, open and marked "Opgeslagen", as after a block editor
// saves (ContentBlockAccess::afterSaveUrl()).
header('Location: ' . ContentBlockAccess::afterSaveUrl($pageSection, $listUrl));
exit;
