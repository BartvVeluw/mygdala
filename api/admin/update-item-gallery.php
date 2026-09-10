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

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\ItemGalleryContent;
use App\Service\ItemGallerySources;
use App\Repository\CollectionRepository;
use App\Repository\ItemGalleryRepository;
use App\Repository\PageRepository;

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

if ($section === null || (new PageRepository())->findByContentKey((string) $pageSlug) === null) {
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
    'eyebrow_nl' => trim((string) ($_POST['eyebrow_nl'] ?? '')),
    'eyebrow_en' => trim((string) ($_POST['eyebrow_en'] ?? '')),
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'lead_nl' => trim((string) ($_POST['lead_nl'] ?? '')),
    'lead_en' => trim((string) ($_POST['lead_en'] ?? '')),
    'footer_note_nl' => trim((string) ($_POST['footer_note_nl'] ?? '')),
    'footer_note_en' => trim((string) ($_POST['footer_note_en'] ?? '')),
    'button_label_nl' => trim((string) ($_POST['button_label_nl'] ?? '')),
    'button_label_en' => trim((string) ($_POST['button_label_en'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'background' => trim((string) ($_POST['background'] ?? '')),
    'tight_top' => isset($_POST['tight_top']),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];

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
    $errors[] = 'Kies een geldige inhoudsbron.';
}

if (!ItemGalleryContent::isPortfolioScope($fields['portfolio_scope'])) {
    $errors[] = 'Kies een geldige selectie voor portfolio-items.';
}

if (!ItemGalleryContent::isBackground($fields['background'])) {
    $errors[] = 'Kies een geldige achtergrond.';
}

if ($fields['max_items'] !== null && ($fields['max_items'] < 1 || $fields['max_items'] > 200)) {
    $errors[] = 'Het maximum aantal items moet tussen 1 en 200 liggen, of leeg blijven.';
}

if ($fields['collection_id'] !== null) {
    try {
        if ((new CollectionRepository())->findById($fields['collection_id']) === null) {
            $errors[] = 'De gekozen collectie bestaat niet (meer).';
        }
    } catch (\Throwable $e) {
        error_log('[api/admin/update-item-gallery.php] collection check failed: ' . $e->getMessage());
        $errors[] = 'De collectie kon niet worden gecontroleerd. Probeer het opnieuw.';
    }
}

// Picking "een collectie" without picking WHICH one would silently render an
// empty block; say so instead.
if (ItemGallerySources::needsCollection($fields['source_type']) && $fields['collection_id'] === null) {
    $errors[] = 'Kies een collectie, of zet de inhoudsbron terug op portfolio-items.';
}

// A URL without a label would be an invisible button, and a label without a
// URL a button that goes nowhere.
if (($fields['button_url'] !== '') !== ($fields['button_label_nl'] !== '')) {
    $errors[] = 'Vul zowel een knoplabel als een knop-URL in, of laat ze allebei leeg.';
}

if ($errors !== []) {
    $_SESSION['admin_item_gallery_errors'] = $errors;
    $_SESSION['admin_item_gallery_old'] = $fields;
    header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam));
    exit;
}

try {
    $repository->upsertSection($pageSlug, $sectionKey, $fields);
    ItemGalleryContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-item-gallery.php] ' . $e->getMessage());

    $_SESSION['admin_item_gallery_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_item_gallery_old'] = $fields;
    header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/item-gallery.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;
