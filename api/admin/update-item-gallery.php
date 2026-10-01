<?php

/**
 * POST /api/admin/update-item-gallery.php
 *
 * Saves one Collectiegalerij block
 * (admin/item-gallery.php?section=<page>:<key>), a block of the Shop. Same guard order and
 * PRG/session-flash pattern as api/admin/update-contact-card.php, and the
 * same "a page-builder-attached section is valid only when the page AND its
 * content row already exist" gate — an arbitrary page_slug:section_key pair
 * from the request is never trusted beyond that.
 *
 * THE SHOP FIRST: App\Module\ModuleGuard refuses before anything else while
 * the Shop is off, since the editor's permission is a Core one.
 *
 * The content source is validated against the closed list of the sources
 * THIS block may show (App\Service\ItemGallerySources::availableFor()) and
 * the collection against the collections table before it is stored: any
 * other source — portfolio items, which are the Projecten block's, included
 * — is REFUSED with an error, never written and never executed. Same for the
 * section background, and for how the cards look (`card_presentation`,
 * checked by App\Service\Blocks\CardPresentation against what this block
 * offers). The scope, category and order of a row stay as stored: a
 * collection reads none of them.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
\App\Module\ModuleGuard::requireApi('shop');

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\CardPresentation;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\ItemGalleryContent;
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

$fields = [
    // The form sends the block's one source; a form without it means the
    // block's first source. Checked below against this block's closed list.
    'source_type' => trim((string) ($_POST['source_type'] ?? ItemGallerySources::defaultSourceFor('item_gallery'))),
    'portfolio_scope' => (string) $section['portfolio_scope'],
    'portfolio_category_id' => ($section['portfolio_category_id'] ?? null) === null ? null : (int) $section['portfolio_category_id'],
    'item_sort' => (string) ($section['item_sort'] ?? 'source'),
    'collection_id' => $rawCollectionId === '' ? null : (int) $rawCollectionId,
    'max_items' => $rawMaxItems === '' ? null : (int) $rawMaxItems,
    // A collection has no categories, so no filter bar; the stored value
    // stays as it is (it draws nothing for this source).
    'show_filter_bar' => (bool) $section['show_filter_bar'],
    'enable_lightbox' => isset($_POST['enable_lightbox']),
    'fallback_link_url' => trim((string) ($_POST['fallback_link_url'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    // Not in the form any more (the background is Extra vormgeving now,
    // admin/_block_appearance.php): a request without it keeps what is stored.
    'background' => array_key_exists('background', $_POST) ? trim((string) $_POST['background']) : (string) ($section['background'] ?? 'default'),
    'tight_top' => isset($_POST['tight_top']),
    'is_active' => isset($_POST['is_active']),
];

// How the cards look (Card Presentation 2.0): one of the presentations this
// block offers, the stored one when the form did not send the field, and a
// refused save for any other word. Never a class or a style from the request.
$cardPresentation = CardPresentation::choiceFromRequest($_POST, BlockDefinitions::get('item_gallery'), $section['card_presentation'] ?? null);

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
 * The source is validated against the CLOSED list of sources this block may
 * show (App\Service\ItemGallerySources::availableFor('item_gallery')) —
 * request input can only hit or miss a key of that list, never become a table
 * or class name, and never the Portfolio's source: a forged `portfolio` is
 * refused here like any unknown word, even on a row that still stores it.
 */
if (!ItemGalleryContent::isSource('item_gallery', $fields['source_type'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_inhoudsbron');
}

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_achtergrond');
}

if ($cardPresentation === null) {
    $errors[] = AdminTranslator::trans('validation.card_presentation_unknown');
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
    + ['card_presentation' => $cardPresentation ?? CardPresentation::stored($section['card_presentation'] ?? null)]
    + ['button_style_id' => is_scalar($_POST['button_style_id'] ?? null) ? (string) $_POST['button_style_id'] : ''];

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
    $repository->saveCardPresentation($sectionId, $cardPresentation);
    (new ButtonStyleRepository())->saveChoice('item_galleries', 'button_style_id', $sectionId, $buttonStyle);
    BlockLocalization::save('item_galleries', $sectionId, $languageCode, $words);

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

    $_SESSION['admin_item_gallery_errors'] = [\App\Service\ContentOwners\OwnerContentGuard::messageFor($e) ?? 'Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_item_gallery_old'] = $old;
    header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: ' . \App\Service\ContentOwners\ContentBlockAccess::afterSaveUrl($placed, '/admin/item-gallery.php?section=' . urlencode($sectionParam)));
exit;
