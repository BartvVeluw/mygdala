<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PageTranslationRepository;
use App\Service\Language\LanguageCode;
use App\Service\Language\LanguageFallback;
use App\Service\Language\SiteLanguages;

/**
 * THE way into a page's text in any website language (Multilingual 2.0,
 * docs/multilingual/ARCHITECTURE.md). Nothing else reads or writes
 * `page_translations`: not a template, not an endpoint, not the breadcrumb or
 * the SEO head.
 *
 * THE FALLBACK, per field, stated once:
 *
 *     the asked-for language  ->  the default language  ->  ''
 *
 * value() is that rule, for a visitor. raw() is the stored words with no
 * fallback, for an editor: a form that showed the default language's words
 * in an empty translation field would save them back as a translation.
 * Whether a whole language version of a page EXISTS for a URL is a different
 * question, and it is the routing phase's (there is one URL per page until
 * then).
 *
 * READS NEVER THROW, the convention App\Service\PageContent follows: a lookup
 * that fails is logged and reads as "no text", so a public request degrades
 * rather than dies. Writes do throw, because an editor must hear that a save
 * did not happen.
 *
 * Per request, one query per page (or one for a whole list, see preload()).
 */
final class PageLocalization
{
    /** @var array<int, array<string, PageTranslation>> page id => language code => translation */
    private static array $cache = [];

    /**
     * Every language this page has text in.
     *
     * @return array<string, PageTranslation> keyed by language code
     */
    public static function translations(int $pageId): array
    {
        if ($pageId < 1) {
            return [];
        }

        if (!array_key_exists($pageId, self::$cache)) {
            try {
                self::$cache[$pageId] = self::build((new PageTranslationRepository())->findForPage($pageId));
            } catch (\Throwable $e) {
                error_log('[PageLocalization] translations for page ' . $pageId . ' could not be read: ' . $e->getMessage());

                self::$cache[$pageId] = [];
            }
        }

        return self::$cache[$pageId];
    }

    /** One language's text, or null when the page has none in that language. */
    public static function translation(int $pageId, string $languageCode): ?PageTranslation
    {
        return self::translations($pageId)[$languageCode] ?? null;
    }

    /** Has this page any text in this language? */
    public static function has(int $pageId, string $languageCode): bool
    {
        return self::translation($pageId, $languageCode) !== null;
    }

    /**
     * The words stored for one field in one language, with NO fallback: what
     * an editor sees. '' is "not written in this language".
     */
    public static function raw(int $pageId, string $field, string $languageCode): string
    {
        self::assertField($field);

        return self::translation($pageId, $languageCode)?->value($field) ?? '';
    }

    /**
     * The words a visitor gets for one field in one language: that
     * language's own, else the default language's, else ''.
     */
    public static function value(int $pageId, string $field, string $languageCode): string
    {
        $own = self::raw($pageId, $field, $languageCode);
        if ($own !== '') {
            return $own;
        }

        $default = self::defaultLanguage();

        return $default === $languageCode ? '' : self::raw($pageId, $field, $default);
    }

    /**
     * This page's public address IN THIS LANGUAGE, or null when it has none
     * (Multilingual 2.0 phase 6, docs/multilingual/ROUTING.md).
     *
     * THE ONE READER IN THIS CLASS WITH NO FALLBACK, and that is the point.
     * Every field above falls back to the default language, because showing a
     * visitor the words they can read beats showing them nothing. An address
     * may not: falling back would publish this page in German at a URL that
     * says it is the German version, when no German version exists. "No slug"
     * therefore means "no route", and route existence is a different question
     * from field fallback — see docs/multilingual/ROUTING.md.
     *
     * A route-bound page has no slug in any language; its address is its
     * route (App\Service\PageContent::publicUrl()).
     */
    public static function slug(int $pageId, string $languageCode): ?string
    {
        $slug = self::translation($pageId, $languageCode)?->slug;

        return ($slug === null || $slug === '') ? null : $slug;
    }

