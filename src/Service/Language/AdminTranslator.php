<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * The CMS interface's own words, in the language the signed-in administrator
 * reads.
 *
 * NOT machine translation, and never will be. These are curated application
 * strings: a button that says "Opslaan" must say "Save" and not whatever a
 * translation API returned this morning. Automatic translation in this
 * project applies to WEBSITE CONTENT only (App\Service\Translation), and the
 * two never meet.
 *
 * WHY A CATALOG AND NOT TWO SETS OF TEMPLATES. Duplicating admin/*.php into
 * a Dutch and an English copy would double every future edit and guarantee
 * the two drift. One template, one key per sentence, one file per language.
 *
 * HOW A KEY IS SHAPED: `<screen or area>.<thing>`, lowercase, dot-separated —
 * `common.save`, `account.title`, `language.website`. Keys are written in
 * source, never built from a request, and never from a database value.
 *
 * A MISSING KEY RENDERS ITS DUTCH TEXT, never an empty string and never the
 * raw key. Dutch is the catalog that is complete by construction (it is the
 * language this CMS was written in), so falling back to it always produces a
 * real sentence. An English screen with one untranslated word is a small
 * blemish; a blank button is a broken CMS. Tests\Service\AdminTranslatorTest
 * fails when a key exists in Dutch but not in English, so the blemish is
 * caught in the suite rather than by an editor.
 */
final class AdminTranslator
{
    /** @var array<string, array<string, string>> locale => key => text */
    private static array $catalogs = [];

    /**
     * The text for $key in the CMS interface language of the person signed
     * in, or in $locale when a caller needs a specific one (the account
     * screen previewing a language, a test).
     *
     * $replacements are substituted as `:name` placeholders. They are the
     * only dynamic part of a translated string; there is no formatting
     * language, no plural engine and no date localisation in V1.
     *
     * @param array<string, string|int> $replacements
     */
    public static function trans(string $key, array $replacements = [], ?string $locale = null): string
    {
        $wanted = $locale === null ? AdminLocale::current() : AdminLocale::normalise($locale);

        $text = self::catalog($wanted)[$key]
            ?? self::catalog(LanguageRegistry::DEFAULT_LANGUAGE)[$key]
            ?? $key;

        if ($replacements === []) {
            return $text;
        }

        $search = [];
        $replace = [];
        foreach ($replacements as $name => $value) {
            $search[] = ':' . $name;
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $text);
    }

    /** True when this key has real text in $locale rather than a fallback. */
    public static function has(string $key, ?string $locale = null): bool
    {
        $wanted = $locale === null ? AdminLocale::current() : AdminLocale::normalise($locale);

        return isset(self::catalog($wanted)[$key]);
    }

    /**
     * One language's whole catalog, loaded once per request.
     *
     * @return array<string, string>
     */
    public static function catalog(string $locale): array
    {
        $wanted = AdminLocale::normalise($locale);

        if (isset(self::$catalogs[$wanted])) {
            return self::$catalogs[$wanted];
        }

        // The path is built from a code that has already been through the
        // closed registry, so it can only ever name a file this project
        // ships. Never build it from a request.
        $file = __DIR__ . '/messages/' . $wanted . '.php';

        $messages = is_file($file) ? require $file : [];

        return self::$catalogs[$wanted] = is_array($messages) ? $messages : [];
    }

    /** Drop the loaded catalogs — tests only; they are static per process. */
    public static function clearCache(): void
    {
        self::$catalogs = [];
    }
}
