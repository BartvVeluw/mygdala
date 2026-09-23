<?php

/**
 * POST /api/admin/update-feature-grid.php
 *
 * Saves the WHOLE editor of one Feature grid
 * (admin/feature-grid.php?section=...) in one request: its heading, when it
 * has one, whether it is shown, and its cards — their icon, words, whether
 * each is shown, their order, new ones and the ones marked for removal. One
 * form, one save (PAGE-EDITOR.md, "Eén formulier per blok-editor"); there is
 * no endpoint per card any more.
 *
 * `editor_action` = items:up|down:<key> is the no-JavaScript path of a
 * card's ↑ and ↓ (App\Service\Blocks\EditorRows), performed on the posted
 * cards after validation and stored with everything else.
 *
 * ALL OR NOTHING. Everything is checked before anything is written, and the
 * writes are one transaction: a refused or failed save stores nothing and
 * hands every typed value back, with each message next to its field.
 *
 * ONE WEBSITE LANGUAGE (Multilingual 2.0): the eyebrow, title and lead and
 * every stored card's words are the language named in `language_code`,
 * which must be an active language of the website registry; which fields
 * exist, how long they may be and what the default language requires comes
 * from FeatureGridBlock::translatableFields(), through
 * App\Service\Blocks\BlockLocalization, and only that language is written. A
 * NEW card is written in the default language, and a removed card takes its
 * words in every language along, both through
 * App\Service\Blocks\EditorChildList. A grid without a heading of its own
 * (FeatureGridContent::SECTIONS, has_heading) has no heading words: whatever
 * a request sends for them is never read, so the frontend never gains a
 * heading it has no markup for.
 *
 * A card's icon is one of FeatureGridContent::ICON_KEYS, ICON_NONE (no icon)
 * or ICON_CUSTOM: an SVG from the library's Iconen, whose id must name an
 * SVG media item (MediaService::findIcon()) or the save is refused with a
 * message next to the picker. Any other key becomes the first icon, as it
 * always did. Only a custom icon keeps a media id, so a card that switches
 * back to a standard icon no longer counts as a use of the SVG
 * (App\Service\Media\Usage\ContentBlockMediaUsage).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\EditorChildList;
use App\Service\Blocks\EditorRows;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\FeatureGridContent;
use App\Service\Media\MediaService;
use App\Repository\FeatureGridRepository;

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
$section = FeatureGridContent::SECTIONS[$sectionKey] ?? null;

if ($section === null) {
    // Page-builder-attached instance: valid only when the page really
    // exists (by its immutable pages.content_key) AND its content row
    // already does too (created by App\Service\SectionRegistry::create())
    // — never trust an arbitrary page_slug:section_key pair from the
    // request.
    [$dynPageSlug, $dynSectionKey] = array_pad(explode(':', $sectionKey, 2), 2, null);
    if ($dynPageSlug === null || $dynSectionKey === null
        || (new \App\Repository\PageRepository())->findByContentKey($dynPageSlug) === null
        || (new FeatureGridRepository())->findBySlugAndKey($dynPageSlug, $dynSectionKey) === null
    ) {
        http_response_code(404);
        exit('Unknown section.');
    }
    $section = ['page_slug' => $dynPageSlug, 'section_key' => $dynSectionKey, 'has_heading' => true];
}

$hasHeading = $section['has_heading'];
$repository = new FeatureGridRepository();
$redirect = '/admin/feature-grid.php?section=' . urlencode($sectionKey);

$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);

$settings = ['is_active' => isset($_POST['is_active'])];

// Exactly the fields the block declares, never a name taken from the
// request — and none at all for a grid without a heading.
$words = [];
if ($hasHeading) {
    foreach (array_keys(BlockLocalization::fields('feature_grids')) as $field) {
        $words[$field] = trim((string) ($_POST[$field] ?? ''));
    }
}

// The cards of THIS grid; a key naming any other row is dropped. An icon is
// always sent, so it does not make an empty new card a card; neither does
// the custom icon's (empty) picker field.
$stored = $repository->findBySlugAndKey($section['page_slug'], $section['section_key']);
$storedIds = $stored === null ? [] : array_map(
    static fn (array $item): int => (int) $item['id'],
    $repository->findItemsByGridId((int) $stored['id'])
);
$items = EditorChildList::fromRequest($_POST, 'items', 'feature_grid_items', $storedIds, EditorRows::parseAction($_POST['editor_action'] ?? null), ['icon_key', 'icon_media_id']);

/** @return array{icon_key: string, icon_media_id: int|null, is_active: bool} what a card has that is the same in every language */
$cardSettings = static function (array $row): array {
    $iconKey = $row['fields']['icon_key'] ?? '';
    $iconMediaId = null;

    if ($iconKey === FeatureGridContent::ICON_CUSTOM) {
        $iconMediaId = MediaService::findIcon((int) ($row['fields']['icon_media_id'] ?? 0))?->id;
    } elseif ($iconKey !== FeatureGridContent::ICON_NONE && !array_key_exists($iconKey, FeatureGridContent::ICON_KEYS)) {
        $iconKey = (string) array_key_first(FeatureGridContent::ICON_KEYS);
    }

    return [
        'icon_key' => $iconKey,
        'icon_media_id' => $iconMediaId,
        'is_active' => EditorChildList::flag($row, 'active'),
    ];
};

