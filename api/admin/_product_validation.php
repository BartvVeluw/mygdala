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

use App\Repository\ProductRepository;
use App\Service\CollectionService;
use App\Service\DescriptionSanitizer;
use App\Service\Shipping\ShippingProfile;

/**
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: array<int, string>, 1: array<string, mixed>} [errors, normalized fields]
 */
function validateProductInput(array $input): array
{
    $errors = [];

    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $nameEn = is_string($input['name_en'] ?? null) ? trim($input['name_en']) : '';
    $descriptionRaw = is_string($input['description'] ?? null) ? $input['description'] : '';
    $descriptionEnRaw = is_string($input['description_en'] ?? null) ? $input['description_en'] : '';
    $priceRaw = is_string($input['price'] ?? null) ? trim(str_replace(',', '.', $input['price'])) : '';
    $active = ($input['active'] ?? null) === '1';
    // Where the product may be SOLD. Two independent channels, both plain
    // checkboxes — see db/migrations/20260908220000_add_product_availability_channels.php.
    // `active` stays the master switch above them: unticked means nowhere.
    $inShop = ($input['in_shop'] ?? null) === '1';
    $inPersonalizationCatalog = ($input['in_personalization_catalog'] ?? null) === '1';

    if ($name === '') {
        $errors[] = 'Naam is verplicht.';
    } elseif (mb_strlen($name) > 150) {
        $errors[] = 'Naam mag maximaal 150 tekens zijn.';
    }

    if (mb_strlen($nameEn) > 150) {
        $errors[] = 'Engelse naam mag maximaal 150 tekens zijn.';
    }

    // The rich-text editor sends HTML (paragraphs/bold/italic/links/line
    // breaks); this strips everything else (scripts, inline JS, unknown
    // tags, unsafe URL schemes) before it ever reaches the database. Plain
    // text with no tags passes through unchanged as escaped text.
    $description = DescriptionSanitizer::sanitize($descriptionRaw);
    $descriptionEn = DescriptionSanitizer::sanitize($descriptionEnRaw);

    if ($description !== null && strlen($description) > 20000) {
        $errors[] = 'Beschrijving is te lang.';
    }

    if ($descriptionEn !== null && strlen($descriptionEn) > 20000) {
        $errors[] = 'Engelse beschrijving is te lang.';
    }

    $price = 0.0;
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
        $errors[] = 'Prijs is verplicht en moet een geldig bedrag zijn.';
    } else {
        $price = (float) $priceRaw;
        if ($price <= 0 || $price > 99999.99) {
            $errors[] = 'Prijs moet groter dan 0 en maximaal € 99.999,99 zijn.';
        }
    }

    $shippingProfile = is_string($input['shipping_profile'] ?? null) ? trim($input['shipping_profile']) : '';
    if (!ShippingProfile::isValid($shippingProfile)) {
        $errors[] = 'Kies een geldig verzendprofiel.';
    }

    $weightRaw = is_string($input['shipping_weight_grams'] ?? null) ? trim($input['shipping_weight_grams']) : '';
    $shippingWeightGrams = 0;
    if ($weightRaw === '' || !is_numeric($weightRaw) || (float) $weightRaw < 0) {
        $errors[] = 'Verzendgewicht is verplicht en moet 0 of hoger zijn.';
    } else {
        $shippingWeightGrams = (int) round((float) $weightRaw);
    }

    $requiresParcel = ($input['requires_parcel'] ?? null) === '1';

    $fields = [
        'name' => $name,
        'name_en' => $nameEn === '' ? null : $nameEn,
        'description' => $description,
        'description_en' => $descriptionEn,
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
