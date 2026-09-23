<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;
use App\Service\Routing\LocalizedSlug;

/**
 * THE way into the Shop's visitor-facing words in any website language
 * (Multilingual 2.0 phase 5 wave C, docs/multilingual/ARCHITECTURE.md,
 * MODULES.md "Shop"). They live in two typed tables, one row per owner per
 * language:
 *
 *   product_translations     name, description, meta_title, meta_description
 *   collection_translations  name, description, meta_title, meta_description,
 *                            related_heading
 *
 * WORDS ARE NOT IDENTITY, and that line is the whole point of this class.
 * Everything a shop DECIDES with stays on its own row and is the same in
 * every language: a product's id, slug, price, stock, shipping settings,
 * `active`, `in_shop`, `in_personalization_catalog`, image paths, its
 * variants and their identity, its personalization configuration; a
 * collection's id, slug, images, `is_active`, `show_related_products` and
 * sort order; and every relation between them. A visitor switching language
 * reads different words and gets the same product at the same price, looked
 * up by the same id, in a cart with the same line.
 *
 * AND WORDS ARE NOT AN ORDER. What a product was CALLED when somebody bought
 * it is a snapshot, not a translation of what it is called now, and it lives
 * in App\Service\OrderItemNameSnapshot with a rule of its own. Nothing in
 * this class is ever read for a historical order, and nothing here is ever
 * written from one.
 *
 * Nothing else reads or writes these two tables: not a repository, not a
 * `*Content` class, not an endpoint. The fallback — the asked-for language,
 * the default language, '' — is App\Service\Language\LanguageFallback's,
 * through App\Service\Language\EntityTranslations. Same shape as
 * App\Service\PortfolioLocalization and App\Service\Blog\BlogLocalization.
 *
 * `description` IS THE ONE RICH FIELD of either, and it keeps the sanitizer
 * the Shop always had (App\Service\DescriptionSanitizer, applied on the way
 * in by the editors and again on the way out here — the "sanitize again on
 * read" half of that pattern). There is no second sanitizer.
 *
 * MODULE OFF CHANGES NOTHING. Switching the Shop off removes no row and no
 * word, and switching it back on shows the same words.
 */
final class ShopLocalization
{
    public const NAME = 'name';
    public const DESCRIPTION = 'description';
    public const META_TITLE = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const RELATED_HEADING = 'related_heading';

    public const NAME_MAX_LENGTH = 150;
    public const META_TITLE_MAX_LENGTH = 255;
    public const META_DESCRIPTION_MAX_LENGTH = 500;
    public const RELATED_HEADING_MAX_LENGTH = 255;

    /**
     * The description has no character rule of its own in the editors — they
     * cap the sanitized HTML at 20000 BYTES (api/admin/_product_validation.php)
     * — so this is the length the rich blocks use, as a backstop against a
     * runaway paste rather than as the rule an editor meets.
     */
    public const DESCRIPTION_MAX_LENGTH = 50000;

    /** The fields a product has, in the order its editor shows them. */
    public const PRODUCT_FIELDS = [
        self::NAME => self::NAME_MAX_LENGTH,
        self::DESCRIPTION => self::DESCRIPTION_MAX_LENGTH,
        self::META_TITLE => self::META_TITLE_MAX_LENGTH,
        self::META_DESCRIPTION => self::META_DESCRIPTION_MAX_LENGTH,
    ];

    /** A collection has the same four, plus its own related-products heading. */
    /**
     * A collection's public address in one language (Multilingual 2.0 phase 6,
     * docs/multilingual/ROUTING.md), read through
     * App\Service\Language\EntityTranslations::slug(), which has no fallback.
     *
     * PRODUCTS HAVE NO SUCH FIELD, and that is deliberate: a product is one
     * page at /product.php?id=… however many collections it appears in
     * (App\Service\ProductSeo). Giving it a slug URL is a URL decision with
     * nothing to do with language, so a column here would be one nothing
     * reads.
     */
    public const SLUG = TranslationTable::SLUG;

