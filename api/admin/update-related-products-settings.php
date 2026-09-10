<?php

/**
 * POST /api/admin/update-related-products-settings.php
 *
 * Saves the whole "Gerelateerde producten" screen (admin/related-products.php):
 * the three global settings, and the per-collection on/off switch + optional
 * heading override.
 *
 * Two storage locations, each written through the class that already owns it
 * — SiteSettingRepository for the global key/value settings (the same
 * upsertMany() admin/settings.php uses) and CollectionRepository for the
 * per-collection columns. No product relation is written anywhere: which
 * products are related is derived from collection membership at render time,
 * so this endpoint never touches `collection_products` or `products`.
 *
 * Same guard order and PRG/session-flash pattern as every other admin
 * endpoint: login → permission → POST-only → CSRF → validate → write.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\CollectionRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\RelatedProductsContent;
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

$fields = [
    'enabled' => isset($_POST['enabled']),
    'heading_nl' => trim((string) ($_POST['heading_nl'] ?? '')),
    'heading_en' => trim((string) ($_POST['heading_en'] ?? '')),
    'max_items' => trim((string) ($_POST['max_items'] ?? '')),
];

$errors = [];

if ($fields['heading_nl'] === '') {
    $errors[] = 'Titel (NL) is verplicht.';
}

foreach (['heading_nl' => 255, 'heading_en' => 255] as $key => $max) {
    if (mb_strlen($fields[$key]) > $max) {
        $errors[] = 'De titel mag maximaal ' . $max . ' tekens lang zijn.';
        break;
    }
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
 * @param mixed $submitted raw $_POST['collections']
 * @param array<int, array<string, mixed>> $collections real collection rows
 *
 * @return array<int, array{enabled: bool, heading_nl: string, heading_en: string}>
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
            'heading_nl' => is_scalar($row['heading_nl'] ?? null) ? trim((string) $row['heading_nl']) : '',
            'heading_en' => is_scalar($row['heading_en'] ?? null) ? trim((string) $row['heading_en']) : '',
        ];
    }

    return $normalised;
}

$collectionRepository = new CollectionRepository();

try {
    $collections = $collectionRepository->findAll();
} catch (\Throwable $e) {
    error_log('[api/admin/update-related-products-settings.php] ' . $e->getMessage());
    $collections = [];
    $errors[] = 'Instellingen konden niet worden opgeslagen. Probeer het opnieuw.';
}

$collectionInput = relatedProductsCollectionInput($_POST['collections'] ?? null, $collections);

foreach ($collectionInput as $values) {
    if (mb_strlen($values['heading_nl']) > 255 || mb_strlen($values['heading_en']) > 255) {
        $errors[] = 'Een eigen titel van een collectie mag maximaal 255 tekens lang zijn.';
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
    (new SiteSettingRepository())->upsertMany([
        'related_products_enabled' => $fields['enabled'] ? '1' : '0',
        'related_products_heading_nl' => $fields['heading_nl'],
        'related_products_heading_en' => $fields['heading_en'],
        'related_products_max_items' => (string) (int) $fields['max_items'],
    ]);

    // Only synchronise the per-collection switches when the form actually
    // carried that list (see admin/related-products.php's
    // collections_submitted marker). A save posted without it — the query
    // that builds the list failed while the screen was rendering — leaves
    // every collection alone instead of silently switching them all off.
    if (isset($_POST['collections_submitted'])) {
        foreach ($collectionInput as $collectionId => $values) {
            $collectionRepository->updateRelatedProductsSettings(
                $collectionId,
                $values['enabled'],
                $values['heading_nl'],
                $values['heading_en']
            );
        }
    }

    SiteSettings::clearCache();
    RelatedProductsContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-related-products-settings.php] ' . $e->getMessage());

    $_SESSION['admin_related_products_errors'] = ['Instellingen konden niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_related_products_old'] = $fields + ['collections' => $collectionInput];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '?saved=1');
exit;
