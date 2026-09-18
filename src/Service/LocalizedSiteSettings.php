<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\LocalizedSettings;
use App\Service\Language\LocalizedValue;

/**
 * THE way into the few CORE site settings that are WEBSITE TEXT in a language
 * (Multilingual 2.0 phase 4, docs/multilingual/ARCHITECTURE.md): stored one
 * row per key per website language in `site_setting_translations`, through
 * App\Service\Language\LocalizedSettings.
 *
 * A CLOSED CATALOGUE, not a generic translation bin. KEYS below is the whole
 * list; the store refuses any other key, so no request, row or module can
 * invent a localized setting, and nothing that is not website text for a
 * visitor belongs here:
 *
 *   city                the place in the contact block's details card
 *   footer_description  the short text in the footer's company block
 *   footer_slogan       the footer's closing line
 *   related_products_heading  the SHOP-WIDE heading above the related products
 *                       under a product, when the collection the products came
 *                       from has no heading of its own
 *
 * The last one is the Shop's, added in phase 5 wave C. It is here rather than
 * in a store of the Shop's own because it is genuinely SITE-WIDE — one heading
 * for the whole shop, set on one screen — and because its per-collection
 * override lives with the collection, where App\Service\RelatedProductsContent
 * reads them together as one precedence chain. A setting that belongs to one
 * module's own screens and nothing else stays in that module's own catalogue:
 * App\Service\Blog\BlogLocalizedSettings holds the Blog's title and
 * introduction in the same physical table, and CORE DOES NOT KNOW THOSE KEYS
 * EXIST.
 *
 * Everything else stays a language-neutral row in `site_settings`
 * (App\Service\SiteSettings): the company's name, e-mail address, phone and
 * KVK number, its postal address, the copyright template, every switch, the
 * logos. So does what is not the website's words at all: the CMS's own
 * interface language (App\Service\Language\AdminLocale) and module
 * configuration.
 *
 * THE FALLBACK is App\Service\Language\LanguageFallback's: the asked-for
 * language, the default language, ''. READS NEVER THROW (logged, read as no
 * words); writes do. One query per request for the whole catalogue.
 */
final class LocalizedSiteSettings
{
    public const CITY = 'city';
    public const FOOTER_DESCRIPTION = 'footer_description';
    public const FOOTER_SLOGAN = 'footer_slogan';
    public const RELATED_PRODUCTS_HEADING = 'related_products_heading';

    /** @var array<string, int> key => maximum length in characters */
    public const KEYS = [
        self::CITY => 150,
        self::FOOTER_DESCRIPTION => 500,
        self::FOOTER_SLOGAN => 200,
        self::RELATED_PRODUCTS_HEADING => 255,
    ];

    private static ?LocalizedSettings $store = null;

    /** Core's own catalogue. It never holds a key of a module's own screens. */
    public static function store(): LocalizedSettings
    {
        return self::$store ??= new LocalizedSettings(self::KEYS);
    }

    /**
     * Every language one key has words in.
     *
     * @return array<string, string> language code => words
     */
    public static function words(string $key): array
    {
        return self::store()->words($key);
    }

    /** The stored words in one language, no fallback: what an editor sees. */
    public static function raw(string $key, string $languageCode): string
    {
        return self::store()->raw($key, $languageCode);
    }

    /** The words a visitor gets in one language, with the fallback. */
    public static function value(string $key, string $languageCode): string
    {
        return self::store()->value($key, $languageCode);
    }

    /** Has the key words in the website's default language? */
    public static function hasDefault(string $key): bool
    {
        return self::store()->hasDefault($key);
    }

    /** The temporary V1 `data-nl`/`data-en` pair of one key. Plain text. */
    public static function bilingual(string $key): LocalizedValue
    {
        return self::store()->bilingual($key);
    }

    /**
     * Which of the given values are too long, by key. No localized setting is
     * required: each is optional in every language, and an empty one is left
     * out of the page.
     *
     * @param array<string, string|null> $values
     * @return array<string, string> key => 'too_long'
     */
    public static function problems(array $values): array
    {
        return self::store()->problems($values);
    }

    /**
     * Store one language's words for the keys given. Every other language,
     * and every key not given, stays as it is. An empty value removes that
     * language's row: "not translated" and "translated as nothing" are one
     * state.
     *
     * Takes part in a transaction already open on the shared connection.
     *
     * @param array<string, string|null> $values key => words
     *
     * @throws \InvalidArgumentException for a key outside the catalogue, an unregistered language or a value over its length
     */
    public static function save(string $languageCode, array $values): void
    {
        self::store()->save($languageCode, $values);
    }

    public static function clearCache(): void
    {
        self::store()->clearCache();
    }

    /**
     * Test seam: pretend the stored words are exactly $words, without a
     * database. null goes back to reading the database.
     *
     * @param array<string, array<string, string>>|null $words key => language code => words
     */
    public static function overrideForTests(?array $words): void
    {
        self::store()->overrideForTests($words);
    }
}
