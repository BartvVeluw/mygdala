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
 * an error, never written and never executed. Same for the portfolio scope
 * and the section background.
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
use App\Service\ItemGallerySources;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
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

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ItemGalleryRepository();

$section = ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === '')
    ? null
    : $repository->findBySlugAndKey($pageSlug, $sectionKey);

// Only a row a gallery block placed: blocks built on the gallery, such as a
// module's Projecten, keep their rows in the same table, and those are their
// own editors' to change (page_sections.section_type).
if ($section === null
    || (new PageRepository())->findByContentKey((string) $pageSlug) === null
    || (new PageSectionRepository())->findBySectionTypeAndId('item_gallery', (int) $section['id']) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$rawCollectionId = trim((string) ($_POST['collection_id'] ?? ''));
$rawMaxItems = trim((string) ($_POST['max_items'] ?? ''));

$fields = [
    'source_type' => trim((string) ($_POST['source_type'] ?? '')),
    'portfolio_scope' => trim((string) ($_POST['portfolio_scope'] ?? '')),
    'collection_id' => $rawCollectionId === '' ? null : (int) $rawCollectionId,
    'max_items' => $rawMaxItems === '' ? null : (int) $rawMaxItems,
    'show_filter_bar' => isset($_POST['show_filter_bar']),
    'enable_lightbox' => isset($_POST['enable_lightbox']),
    'fallback_link_url' => trim((string) ($_POST['fallback_link_url'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'background' => trim((string) ($_POST['background'] ?? '')),
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

if (!ItemGalleryContent::isPortfolioScope($fields['portfolio_scope'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_selectie_portfolio_items');
}

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = AdminTranslator::trans('validation.kies_geldige_achtergrond');
}

if ($fields['max_items'] !== null && ($fields['max_items'] < 1 || $fields['max_items'] > 200)) {
    $errors[] = AdminTranslator::trans('validation.maximum_aantal_items_tussen_1');
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

$old = ['language_code' => $languageCode] + $words + $fields;

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
    BlockLocalization::save('item_galleries', $sectionId, $languageCode, $words);

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

header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
