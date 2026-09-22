<?php

/**
 * POST /api/admin/update-stat-strip.php
 *
 * Saves the WHOLE editor of one Stat strip (admin/stat-strip.php?section=...)
 * in one request: whether it is shown, and its stats — their words, whether
 * each is shown, their order, new ones and the ones marked for removal. One
 * form, one save (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is
 * no endpoint per stat any more. This section type has no words of its own
 * (see App\Service\StatStripContent).
 *
 * `editor_action` = items:up|down:<key> is the no-JavaScript path of a
 * stat's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted
 * stats after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): a stored stat's words are the
 * language named in `language_code`, which must be an active language of the
 * website registry, and are required only in the default language
 * (StatStripBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization); only that language is written. A NEW
 * stat is written in the default language, and a removed stat takes its
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
use App\Service\StatStripContent;
use App\Repository\StatStripRepository;

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
$section = StatStripContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new StatStripRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey];
}

$repository = new StatStripRepository();
$redirect = '/admin/stat-strip.php?section=' . urlencode($sectionKey);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

$settings = ['is_active' => isset($_POST['is_active'])];

// The stats of THIS strip; a key naming any other row is dropped.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedIds = $stored === null ? [] : array_map(
    static fn (array $item): int => (int) $item['id'],
    $repository->findItemsByStripId((int) $stored['id'])
);
$items = EditorChildList::fromRequest($_POST, 'items', 'stat_strip_items', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null));

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems() per stat, as a message at its field.
    $fieldErrors = $items->problems($languageCode);
    $errors = $items->summary($fieldErrors, AdminTranslator::trans('block_stats.stat'));
}

$old = ['language_code' => $languageCode] + $settings + ['items' => $items->old()];

if ($errors !== []) {
    $_SESSION['admin_stat_strip_errors'] = $errors;
    $_SESSION['admin_stat_strip_field_errors'] = $fieldErrors;
    $_SESSION['admin_stat_strip_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The strip's switch and every stat are one save.
    $db->beginTransaction();

    $repository->upsertStrip($section['page_slug'], $section['section_key'], $settings);
    $stripId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];

    $items->save(
        $languageCode,
        static function (array $row) use ($repository, $stripId): int {
            $id = $repository->createItem($stripId);
            $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updateItem($id, ['is_active' => EditorChildList::flag($row, 'active')]),
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($stripId, $order)
    );

    $db->commit();
    StatStripContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-stat-strip.php] ' . $e->getMessage());

    $_SESSION['admin_stat_strip_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_stat_strip_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
