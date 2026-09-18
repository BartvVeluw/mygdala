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
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;
use App\Service\RichTextSanitizer;
use App\Service\ShopLocalization;

/**
 * @param array<string, mixed> $input raw $_POST
 * @param bool $isNew a NEW collection is written in the default language, like
 *                    a new product: its slug is generated from that name when
 *                    the admin leaves the slug field empty. An existing
 *                    collection is edited in the language the form names.
 * @return array{0: array<int, string>, 1: array<string, mixed>} [errors, normalized fields]
 */
function validateCollectionInput(array $input, bool $isNew): array
{
    $errors = [];

    // ONE website language per request (Multilingual 2.0 phase 5 wave C).
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $slugRaw = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
    $descriptionRaw = is_string($input['description'] ?? null) ? $input['description'] : '';
    $isActive = ($input['is_active'] ?? null) === '1';
    $language = $isNew
        ? ShopLocalization::defaultLanguage()
        : (LanguageCode::normalise((string) ($input['language_code'] ?? '')) ?? '');
    $isDefaultLanguage = $language !== '' && $language === ShopLocalization::defaultLanguage();

    if ($language === '' || !SiteLanguages::isActive($language)) {
        $errors[] = AdminTranslator::trans('validation.language_unknown');
    }

    // A name is required only in the DEFAULT language: a translation falls
    // back, and the slug is generated from the default language's name.
    if ($name === '' && $isDefaultLanguage) {
        $errors[] = AdminTranslator::trans('validation.naam_verplicht');
    } elseif (mb_strlen($name) > CollectionService::MAX_NAME_LENGTH) {
        $errors[] = 'Naam mag maximaal ' . CollectionService::MAX_NAME_LENGTH . ' tekens zijn.';
    }

    // The Quill editor sends HTML; RichTextSanitizer is the security
    // boundary that strips everything outside its allowlist (scripts,
    // iframes, inline handlers, unsafe URL schemes) before it can ever reach
    // the database. Same 'full' preset the Portfolio project write-ups and
    // CMS Rich text sections use, because a collection description is the
    // same kind of long-form copy.
    $description = RichTextSanitizer::sanitize($descriptionRaw);

    if ($description !== null && strlen($description) > CollectionService::MAX_DESCRIPTION_LENGTH) {
        $errors[] = 'Beschrijving is te lang.';
    }

    $fields = [
        'language_code' => $language,
        'name' => $name,
        'slug_input' => $slugRaw,
        'slug' => CollectionService::sanitizeSlug($slugRaw),
        'description' => $description,
        'is_active' => $isActive,
        // Kept raw for the PRG round trip so a failed save re-renders the
        // picker with the admin's own selection/order intact.
        'product_ids' => CollectionService::normalizeIdList($input['product_ids'] ?? null),
        // Likewise for the SEO card's "remove social image" tick.
        'remove_og_image' => seoImageRemovalRequested($input),
    ] + normalizeSeoInput($input, $errors);

    return [$errors, $fields];
}
