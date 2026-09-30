<?php

/**
 * POST /api/admin/update-item-gallery.php
 *
 * Saves one Portfolio-/collectiegalerij block
 * (admin/item-gallery.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-contact-card.php, and the
 * same "a page-builder-attached section is valid only when the page AND its
 * content row already exist" gate — an arbitrary page_slug:section_key pair
 * from the request is never trusted beyond that.
 *
 * The content source is validated against the closed list in
 * App\Service\ItemGalleryContent::SOURCES (and the collection against the
 * collections table) before it is stored: an unknown source is REFUSED with
 * an error, never written and never executed. Same for the section
 * background.
 *
 * WHICH ITEMS (Projecten 2.0): for a source whose items can be chosen
 * (ItemGallerySources::selectionSource()) the scope, the category, the order
 * and the picked items are read and checked by App\Service\ItemGallerySelection,
 * exactly as for the Projecten block, and the picked items are stored through
 * ItemGallerySources::saveSelection() in the same transaction. With no such
 * source on (its module switched off), the stored choice is kept as it is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySelection;
use App\Service\ItemGallerySources;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
use App\Repository\ButtonStyleRepository;
use App\Service\Theme\ButtonStyles;

AdminAuth::requireLoginForApi();
\App\Service\ContentOwners\ContentBlockAccess::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ItemGalleryRepository();

$section = ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === '')
    ? null
    : $repository->findBySlugAndKey($pageSlug, $sectionKey);

// Only a row a gallery block placed: blocks built on the gallery, such as a
// module's Projecten, keep their rows in the same table, and those are their
// own editors' to change (page_sections.section_type, or the draft record
// of a new one: AppServiceBlocksContentBlockDrafts::belongsTo()).
if ($section === null
    || \App\Service\ContentOwners\ContentBlockAccess::pageForKeyForApi((string) $pageSlug) === null
    || !\App\Service\Blocks\ContentBlockDrafts::belongsTo('item_gallery', (int) $section['id'])
) {
    http_response_code(404);
    exit('Unknown section.');
}

$rawCollectionId = trim((string) ($_POST['collection_id'] ?? ''));
$rawMaxItems = trim((string) ($_POST['max_items'] ?? ''));

// Which items: checked by the choice both gallery editors share, for the
// source whose items can be chosen; without one, what is stored stays.
$selectionSource = ItemGallerySources::selectionSource();
$selection = $selectionSource !== '' ? ItemGallerySelection::fromRequest($_POST, $selectionSource, $section) : null;

$fields = [
    'source_type' => trim((string) ($_POST['source_type'] ?? '')),
    'portfolio_scope' => $selection['values']['portfolio_scope'] ?? (string) $section['portfolio_scope'],
    'portfolio_category_id' => $selection !== null
        ? $selection['values']['portfolio_category_id']
        : (($section['portfolio_category_id'] ?? null) === null ? null : (int) $section['portfolio_category_id']),
    'item_sort' => $selection['values']['item_sort'] ?? (string) ($section['item_sort'] ?? 'source'),
    'collection_id' => $rawCollectionId === '' ? null : (int) $rawCollectionId,
    'max_items' => $rawMaxItems === '' ? null : (int) $rawMaxItems,
    'show_filter_bar' => isset($_POST['show_filter_bar']),
    'enable_lightbox' => isset($_POST['enable_lightbox']),
    'fallback_link_url' => trim((string) ($_POST['fallback_link_url'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    // Not in the form any more (the background is Extra vormgeving now,
    // admin/_block_appearance.php): a request without it keeps what is stored.
    'background' => array_key_exists('background', $_POST) ? trim((string) $_POST['background']) : (string) ($section['background'] ?? 'default'),
    'tight_top' => isset($_POST['tight_top']),
    'is_active' => isset($_POST['is_active']),
];

$sectionId = (int) $section['id'];
$languageCode = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$languageIsWritable = $languageCode !== '' && SiteLanguages::isActive($languageCode);
$isDefaultLanguage = $languageIsWritable && $languageCode === BlockLocalization::defaultLanguage();

// Exactly the fields the block declares, never a name taken from the request.
$words = [];
foreach (array_keys(BlockLocalization::fields('item_galleries')) as $field) {
    $words[$field] = trim((string) ($_POST[$field] ?? ''));
}

$errors = [];

if (!$languageIsWritable) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
} else {
    foreach (BlockLocalization::messageKeys(BlockLocalization::problems('item_galleries', $languageCode, $words)) as $key) {
        $errors[] = AdminTranslator::trans($key);
    }
}

/**
 * The source is validated against the CLOSED list of sources this deployment
 * currently offers (App\Service\ItemGallerySources) — request input can still
 * only hit or miss a key of that list, never become a table or class name.
 *
 * One exception, and only one: the value already stored on this row. A block
 * set to a source whose module has since been switched off must be able to
 * save its title and its display settings without that save silently
 * rewriting its source; the editor renders it as a locked option and says so.
 * The value is compared against what the database holds, never taken from the
 * request on trust.
 */
