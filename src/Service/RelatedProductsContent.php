<?php

namespace App\Service;

use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Service\Language\LanguageFallback;
use App\Service\Routing\RequestLanguage;


/**
 * "Gerelateerde producten" on a product detail page: which other products to
 * show under a product, and under which heading.
 *
 * This is NOT a page-builder section and nothing about it is configured per
 * page. The shop's own collections are the single source of truth: a
 * product's collection IS its related-products pool, so an owner who curates
 * a collection has already curated its related products, and there is no
 * second list to maintain and no per-product configuration at all.
 *
 * Everything here is composed out of code that already existed, rather than
 * new SQL:
 *   - CollectionRepository::collectionsForProduct() — the product's
 *     collections, already ordered by the CMS's own collection order
 *     (collections.sort_order, then id).
 *   - ProductRepository::findAllActive($collectionId) — the ACTIVE products
 *     of one collection, already in that collection's own product order
 *     (collection_products.sort_order). This is byte-for-byte the query the
 *     public collection page uses, so "visible in the shop" has exactly one
 *     definition.
 * The only thing this class adds is the choice of collection, the removal of
 * the product being viewed, and the cap.
 *
 * ELIGIBILITY, in order. A product shows related products when:
 *   1. the global switch (`related_products_enabled`) is on;
 *   2. the product itself is active — an unavailable product's page is an
 *      error state, not a place to advertise;
 *   3. it belongs to at least one ELIGIBLE collection: published
 *      (is_active = 1, the same rule that decides whether the collection's
 *      own public page exists at all) AND with show_related_products = 1;
 *   4. that collection contains at least one other active product.
 * Fail any of these and forProduct() returns null and the section is not
 * rendered — no empty heading, no empty grid.
 *
 * PRODUCTS IN MORE THAN ONE COLLECTION. The data model allows it (see
 * collection_products) and there is no "primary collection" concept in this
 * project, so the rule is deterministic rather than merged: the FIRST
 * eligible collection in the CMS's existing collection order wins, and that
 * one collection is the whole pool. Deliberately no merging of several
 * collections — that would make the list depend on how many collections a
 * product happens to sit in, and would silently reorder as collections are
 * added. Note that "eligible" is a configuration property (published +
 * enabled), not a content one: if the winning collection turns out to hold
 * no other visible products, the section is simply not rendered rather than
 * falling through to the product's next collection, so what an owner sees on
 * the Gerelateerde producten screen is what decides the source.
 */
class RelatedProductsContent
{
    /** Bounds for the "maximum products" setting, enforced on save and on read. */
    public const MIN_MAX_ITEMS = 1;
    public const MAX_MAX_ITEMS = 24;

    /** @var array<string, array<string, mixed>|null> per request language and product */
    private static array $cache = [];

    /**
     * The related-products block for one product, or null when it must not be
     * rendered at all (see the class docblock's eligibility list).
     *
     * A database problem is treated the same as "nothing to show": the
     * product page keeps working, it just has no related products — the same
     * fallback philosophy every Content class in this project follows.
     *
     * @return array{heading: string, product_ids: list<int>, collection: array<string, mixed>}|null
     */
    public static function forProduct(int $productId): ?array
    {
        $language = RequestLanguage::current();
        $cacheKey = $language . '|' . $productId;
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        if ($productId < 1 || !self::isEnabled()) {
            return self::$cache[$cacheKey] = null;
        }

        try {
            $productRepository = new ProductRepository();

            // The product must be publicly visible itself. findActiveById()
            // is the shop's own "is this product public" query — deleted and
            // deactivated products both come back as null.
            if ($productRepository->findActiveById($productId) === null) {
                return self::$cache[$cacheKey] = null;
            }

            $collection = self::sourceCollection($productId);
            if ($collection === null) {
                return self::$cache[$cacheKey] = null;
            }

            // Active products of that collection, in the collection's own
            // order. Nothing is re-sorted here: the current product is
            // removed in place and the remainder keeps its relative order,
            // then the cap is applied last.
            $productIds = [];
            foreach ($productRepository->findAllActive((int) $collection['id']) as $row) {
                $id = (int) $row['id'];
                if ($id !== $productId) {
                    $productIds[] = $id;
                }
            }

            if ($productIds === []) {
                return self::$cache[$cacheKey] = null;
            }

            $productIds = array_slice($productIds, 0, self::maxItems());
        } catch (\Throwable $e) {
            error_log('[RelatedProductsContent] falling back to no related products for product ' . $productId . ': ' . $e->getMessage());

            return self::$cache[$cacheKey] = null;
        }

        return self::$cache[$cacheKey] = [
            'heading' => self::heading((int) $collection['id'], $language),
            'product_ids' => $productIds,
            'collection' => $collection,
        ];
    }

