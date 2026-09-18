<?php

declare(strict_types=1);

/**
 * Shared server-side normalisation of the four editable SEO text fields and
 * of the optional social-image upload, used identically by the product
 * editor (_product_validation.php) and the collection editor
 * (_collection_validation.php).
 *
 * One file rather than a copy in each, for the same reason
 * App\Service\Seo exists: an SEO title must mean the same thing, be trimmed
 * the same way and be capped at the same length whichever editor saved it.
 * Included with require_once, so an endpoint may pull in both validators
 * without a redeclaration error.
 */

use App\Service\Seo;

/**
 * Normalises meta_title and meta_description out of a submitted form, in the
 * ONE website language the request names (Multilingual 2.0 phase 5 wave C).
 * Before that wave it normalised a Dutch and an English pair; there is one set
 * of fields now, and which language they are stored in is the endpoint's
 * business.
 *
 * They are stored as PLAIN TEXT, not rich text: they end up inside <title>
 * and <meta> attributes, where markup has no meaning and would only ever
 * arrive by paste accident. The strip_tags() here is normalisation, not the
 * security boundary — every render escapes with htmlspecialchars() anyway.
 *
 * Nothing is silently shortened: a value longer than its database column is
 * REJECTED with a message rather than truncated, so the administrator's own
 * copy is never quietly rewritten. (The form's maxlength stops this
 * happening in a browser; this is the server-side half of the same rule.)
 *
 * Empty becomes null, and null is what makes the automatic fallback apply —
 * see App\Service\ProductSeo and App\Service\CollectionContent.
 *
 * @param array<string, mixed> $input  raw $_POST
 * @param array<int, string>   $errors appended to in place
 *
 * @return array{meta_title:?string, meta_description:?string}
 */
function normalizeSeoInput(array $input, array &$errors): array
{
    $limits = [
        'meta_title' => [Seo::MAX_META_TITLE_LENGTH, 'SEO-titel'],
        'meta_description' => [Seo::MAX_META_DESCRIPTION_LENGTH, 'Meta description'],
    ];

    $fields = [];

    foreach ($limits as $field => [$maxLength, $label]) {
        $value = is_string($input[$field] ?? null) ? $input[$field] : '';
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        if (mb_strlen($value) > $maxLength) {
            $errors[] = $label . ' mag maximaal ' . $maxLength . ' tekens zijn.';
        }

        $fields[$field] = $value === '' ? null : $value;
    }

    return $fields;
}

/**
 * Was the "remove the social image" checkbox ticked on this save?
 *
 * A separate flag rather than "an empty file input means remove": an
 * ordinary text-only save posts an empty file input every time, and must
 * leave the stored image exactly where it is (the same rule
 * ProductRepository::update() follows for the product photo).
 *
 * @param array<string, mixed> $input raw $_POST
 */
function seoImageRemovalRequested(array $input): bool
{
    return ($input['remove_og_image'] ?? null) === '1';
}
