<?php

declare(strict_types=1);

/**
 * Shared server-side validation for the admin collection create/edit
 * endpoints. Included by create-collection.php and update-collection.php so
 * both apply exactly the same rules — same arrangement as
 * api/admin/_product_validation.php.
 *
 * Slug rules, product-id validation and deletion live in
 * App\Service\CollectionService (a class, so they are unit-testable and hold
 * for any caller); this file only normalises and range-checks the plain form
 * fields, which is all _product_validation.php does for products too.
 *
 * The SEO block (SEO title / meta description per language, plus the
 * optional social image) is normalised by api/admin/_seo_validation.php,
 * shared with the product editor so both apply identical rules.
 */

require_once __DIR__ . '/_seo_validation.php';

use App\Service\Language\AdminTranslator;
use App\Service\CollectionService;
use App\Service\RichTextSanitizer;

/**
 * @param array<string, mixed> $input raw $_POST
 * @return array{0: array<int, string>, 1: array<string, mixed>} [errors, normalized fields]
 */
function validateCollectionInput(array $input): array
{
    $errors = [];

    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $nameEn = is_string($input['name_en'] ?? null) ? trim($input['name_en']) : '';
    $slugRaw = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
    $descriptionRaw = is_string($input['description'] ?? null) ? $input['description'] : '';
    $descriptionEnRaw = is_string($input['description_en'] ?? null) ? $input['description_en'] : '';
    $isActive = ($input['is_active'] ?? null) === '1';

    if ($name === '') {
        $errors[] = AdminTranslator::trans('validation.naam_verplicht');
    } elseif (mb_strlen($name) > CollectionService::MAX_NAME_LENGTH) {
        $errors[] = 'Naam mag maximaal ' . CollectionService::MAX_NAME_LENGTH . ' tekens zijn.';
    }

    if (mb_strlen($nameEn) > CollectionService::MAX_NAME_LENGTH) {
        $errors[] = 'Engelse naam mag maximaal ' . CollectionService::MAX_NAME_LENGTH . ' tekens zijn.';
    }

    // The Quill editor sends HTML; RichTextSanitizer is the security
    // boundary that strips everything outside its allowlist (scripts,
    // iframes, inline handlers, unsafe URL schemes) before it can ever reach
    // the database. Same 'full' preset the Portfolio project write-ups and
    // CMS Rich text sections use, because a collection description is the
    // same kind of long-form copy.
    $description = RichTextSanitizer::sanitize($descriptionRaw);
    $descriptionEn = RichTextSanitizer::sanitize($descriptionEnRaw);

    if ($description !== null && strlen($description) > CollectionService::MAX_DESCRIPTION_LENGTH) {
        $errors[] = 'Beschrijving is te lang.';
    }

    if ($descriptionEn !== null && strlen($descriptionEn) > CollectionService::MAX_DESCRIPTION_LENGTH) {
        $errors[] = 'Engelse beschrijving is te lang.';
    }

    $fields = [
        'name' => $name,
        'name_en' => $nameEn === '' ? null : $nameEn,
        'slug_input' => $slugRaw,
        'slug' => CollectionService::sanitizeSlug($slugRaw),
        'description' => $description,
        'description_en' => $descriptionEn,
        'is_active' => $isActive,
        // Kept raw for the PRG round trip so a failed save re-renders the
        // picker with the admin's own selection/order intact.
        'product_ids' => CollectionService::normalizeIdList($input['product_ids'] ?? null),
        // Likewise for the SEO card's "remove social image" tick.
        'remove_og_image' => seoImageRemovalRequested($input),
    ] + normalizeSeoInput($input, $errors);

    return [$errors, $fields];
}
