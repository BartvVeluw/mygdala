<?php

/**
 * POST /api/admin/update-related-products-settings.php
 *
 * Saves the whole "Gerelateerde producten" screen (admin/related-products.php):
 * the three global settings, and the per-collection on/off switch + optional
 * heading override.
 *
 * THREE STORAGE LOCATIONS, each written through the class that already owns
 * it: SiteSettingRepository for the language-neutral key/value settings (the
 * same upsertMany() admin/settings.php uses), App\Service\LocalizedSiteSettings
 * for the shop-wide heading, and App\Service\ShopLocalization for each
 * collection's own heading. No product relation is written anywhere: which
 * products are related is derived from collection membership at render time,
 * so this endpoint never touches `collection_products` or `products`.
 *
 * ONE LANGUAGE PER REQUEST (Multilingual 2.0 phase 5 wave C). The form shows
 * one heading and one override per collection, in the language
 * `language_code` names, which must be an active website language. Every
 * other language's headings stay exactly as they are, so switching the
 * editing language cannot overwrite a translation with a stale copy — and a
 * website with five languages still posts one language's fields.
 *
 * Same guard order and PRG/session-flash pattern as every other admin
 * endpoint: login → permission → POST-only → CSRF → validate → write.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Repository\CollectionRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\LocalizedSiteSettings;
use App\Service\RelatedProductsContent;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('collections.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$redirect = '/admin/related-products.php';

$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';
$isDefaultLanguage = $language !== '' && $language === ShopLocalization::defaultLanguage();

$fields = [
    'language_code' => $language,
    'enabled' => isset($_POST['enabled']),
    'heading' => trim((string) ($_POST['heading'] ?? '')),
    'max_items' => trim((string) ($_POST['max_items'] ?? '')),
];

$errors = [];

if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

// The heading is required only in the DEFAULT language: a translation is
// optional by definition, because an untranslated heading falls back to the
// default language's words (App\Service\RelatedProductsContent::heading()).
if ($fields['heading'] === '' && $isDefaultLanguage) {
    $errors[] = AdminTranslator::trans('validation.titel_verplicht');
}

if (mb_strlen($fields['heading']) > ShopLocalization::RELATED_HEADING_MAX_LENGTH) {
    $errors[] = AdminTranslator::trans('validation.title_max_chars', ['v1' => ShopLocalization::RELATED_HEADING_MAX_LENGTH]);
}

// The save-time half of RelatedProductsContent::maxItems()' clamp, so an
// out-of-range value is reported rather than silently corrected.
$maxItemsError = RelatedProductsContent::validateMaxItems($fields['max_items']);
if ($maxItemsError !== null) {
    $errors[] = $maxItemsError;
}

/**
 * Normalises the submitted `collections[<id>][...]` structure against the
 * collections that actually exist — never trusting ids, keys or shapes from
 * the browser. An id that is not a real collection is dropped; a collection
 * missing from the submission keeps whatever it has (its checkbox was simply
 * not rendered), so this can never disable a collection the form never
 * showed.
 *
 * `heading` is one language's words, the language of the whole request.
 *
 * @param mixed $submitted raw $_POST['collections']
 * @param array<int, array<string, mixed>> $collections real collection rows
 *
 * @return array<int, array{enabled: bool, heading: string}>
 */
function relatedProductsCollectionInput(mixed $submitted, array $collections): array
{
    $submitted = is_array($submitted) ? $submitted : [];
    $normalised = [];

    foreach ($collections as $collection) {
        $id = (int) $collection['id'];
        $row = $submitted[$id] ?? null;

        if (!is_array($row)) {
            // Not in the submission at all: an unticked checkbox posts no
            // key, so "absent" means OFF — but only because the caller has
            // already confirmed the whole list was rendered (see the
            // collections_submitted marker).
            $row = [];
        }

        $normalised[$id] = [
            'enabled' => !empty($row['enabled']),
            'heading' => is_scalar($row['heading'] ?? null) ? trim((string) $row['heading']) : '',
        ];
    }

    return $normalised;
}

$db = Database::connection();
$collectionRepository = new CollectionRepository($db);

try {
    $collections = $collectionRepository->findAll();
} catch (\Throwable $e) {
    error_log('[api/admin/update-related-products-settings.php] ' . $e->getMessage());
    $collections = [];
    $errors[] = AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw');
}

$collectionInput = relatedProductsCollectionInput($_POST['collections'] ?? null, $collections);

foreach ($collectionInput as $values) {
    if (mb_strlen($values['heading']) > ShopLocalization::RELATED_HEADING_MAX_LENGTH) {
        $errors[] = AdminTranslator::trans('validation.eigen_titel_collectie_mag_maximaal');
        break;
    }
}

if ($errors !== []) {
    $_SESSION['admin_related_products_errors'] = $errors;
    $_SESSION['admin_related_products_old'] = $fields + ['collections' => $collectionInput];
    header('Location: ' . $redirect);
    exit;
}

try {
    // Neutral settings, the shop-wide heading and every collection's own
    // heading are ONE transaction: a screen that saves in one click cannot
    // end up half applied.
    $db->beginTransaction();

    (new SiteSettingRepository($db))->upsertMany([
        'related_products_enabled' => $fields['enabled'] ? '1' : '0',
        'related_products_max_items' => (string) (int) $fields['max_items'],
    ]);

    LocalizedSiteSettings::save($language, [
        LocalizedSiteSettings::RELATED_PRODUCTS_HEADING => $fields['heading'],
    ]);

    // Only synchronise the per-collection switches when the form actually
    // carried that list (see admin/related-products.php's
    // collections_submitted marker). A save posted without it — the query
    // that builds the list failed while the screen was rendering — leaves
    // every collection alone instead of silently switching them all off.
    if (isset($_POST['collections_submitted'])) {
        foreach ($collectionInput as $collectionId => $values) {
            $collectionRepository->updateRelatedProductsSettings($collectionId, $values['enabled']);
            ShopLocalization::saveCollection($collectionId, $language, [
                ShopLocalization::RELATED_HEADING => $values['heading'],
            ]);
        }
    }

    $db->commit();

    SiteSettings::clearCache();
    LocalizedSiteSettings::clearCache();
    ShopLocalization::clearCache();
    RelatedProductsContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-related-products-settings.php] ' . $e->getMessage());

    $_SESSION['admin_related_products_errors'] = [AdminTranslator::trans('validation.instellingen_konden_opgeslagen_probeer_opnieuw')];
    $_SESSION['admin_related_products_old'] = $fields + ['collections' => $collectionInput];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '?saved=1');
exit;