$storedSource = (string) ($section['source_type'] ?? '');

if (!ItemGalleryContent::isSource($fields['source_type'])
    && !($fields['source_type'] !== '' && $fields['source_type'] === $storedSource)
) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_inhoudsbron');
}

if ($selection !== null) {
    array_push($errors, ...$selection['errors']);
}

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_achtergrond');
}

if ($fields['max_items'] !== null && ($fields['max_items'] < 1 || $fields['max_items'] > 200)) {
    $errors[] = AdminTranslator::trans('validation.maximum_aantal_items_tussen_1');
}

// The one rule for a typed address (App\Service\Routing\SafeUrl): never a
// javascript: or a hidden control character on the website.
foreach (['fallback_link_url', 'button_url'] as $urlField) {
    $urlProblem = \App\Service\Routing\SafeUrl::optionalFieldMessage($fields[$urlField]);
    if ($urlProblem !== null && !in_array($urlProblem, $errors, true)) {
        $errors[] = $urlProblem;
    }
}

if ($fields['collection_id'] !== null) {
    try {
        if ((new CollectionRepository())->findById($fields['collection_id']) === null) {
            $errors[] = AdminTranslator::trans('validation.gekozen_collectie_bestaat_meer');
        }
    } catch (\Throwable $e) {
        error_log('[api/admin/update-item-gallery.php] collection check failed: ' . $e->getMessage());
        $errors[] = AdminTranslator::trans('validation.collectie_kon_gecontroleerd_probeer_opnieuw');
    }
}

// Picking "een collectie" without picking WHICH one would silently render an
// empty block; say so instead.
if (ItemGallerySources::needsCollection($fields['source_type']) && $fields['collection_id'] === null) {
    $errors[] = AdminTranslator::trans('validation.kies_collectie_zet_inhoudsbron_terug');
}

// A URL without a label would be an invisible button, and a label without a
// URL a button that goes nowhere. The label that counts is the default
// language's, the one every other language falls back to, so a translation
// save checks the stored default label, and a translated label without a URL
// is refused too, since it could never show.
$defaultButtonLabel = $isDefaultLanguage
    ? $words['button_label']
    : BlockLocalization::raw('item_galleries', $sectionId, 'button_label', BlockLocalization::defaultLanguage());
$buttonUrlSet = $fields['button_url'] !== '';

if ($languageIsWritable && (($defaultButtonLabel !== '') !== $buttonUrlSet || ($words['button_label'] !== '' && !$buttonUrlSet))) {
    $errors[] = AdminTranslator::trans('validation.vul_zowel_knoplabel_knop_url');
}

// The button's style (Button Styles 2.0): '' = the default, else a style that
// exists; a forged id is refused.
[$buttonStyle, $buttonStyleError] = ButtonStyles::choiceFromRequest($_POST, 'button_style_id', ButtonStyles::storedChoice($section['button_style_id'] ?? null));
if ($buttonStyleError !== null) {
    $errors[] = $buttonStyleError;
}

$old = ['language_code' => $languageCode] + $words + $fields
    + ['button_style_id' => is_scalar($_POST['button_style_id'] ?? null) ? (string) $_POST['button_style_id'] : '']
    + ($selection !== null && $selection['selected'] !== null ? ['item_ids' => $selection['selected']] : []);

if ($errors !== []) {
    $_SESSION['admin_item_gallery_errors'] = $errors;
    $_SESSION['admin_item_gallery_old'] = $old;
    header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam));
    exit;
}

$db = Database::connection();

try {
    // The gallery's settings and its words in this language are one save.
    $db->beginTransaction();

    $repository->upsertSection($pageSlug, $sectionKey, $fields);
    (new ButtonStyleRepository())->saveChoice('item_galleries', 'button_style_id', $sectionId, $buttonStyle);
    BlockLocalization::save('item_galleries', $sectionId, $languageCode, $words);
    if ($selection !== null && $selection['selected'] !== null) {
        ItemGallerySources::saveSelection($selectionSource, $sectionId, $selection['selected']);
    }

    // A new block joins its page now, in this save's transaction
    // (App\Service\Blocks\ContentBlockDrafts); an existing one is found.
    $placed = \App\Service\Blocks\ContentBlockDrafts::place('item_gallery', (int) $section['id']);
    $db->commit();
    ItemGalleryContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-item-gallery.php] ' . $e->getMessage());

    $_SESSION['admin_item_gallery_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_item_gallery_old'] = $old;
    header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, '/admin/item-gallery.php?section=' . urlencode($sectionParam)));
exit;