    /** Matches `collections.slug`. */
    public const SLUG_MAX_LENGTH = 170;

    public const COLLECTION_FIELDS = self::PRODUCT_FIELDS + [
        self::RELATED_HEADING => self::RELATED_HEADING_MAX_LENGTH,
    ];

    private static ?EntityTranslations $products = null;
    private static ?EntityTranslations $collections = null;
    private static ?EntityTranslations $variants = null;

    /**
     * A variant's own words: only its description, and only where an editor
     * gave it one. No row in a language means "the product's description"
     * (variantDescription()), so nothing here is ever a copy of the product's
     * text.
     */
    public const VARIANT_FIELDS = [
        self::DESCRIPTION => self::DESCRIPTION_MAX_LENGTH,
    ];

    public static function products(): EntityTranslations
    {
        return self::$products ??= new EntityTranslations(
            new TranslationTable('product_translations', 'product_id', self::PRODUCT_FIELDS)
        );
    }

    public static function collections(): EntityTranslations
    {
        return self::$collections ??= new EntityTranslations(
            new TranslationTable(
                'collection_translations',
                'collection_id',
                // The ADDRESS is a column of the table, not one of the WORDS:
                // *_FIELDS is what an editor writes and what falls back, and
                // a slug is neither (docs/multilingual/ROUTING.md).
                [self::SLUG => self::SLUG_MAX_LENGTH] + self::COLLECTION_FIELDS
            )
        );
    }

