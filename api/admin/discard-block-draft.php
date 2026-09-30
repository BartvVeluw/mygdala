<?php

/**
 * POST /api/admin/discard-block-draft.php
 *
 * Annuleren in the editor of a NEW content block (Content Blocks Lifecycle
 * 1.0): the block that was chosen in the picker and never saved is removed,
 * and the editor lands on its page's block list, which looks exactly as it
 * did before the choice (App\Service\Blocks\ContentBlockDrafts::discard()).
 *
 * The editor posts what its own URL carries — `section_type` and `section`
 * (<content_key>:<section_key>) — never an id to delete. The list is found by
 * its content key and its permission checked as in every block endpoint
 * (ContentBlockAccess::pageForKeyForApi()), and only a DRAFT of that type
 * with that key on that list is removed. A block that is on its page — saved
 * a moment ago in another tab, or an existing block with a forged request —
 * is never a draft, so nothing is removed and the editor just lands on the
 * list. That also makes a second click harmless.
 *
 * Nothing on a Media Library item: a picture chosen or uploaded while writing
 * the block stays in the library.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\ContentBlockDraftRepository;
use App\Service\AdminAuth;
use App\Service\Blocks\ContentBlockDrafts;
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

$sectionType = (string) ($_POST['section_type'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', (string) ($_POST['section'] ?? ''), 2), 2, null);

if ($pageSlug === null || $sectionKey === null || $pageSlug === '' || $sectionKey === ''
    || preg_match('/^[a-z0-9_]{1,50}$/', $sectionType) !== 1
    || ($page = ContentBlockAccess::pageForKeyForApi($pageSlug)) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$draft = (new ContentBlockDraftRepository())->findOnPage((int) $page['id'], $sectionType, $sectionKey);

// Where to land, asked BEFORE the discard: a product's or project's content
// page made only for this block goes with it, and with it the link that
// names its owner. The owner's own editor does not need that page.
$back = ContentBlockAccess::listUrl($page);

if ($draft !== null) {
    try {
        ContentBlockDrafts::discard($draft);
    } catch (\Throwable $e) {
        // The page is untouched either way: a draft is on no page. What is
        // left behind is cleared later (ContentBlockDrafts::purgeStale()).
        error_log('[api/admin/discard-block-draft.php] ' . $e->getMessage());
        $_SESSION['admin_pages_error'] = AdminTranslator::trans('blocks.draft_discard_failed');
    }
}

header('Location: ' . $back);
exit;