/** A custom icon needs an SVG from the library: the field => message the list prints next to it. */
$iconProblems = static function (array $row) use ($cardSettings): array {
    $settings = $cardSettings($row);

    return $settings['icon_key'] === FeatureGridContent::ICON_CUSTOM && $settings['icon_media_id'] === null
        ? ['icon_media_id' => AdminTranslator::trans('block_features.icoon_eigen_ontbreekt')]
        : [];
};

$errors = [];
$fieldErrors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    // BlockLocalization::problems(), as a message per field.
    $fieldErrors = $hasHeading ? EditorChildList::wordErrors('feature_grids', $languageCode, $words) : [];
    $itemErrors = $items->problems($languageCode, $iconProblems);

    // One line per problem at the top, the same line next to its field.
    foreach ($fieldErrors as $message) {
        if (!in_array($message, $errors, true)) {
            $errors[] = $message;
        }
    }
    array_push($errors, ...$items->summary($itemErrors, AdminTranslator::trans('block_features.kaart')));
    $fieldErrors += $itemErrors;
}

$old = ['language_code' => $languageCode] + $words + $settings + ['items' => $items->old()];

if ($errors !== []) {
    $_SESSION['admin_feature_grid_errors'] = $errors;
    $_SESSION['admin_feature_grid_field_errors'] = $fieldErrors;
    $_SESSION['admin_feature_grid_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

$db = Database::connection();

try {
    // The grid, its heading in this language and every card are one save.
    $db->beginTransaction();

    $repository->upsertGrid($section['page_slug'], $section['section_key'], $settings);
    $gridId = (int) $repository->findBySlugAndKey($section['page_slug'], $section['section_key'])['id'];

    if ($hasHeading) {
        BlockLocalization::save('feature_grids', $gridId, $languageCode, $words);
    }

    $items->save(
        $languageCode,
        static function (array $row) use ($repository, $gridId, $cardSettings): int {
            $values = $cardSettings($row);
            $id = $repository->createItem($gridId, ['icon_key' => $values['icon_key'], 'icon_media_id' => $values['icon_media_id']]);
            $repository->updateItem($id, $values);

            return $id;
        },
        static fn (int $id, array $row) => $repository->updateItem($id, $cardSettings($row)),
        static fn (int $id) => $repository->deleteItem($id),
        static fn (array $order) => $repository->reorderItems($gridId, $order)
    );

    $db->commit();
    FeatureGridContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-feature-grid.php] ' . $e->getMessage());

    $_SESSION['admin_feature_grid_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    $_SESSION['admin_feature_grid_old'] = $old;
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;