    public static function variants(): EntityTranslations
    {
        return self::$variants ??= new EntityTranslations(
            new TranslationTable('product_variant_translations', 'variant_id', self::VARIANT_FIELDS)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Products                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A collection's address in one language: its own when it has one, else
     * the neutral `collections.slug` for the DEFAULT language only, else null
     * (App\Service\Routing\LocalizedSlug — the same rule pages and blog
     * posts follow).
     *
     * null is "this language has no public route to this collection". Words
     * fall back; an address does not.
     *
     * @param array<string, mixed> $collection a `collections` row
     */
    public static function collectionSlug(array $collection, string $languageCode): ?string
    {
        return LocalizedSlug::resolve(
            self::collections()->slug((int) ($collection['id'] ?? 0), $languageCode),
            (string) ($collection['slug'] ?? ''),
            $languageCode
        );
    }

    /** The words a visitor gets for one product field, with the fallback. */
    public static function product(int $productId, string $field, string $languageCode): string
    {
        return self::products()->value($productId, $field, $languageCode);
    }

    /** The stored words in one language, no fallback: what the editor shows. */
    public static function rawProduct(int $productId, string $field, string $languageCode): string
    {
        return self::products()->raw($productId, $field, $languageCode);
    }

    /**
     * One product's sanitized description in one language, sanitized BEFORE
     * the fallback runs: a language whose markup sanitizes away to nothing
     * simply has no description, and the fallback takes over.
     */
    public static function productDescription(int $productId, string $languageCode): string
    {
        return self::sanitized(self::products()->words($productId), self::DESCRIPTION, $languageCode);
    }

    /** What the CMS calls a product in its lists, pickers and headings. */
    public static function productName(int $productId): string
    {
        return self::products()->name($productId, self::NAME);
    }

    /** @param list<int> $productIds */
    public static function preloadProducts(array $productIds): void
    {
        self::products()->preload($productIds);
    }

    /**
     * The products whose name contains $needle, in ANY website language: what
     * a CMS search box narrows a list to.
     *
     * @return list<int>
     */
    public static function productIdsMatchingName(string $needle): array
    {
        return self::products()->ownersMatching(self::NAME, $needle);
    }

    /**
     * Store one language's words for a product. Only the fields given are
     * written, so a screen that does not show a field cannot empty it.
     *
     * @param array<string, string|null> $values
     */
    public static function saveProduct(int $productId, string $languageCode, array $values): void
    {
        self::products()->save($productId, $languageCode, $values);
    }

    /* ------------------------------------------------------------------ */
    /* Variants                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * A variant's OWN description in exactly this language, sanitized, or ''
     * when it has none there — no fallback to another language. What the
     * editor shows, and the first half of variantDescription().
     */
    public static function variantOwnDescription(int $variantId, string $languageCode): string
    {
        $words = self::variants()->words($variantId);

        return DescriptionSanitizer::sanitize($words[$languageCode][self::DESCRIPTION] ?? null) ?? '';
    }

    /**
     * The description a visitor reads for a variant:
     *
     *     the variant's own text in this language ?? the product's description in this language
     *
     * The second half is productDescription(), with the product's own
     * language fallback. The variant's text never falls back to another
     * language first: a variant that was only given Dutch text shows the
     * product's English text on the English page rather than Dutch words, and
     * an override in one language leaves every other language as it was.
     */
    public static function variantDescription(int $variantId, int $productId, string $languageCode): string
    {
        $own = self::variantOwnDescription($variantId, $languageCode);

        return $own !== '' ? $own : self::productDescription($productId, $languageCode);
    }

    /**
     * Stores or clears one variant's own description in one language. null
     * (or empty) removes the override, and the variant follows the product's
     * description again. Every other language is left as it is.
     */
    public static function saveVariantDescription(int $variantId, string $languageCode, ?string $html): void
    {
        self::variants()->save($variantId, $languageCode, [self::DESCRIPTION => $html]);
    }

    /** @param list<int> $variantIds */
    public static function preloadVariants(array $variantIds): void
    {
        self::variants()->preload($variantIds);
    }

    /* ------------------------------------------------------------------ */
    /* Collections                                                         */
    /* ------------------------------------------------------------------ */

    public static function collection(int $collectionId, string $field, string $languageCode): string
    {
        return self::collections()->value($collectionId, $field, $languageCode);
    }

    public static function rawCollection(int $collectionId, string $field, string $languageCode): string
    {
        return self::collections()->raw($collectionId, $field, $languageCode);
    }

    /** A collection's sanitized description in one language, the way productDescription() reads one. */
    public static function collectionDescription(int $collectionId, string $languageCode): string
    {
        return self::sanitized(self::collections()->words($collectionId), self::DESCRIPTION, $languageCode);
    }

    /** What the CMS calls a collection in its lists, pickers and headings. */
    public static function collectionName(int $collectionId): string
    {
        return self::collections()->name($collectionId, self::NAME);
    }

    /** @param list<int> $collectionIds */
    public static function preloadCollections(array $collectionIds): void
    {
        self::collections()->preload($collectionIds);
    }

    /** @param array<string, string|null> $values */
    public static function saveCollection(int $collectionId, string $languageCode, array $values): void
    {
        self::collections()->save($collectionId, $languageCode, $values);
    }

    /** The language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::products()->clearCache();
        self::collections()->clearCache();
        self::variants()->clearCache();
    }

    /**
     * One rich field of one owner in one language, sanitized per language
     * before the fallback runs. A language whose markup sanitizes away to
     * nothing has no words, so the fallback takes over rather than a visitor
     * getting an empty block — the same rule
     * App\Service\PortfolioLocalization::itemRich() and
     * App\Service\Blog\BlogLocalization::body() follow.
     *
     * @param array<string, array<string, string>> $words language code => field => words
     */
    private static function sanitized(array $words, string $field, string $languageCode): string
    {
        $html = [];
        foreach ($words as $code => $fields) {
            $markup = DescriptionSanitizer::sanitize($fields[$field] ?? null) ?? '';
            if ($markup !== '') {
                $html[$code] = $markup;
            }
        }

        return LanguageFallback::resolve($html, $languageCode);
    }
}
