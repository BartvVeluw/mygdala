<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Language\LanguageFallback;
use App\Service\Language\LocalizedSettings;

/**
 * THE way into the two Blog settings that are WEBSITE TEXT in a language
 * (Multilingual 2.0 phase 5, docs/multilingual/ARCHITECTURE.md, BLOG.md):
 * what the listing is called and the paragraph under that heading.
 *
 *   blog_title   the heading of /blog, the breadcrumb to it, the feed's
 *                channel title and the tail of every Blog page title
 *   blog_intro   the optional paragraph under that heading, which is also
 *                the listing's meta description and the feed's channel
 *                description
 *
 * A CLOSED CATALOGUE OF TWO, held by the Blog itself. The words live in the
 * one physical localized-settings table, `site_setting_translations`, through
 * App\Service\Language\LocalizedSettings — the same store
 * App\Service\LocalizedSiteSettings uses for Core's own website text. THE
 * STORAGE IS SHARED, THE CATALOGUE IS NOT: Core's catalogue does not name
 * `blog_title`, so Core never learns that a blog exists (MODULES.md), and
 * this class does not know what Core keeps in the rows beside it. A request
 * cannot reach either catalogue's keys: both are closed lists in code.
 *
 * WHY NOT `blog_settings`. Until phase 5 these two were four fixed keys in
 * the Blog's own key/value table — `blog_title`/`blog_title_en` and
 * `blog_intro`/`blog_intro_en` — which is exactly the Dutch/English storage
 * Multilingual 2.0 removes: a third website language had nowhere to go.
 * `blog_settings` keeps everything that is the same in every language (the
 * page size and the four switches); its words moved here in 20260918260000.
 *
 * THE FALLBACK is App\Service\Language\LanguageFallback's, once, through the
 * store: the asked-for language, the default language, ''. There is no
 * Dutch-last rule left, no `_en` branch and no fallback in a template.
 *
 * THE CODE DEFAULT survives the move. A Blog whose title has never been typed
 * is still called "Blog" — in every language, because the name of a thing
 * that has no name is not a translation. An empty introduction stays empty:
 * the paragraph is simply not printed.
 *
 * MODULE OFF CHANGES NOTHING. Switching the Blog off removes no row and no
 * word, and switching it back on shows the same texts: this class never asks
 * whether the module is enabled.
 */
final class BlogLocalizedSettings
{
    public const TITLE = 'blog_title';
    public const INTRO = 'blog_intro';

    public const TITLE_MAX_LENGTH = 150;
    public const INTRO_MAX_LENGTH = 1000;

    /** What the listing is called when nobody has renamed it, in any language. */
    public const DEFAULT_TITLE = 'Blog';

    /** @var array<string, int> key => maximum length in characters */
    public const KEYS = [
        self::TITLE => self::TITLE_MAX_LENGTH,
        self::INTRO => self::INTRO_MAX_LENGTH,
    ];

    private static ?LocalizedSettings $store = null;

    public static function store(): LocalizedSettings
    {
        return self::$store ??= new LocalizedSettings(self::KEYS);
    }

    /* ------------------------------------------------------------------ */
    /* What a visitor gets                                                 */
    /* ------------------------------------------------------------------ */

    /** The listing's heading in one language, with the fallback and the code default. */
    public static function title(string $languageCode): string
    {
        $title = self::store()->value(self::TITLE, $languageCode);

        return $title !== '' ? $title : self::DEFAULT_TITLE;
    }

    /** The optional paragraph under that heading. '' means: print none. */
    public static function intro(string $languageCode): string
    {
        return self::store()->value(self::INTRO, $languageCode);
    }

    /* ------------------------------------------------------------------ */
    /* What an editor sees and writes                                      */
    /* ------------------------------------------------------------------ */

    /** The stored words in one language, no fallback and no code default. */
    public static function raw(string $key, string $languageCode): string
    {
        return self::store()->raw($key, $languageCode);
    }

    /**
     * Which of the given values are too long, by key.
     *
     * @param array<string, string|null> $values
     * @return array<string, string> key => 'too_long'
     */
    public static function problems(array $values): array
    {
        return self::store()->problems($values);
    }

    /**
     * Store one language's words. Every other language stays as it is, and an
     * empty value removes that language's row.
     *
     * @param array<string, string|null> $values key => words
     *
     * @throws \InvalidArgumentException for a key outside this catalogue, an unregistered language or a value over its length
     */
    public static function save(string $languageCode, array $values): void
    {
        self::store()->save($languageCode, $values);
    }

    /** The language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::store()->clearCache();
    }

    /**
     * Test seam: pretend these are the stored words, without a database. null
     * goes back to reading storage; always reset in tearDown().
     *
     * @param array<string, array<string, string>>|null $words key => language code => words
     */
    public static function overrideForTests(?array $words): void
    {
        self::store()->overrideForTests($words);
    }
}