    /**
     * Every language this page has a public address in, in registry order.
     *
     * What the language switch, the hreflang block and the sitemap all ask
     * before they name a URL.
     *
     * @return list<string>
     */
    public static function routableLanguages(int $pageId): array
    {
        $codes = [];

        foreach (SiteLanguages::activeCodes() as $code) {
            if (self::slug($pageId, $code) !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * The published page that owns one address in one language, or null.
     *
     * The public lookup behind every localized URL, here rather than on
     * App\Repository\PageRepository because the ADDRESS is what is being
     * looked up, and this class owns addresses along with the rest of
     * `page_translations`.
     *
     * Reads never throw: a lookup that failed is a page that does not exist,
     * which is what a visitor was about to be told anyway.
     *
     * @return array<string, mixed>|null a `pages` row
     */
    public static function pageForSlug(string $slug, string $languageCode): ?array
    {
        try {
            return (new PageTranslationRepository())->findPageBySlug($slug, $languageCode);
        } catch (\Throwable $e) {
            error_log('[PageLocalization] address lookup failed for "' . $slug . '": ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Is this address already another page's, in this language?
     *
     * Asked by App\Service\PageService before it lets a save through; here
     * because this class is the only reader of `page_translations`.
     *
     * @throws \RuntimeException when the question could not be answered —
     *         a slug check that silently said "free" would hand out an
     *         address that is somebody else's
     */
    public static function slugExists(string $slug, string $languageCode, ?int $excludePageId = null): bool
    {
        try {
            return (new PageTranslationRepository())->slugExists($slug, $languageCode, $excludePageId);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The localized page addresses could not be read.', 0, $e);
        }
    }

    /** The page's name in a language, with the fallback. */
    public static function title(int $pageId, string $languageCode): string
    {
        return self::value($pageId, PageTranslation::TITLE, $languageCode);
    }

    /**
     * What the CMS calls a page in its lists, pickers and back links: its
     * name in the website's default language.
     *
     * The one step beyond value(), and admin-only: a page with no name in
     * the default language (text that arrived in another language first) is
     * named by the first language that has one, in the registry's order,
     * because a nameless row in a list cannot be clicked with any confidence.
     * A visitor never gets this step.
     */
    public static function name(int $pageId): string
    {
        $name = self::title($pageId, self::defaultLanguage());
        if ($name !== '') {
            return $name;
        }

        $translations = self::translations($pageId);
        $order = array_map(static fn ($language): string => $language->code, SiteLanguages::all());

        foreach (array_unique(array_merge($order, array_keys($translations))) as $code) {
            $title = isset($translations[$code]) ? $translations[$code]->value(PageTranslation::TITLE) : '';
            if ($title !== '') {
                return $title;
            }
        }

        return '';
    }

    /**
     * Load the text of many pages in one query, for a screen that is about to
     * name every one of them. Pages already loaded are left alone.
     *
     * @param list<int> $pageIds
     */
    public static function preload(array $pageIds): void
    {
        $missing = array_values(array_filter(
            array_unique(array_map('intval', $pageIds)),
            static fn (int $id): bool => $id > 0 && !array_key_exists($id, self::$cache)
        ));

        if ($missing === []) {
            return;
        }

        try {
            $rows = (new PageTranslationRepository())->findForPages($missing);
        } catch (\Throwable $e) {
            error_log('[PageLocalization] preloading ' . count($missing) . ' pages failed: ' . $e->getMessage());

            return;
        }

        foreach ($missing as $pageId) {
            self::$cache[$pageId] = self::build($rows[$pageId] ?? []);
        }
    }

    /**
     * Store one language's text for one page: all three fields, as given.
     *
     * Empty fields are stored as NULL, and a language left with no words at
     * all has no row: "not translated" and "translated as nothing" are the
     * same thing, and exactly one of them is stored.
     *
     * Takes part in a transaction already open on the shared connection.
     *
     * @param array<string, string|null> $fields keyed by PageTranslation::FIELDS; a missing key is empty
     * @param string|null                $slug   this language's public address, or null for none.
     *                                           Already sanitized and validated by the caller
     *                                           (App\Service\PageService) — this class stores it,
     *                                           it does not decide whether it is allowed.
     *
     * @throws \InvalidArgumentException when the language is not a registered website language
     */
    public static function save(int $pageId, string $languageCode, array $fields, ?string $slug = null): void
    {
        $code = LanguageCode::normalise($languageCode);

        if ($pageId < 1 || $code === null || !SiteLanguages::exists($code)) {
            throw new \InvalidArgumentException('Page text can only be stored for an existing page in a registered website language.');
        }

        foreach (array_keys($fields) as $field) {
            self::assertField((string) $field);
        }

        $translation = new PageTranslation(
            pageId: $pageId,
            languageCode: $code,
            title: PageTranslation::textOrNull($fields[PageTranslation::TITLE] ?? null),
            metaTitle: PageTranslation::textOrNull($fields[PageTranslation::META_TITLE] ?? null),
            metaDescription: PageTranslation::textOrNull($fields[PageTranslation::META_DESCRIPTION] ?? null),
            slug: PageTranslation::textOrNull($slug),
        );

        $repository = new PageTranslationRepository();

        if ($translation->isEmpty()) {
            $repository->delete($pageId, $code);
        } else {
            $repository->save(
                $pageId,
                $code,
                $translation->title,
                $translation->metaTitle,
                $translation->metaDescription,
                $translation->slug
            );
        }

        unset(self::$cache[$pageId]);
    }

    /** The website's default language: the language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    /** Drop the per-request cache. PageContent::clearCache() calls this too. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Test seam: pretend this page's text is exactly $translations, without a
     * database. Reset with clearCache() in tearDown(): the cache is static.
     *
     * @param list<PageTranslation> $translations
     */
    public static function overrideForTests(int $pageId, array $translations): void
    {
        $rows = [];
        foreach ($translations as $translation) {
            $rows[$translation->languageCode] = $translation;
        }

        self::$cache[$pageId] = $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows keyed by language code
     * @return array<string, PageTranslation>
     */
    private static function build(array $rows): array
    {
        $translations = [];
        foreach ($rows as $row) {
            $translation = PageTranslation::fromRow($row);
            if ($translation !== null) {
                $translations[$translation->languageCode] = $translation;
            }
        }

        return $translations;
    }

    private static function assertField(string $field): void
    {
        if (!in_array($field, PageTranslation::FIELDS, true)) {
            throw new \InvalidArgumentException('A page has no localized field "' . $field . '".');
        }
    }
}
