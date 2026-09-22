<?php

/**
 * POST /api/admin/update-marquee-section.php
 *
 * Saves the WHOLE editor of one Marquee (admin/marquee.php?section=...) in
 * one request: whether it is shown, and its items — their text, whether each
 * is shown, their order, new ones and the ones marked for removal. One
 * form, one save (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is
 * no endpoint per item any more. This section type has no words of its own
 * (see App\Service\MarqueeContent).
 *
 * `editor_action` = items:up|down:<key> is the no-JavaScript path of an
 * item's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted
 * items after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): a stored item's text are the
 * language named in `language_code`, which must be an active language of the
 * website registry, and are required only in the default language
 * (MarqueeBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization); only that language is written. A NEW
 * item is written in the default language, and a removed item takes its
 * words in every language along, both through App\Service\Blocks\EditorChildList.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\MarqueeContent;
use App\Repository\PageRepository;
use App\Repository\MarqueeRepository;

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

$sectionKey = (string) ($_POST['section'] ?? '');

// A page-builder-attached instance is valid only when the page really
// exists (by its immutable pages.content_key) AND its content row already
// does too (created by App\Service\SectionRegistry::create()) — never trust
// an arbitrary page_slug:section_key pair from the request beyond that.
[$pageSlug, $sectionKeyPart] = array_pad(explode(':', $sectionKey, 2), 2, null);

if ($pageSlug === null || $pageSlug === '' || $sectionKeyPart === null || $sectionKeyPart === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || (new MarqueeRepository())->findBySlugAndKey($pageSlug, $sectionKeyPart) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$section = ['page_slug' => $pageSlug, 'section_key' => $sectionKeyPart];

$repository = new MarqueeRepository();
$redirect = '/admin/marquee.php?section=' . urlencode($sectionKey);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

$settings = ['is_active' => isset($_POST['is_active'])];

// The items of THIS section; a key naming any other row is dropped.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedIds = $stored === null ? [] : array_map(
    static fn (array $item): int => (int) $item['id'],
    $repository->findItemsBySectionId((int) $stored['id'])
);
$items = EditorChildList::fromRequest($_POST, 'items', 'marquee_items', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null));

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems() per item, as a message at its field.
    $fieldErrors = $items->problems($languageCode);
    $errors = $items->summary($fieldErrors, AdminTranslator::trans('block_marquee.item'));
}

$old = ['language_code' => $languageCode] + $settings + ['items' => $items->old()];

if ($errors !== []) {
    $_SESSION['admin_marquee_errors'] = $errors;
    $_SESSION['admin_marquee_field_errors'] = $fieldErrors;
    $_SESSION['admin_marquee_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The section's switch and every item are one save.
    $db->beginTransaction();

    $repository->upsertSection($section['page_slug'], $section['section_key'], $settings);
    $sectionId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];

    $items->save(
        $languageCode,
        static function (array $row) use ($repository, $sectionId): int {
            $id = $repository->createItem($sectionId);
            $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]),
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($sectionId, $order)
    );

    $db->commit();
    MarqueeContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-marquee-section.php] ' . $e->getMessage());

    $_SESSION['admin_marquee_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_marquee_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
