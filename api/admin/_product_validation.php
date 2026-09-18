<?php

declare(strict_types=1);

/**
 * Shared server-side validation + slug generation for the admin product
 * create/edit endpoints. Included by create-product.php and update-product.php
 * so both apply exactly the same rules.
 *
 * The SEO block (SEO title / meta description per language, and the optional
 * social image) is normalised by api/admin/_seo_validation.php, shared with
 * the collection editor so both apply identical rules.
 */

require_once __DIR__ . '/_seo_validation.php';

use App\Service\Language\AdminTranslator;
use App\Repository\ProductRepository;
use App\Service\CollectionService;
use App\Service\DescriptionSanitizer;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\Shipping\ShippingProfile;
use App\Service\ShopLocalization;

/**
 * @param array<string, mixed> $input raw $_POST
 * @param bool $isNew a NEW product is written in the default language, like a
 *                    new page or a new blog post: its slug comes from that
 *                    name, and translating it happens on the product itself
 *                    afterwards. An existing product is edited in the
 *                    language the form's hidden field names.
 * @return array{0: array<int, string>, 1: array<string, mixed>} [errors, normalized fields]
 */
function validateProductInput(array $input, bool $isNew): array
{
    $errors = [];

    // ONE website language per request (Multilingual 2.0 phase 5 wave C):
    // one name, one description, and the language they belong to. Every other
    // translation of this product stays exactly as it is.
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $descriptionRaw = is_string($input['description'] ?? null) ? $input['description'] : '';
    $language = $isNew
        ? ShopLocalization::defaultLanguage()
        : (LanguageCode::normalise((string) ($input['language_code'] ?? '')) ?? '');
    $isDefaultLanguage = $language !== '' && $language === ShopLocalization::defaultLanguage();
    $priceRaw = is_string($input['price'] ?? null) ? trim(str_replace(',', '.', $input['price'])) : '';
    $active = ($input['active'] ?? null) === '1';
    // Where the product may be SOLD. Two independent channels, both plain
    // checkboxes — see db/migrations/20260908220000_add_product_availability_channels.php.
    // `active` stays the master switch above them: unticked means nowhere.
    $inShop = ($input['in_shop'] ?? null) === '1';
    $inPersonalizationCatalog = ($input['in_personalization_catalog'] ?? null) === '1';

    if ($language === '' || !SiteLanguages::isActive($language)) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    }

    // A name is required only in the DEFAULT language: a translation is
    // optional by definition, because it falls back.
    if ($name === '' && $isDefaultLanguage) {
        $errors[] = AdminTranslator::trans('validation.naam_verplicht');
    } elseif (mb_strlen($name) > ShopLocalization::NAME_MAX_LENGTH) {
        $errors[] = AdminTranslator::trans('validation.naam_mag_maximaal_150_tekens');
    }

    // The rich-text editor sends HTML (paragraphs/bold/italic/links/line
    // breaks); this strips everything else (scripts, inline JS, unknown
    // tags, unsafe URL schemes) before it ever reaches the database. Plain
    // text with no tags passes through unchanged as escaped text.
    $description = DescriptionSanitizer::sanitize($descriptionRaw);

    if ($description !== null && strlen($description) > 20000) {
        $errors[] = 'Beschrijving is te lang.';
    }

    $price = 0.0;
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        $errors[] = AdminTranslator::trans('validation.prijs_verplicht_geldig_bedrag');
    } else {
        $price = (float) $priceRaw;
        if ($price <= 0 || $price > 99999.99) {
            $errors[] = AdminTranslator::trans('validation.prijs_groter_0_maximaal_99');
        }
    }

    $shippingProfile = is_string($input['shipping_profile'] ?? null) ? trim($input['shipping_profile']) : '';
    if (!ShippingProfile::isValid($shippingProfile)) {
        $errors[] = AdminTranslator::trans('validation.kies_geldig_verzendprofiel');
    }

    $weightRaw = is_string($input['shipping_weight_grams'] ?? null) ? trim($input['shipping_weight_grams']) : '';
    $shippingWeightGrams = 0;
    if ($weightRaw === '' || !is_numeric($weightRaw) || (float) $weightRaw < 0) {
        $errors[] = AdminTranslator::trans('validation.verzendgewicht_verplicht_0_hoger');
    } else {
        $shippingWeightGrams = (int) round((float) $weightRaw);
    }

    $requiresParcel = ($input['requires_parcel'] ?? null) === '1';

    $fields = [
        'language_code' => $language,
        'name' => $name,
        'description' => $description,
        'price' => $price,
        'price_input' => $priceRaw,
        'active' => $active,
        'in_shop' => $inShop,
        'in_personalization_catalog' => $inPersonalizationCatalog,
        'shipping_profile' => $shippingProfile,
        'shipping_weight_grams' => $shippingWeightGrams,
        'shipping_weight_grams_input' => $weightRaw,
        'requires_parcel' => $requiresParcel,
        // Which collections the admin ticked. Normalised to a list of ints
        // here; the ids are only confirmed to exist at save time, by
        // App\Service\CollectionService::validateCollectionIds(), so this
        // stays a pure normalisation step with no database access.
        'collection_ids' => CollectionService::normalizeIdList($input['collection_ids'] ?? null),
        // Kept for the PRG round trip so a failed save re-renders the SEO
        // card with the admin's own tick intact.
        'remove_og_image' => seoImageRemovalRequested($input),
    ] + normalizeSeoInput($input, $errors);

    return [$errors, $fields];
}

/**
 * Generates a URL-safe slug from the product name and makes it unique
 * against the products table. Only used on create — edits never change the
 * slug (nothing in the app links to it via id/URL yet, see MAIN.MD), so
 * there's no risk of edits breaking an existing slug-based reference.
 */
function generateUniqueSlug(ProductRepository $repository, string $name): string
{
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $name) : $name;
    $base = strtolower((string) ($ascii !== false ? $ascii : $name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim($base, '-');

    if ($base === '') {
        $base = 'product';
    }

    $base = substr($base, 0, 150);

    $slug = $base;
    $suffix = 2;
    while ($repository->slugExists($slug)) {
        $slug = substr($base, 0, 170 - strlen((string) $suffix) - 1) . '-' . $suffix;
        $suffix++;
    }

    return $slug;
}
