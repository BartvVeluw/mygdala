<?php

declare(strict_types=1);

/**
 * The <head> SEO block for the pages whose metadata a resolver hands over as
 * an array rather than as an App\Service\SeoMetadata: the two shop detail
 * pages (product.php, collectie.php) and the Personalisatie catalogue
 * (personaliseren.php).
 *
 * Include inside <head>, having first set:
 *
 *   $seo (array) with:
 *     title                          (string) the already-resolved <title>
 *                                    text, in the request's language
 *     description                    (string) the already-resolved meta
 *                                    description in that language; '' means
 *                                    "this page has none of its own"
 *     canonical_url                  (string) absolute, built by the content
 *                                    type's own canonicalUrl() helper
 *     og_image_path                  (?string) this page's social image, or
 *                                    null for the site-wide one
 *     json_ld                        (?array) structured data, or null
 *   $seoOgType    (string, optional) og:type; defaults to 'website'
 *   $seoIndexable (bool, optional)   defaults to true
 *
 * An adapter, not a second renderer: it maps that array onto
 * App\Service\SeoMetadata and requires partials/seo-head.php, which prints
 * every tag. The array shape is kept because App\Service\ProductSeo and
 * App\Service\CollectionContent return it and their tests assert on it —
 * turning those two into SeoMetadata factories is a refactor of the shop's
 * own read models, not of the SEO layer, and is deliberately left for later
 * (SEO.md).
 *
 * This partial still RESOLVES NOTHING. Which title a product gets, which
 * photo represents a collection and what goes into the JSON-LD are decided
 * in App\Service\ProductSeo / App\Service\CollectionContent, once, so the
 * head, the Open Graph tags, the structured data and the sitemap can never
 * disagree.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\SeoMetadata;

$seoMetadata = SeoMetadata::create(
    title: (string) ($seo['title'] ?? ''),
    description: (string) ($seo['description'] ?? ''),
    canonical: (string) ($seo['canonical_url'] ?? ''),
    indexable: $seoIndexable ?? true,
    ogType: $seoOgType ?? 'website',
    socialImage: $seo['og_image_path'] ?? null,
    jsonLd: is_array($seo['json_ld'] ?? null) ? $seo['json_ld'] : null,
);

require __DIR__ . '/seo-head.php';