    /**
     * The collection a product's related products come from: the first of
     * its collections that is both published and has related products
     * enabled, in the CMS's own collection order. Null when it has none.
     *
     * Reuses CollectionRepository::collectionsForProduct(), which already
     * returns full rows in `collections.sort_order ASC, collections.id ASC`
     * — no second copy of that ordering rule, and no new SQL.
     *
     * @return array<string, mixed>|null
     */
    public static function sourceCollection(int $productId): ?array
    {
        foreach ((new CollectionRepository())->collectionsForProduct($productId) as $collection) {
            if ((int) $collection['is_active'] === 1 && (int) $collection['show_related_products'] === 1) {
                return $collection;
            }
        }

        return null;
    }

    /**
     * THE HEADING, with its precedence intact.
     *
     * Two sources, and the collection's own words win as a UNIT: a collection
     * that has a heading at all speaks for itself, in whatever language it has
     * it, before the global setting is consulted. Within each source the
     * ordinary fallback applies — the asked-for language, the default
     * language — and that is App\Service\Language\LanguageFallback's one rule,
     * applied twice in source order rather than a second fallback written here.
     *
     *   1. the collection's heading in this language
     *   2. the collection's heading in the default language
     *   3. the global setting in this language
     *   4. the global setting in the default language
     *   5. ''  (and then nothing is rendered)
     *
     * Before Multilingual 2.0 phase 5 wave C this was two fixed chains:
     * NL = the collection's Dutch heading, else the global Dutch setting; and
     * EN = the collection's English heading, else its Dutch one, else the
     * global English setting, else the Dutch answer. On a Dutch-default site
     * the five steps above produce exactly those two chains, value for value —
     * Tests\Service\RelatedProductsContentTest pins that down. On a site whose
     * default language is NOT Dutch they differ deliberately: the old English
     * chain ended on the Dutch heading, and one fallback rule cannot.
     */
    public static function heading(int $collectionId, string $languageCode): string
    {
        $ownWords = [];
        foreach (ShopLocalization::collections()->words($collectionId) as $code => $fields) {
            if (($fields[ShopLocalization::RELATED_HEADING] ?? '') !== '') {
                $ownWords[(string) $code] = $fields[ShopLocalization::RELATED_HEADING];
            }
        }

        $globalWords = LocalizedSiteSettings::words(LocalizedSiteSettings::RELATED_PRODUCTS_HEADING);

        $own = LanguageFallback::resolve($ownWords, $languageCode);

        return $own !== '' ? $own : LanguageFallback::resolve($globalWords, $languageCode);
    }

    public static function isEnabled(): bool
    {
        return SiteSettings::get('related_products_enabled') === '1';
    }

    /**
     * The configured cap, clamped to the same bounds the admin form
     * validates against — a value written directly into the database can
     * never make a product page render the whole catalogue, or nothing.
     */
    public static function maxItems(): int
    {
        $value = (int) SiteSettings::get('related_products_max_items');

        return max(self::MIN_MAX_ITEMS, min(self::MAX_MAX_ITEMS, $value));
    }

    /**
     * Validates the submitted "maximum products" value. Returns the message
     * to show, or null when it is fine — the save-time half of the clamp in
     * maxItems(), so an admin gets told rather than silently corrected.
     */
    public static function validateMaxItems(string $submitted): ?string
    {
        if ($submitted === '' || !ctype_digit($submitted)) {
            return 'Maximum aantal producten moet een heel getal zijn.';
        }

        $value = (int) $submitted;
        if ($value < self::MIN_MAX_ITEMS || $value > self::MAX_MAX_ITEMS) {
            return 'Maximum aantal producten moet tussen ' . self::MIN_MAX_ITEMS . ' en ' . self::MAX_MAX_ITEMS . ' liggen.';
        }

        return null;
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
     * after writing new values, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

}
