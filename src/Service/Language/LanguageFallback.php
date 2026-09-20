<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * THE fallback of Multilingual 2.0 for everything phase 4 converts: menu
 * items, footer columns and links, localized site settings, forms, form
 * fields and their options (docs/multilingual/ARCHITECTURE.md).
 *
 *     the asked-for language  ->  the default language  ->  ''
 *
 * Stated once, here, and applied to words that arrive already grouped per
 * language. It knows no domain, no table and no language by name: the
 * callers (App\Service\Language\EntityTranslations for the typed translation
 * tables, App\Service\LocalizedSiteSettings for the settings) hand it the
 * stored words, and every screen, partial, validator and e-mail of those
 * domains asks them rather than deciding a fallback of its own.
 *
 * App\Service\PageLocalization and App\Service\Blocks\BlockLocalization state
 * the same rule for their own stores (phases 2 and 3) and are left as they
 * are.
 */
final class LanguageFallback
{
    /**
     * The website's default language: the language every field falls back
     * to. When the registry cannot answer, the V1 adapter's answer, which is
     * a language every existing installation has words in.
     */
    public static function defaultLanguage(): string
    {
        try {
            return SiteLanguages::defaultCode();
        } catch (\RuntimeException) {
            return ContentLanguages::primary();
        }
    }

    /**
     * Every language a PUBLIC PAGE may have to render right now: the closed
     * V1 registry, plus every language this site has switched on.
     *
     * The two lists are the same on a site that publishes Dutch and English,
     * which is every installation that exists today — so this changes nothing
     * for them. It matters the moment somebody adds a third: the storage has
     * been per-language since phases 2 to 5, the router serves /de/ since
     * phase 6, and the only thing still narrowing a page to two languages was
     * the closed V1 list this widens. Adding German is then a registry row,
     * as App\Service\Language\SiteLanguages has always promised.
     *
     * The V1 `data-nl`/`data-en` ATTRIBUTES deliberately do NOT widen: they
     * are a compatibility output that phase 7 removes, and giving them a
     * third half would be building something new on top of the thing being
     * taken away.
     *
     * @return list<string> registry order first, then the site's own order
     */
    public static function renderableLanguages(): array
    {
        $codes = LanguageRegistry::codes();

        foreach (SiteLanguages::activeCodes() as $code) {
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return array_values($codes);
    }

    /**
     * The words a visitor gets in one language: its own, else the default
     * language's, else ''.
     *
     * @param array<string, string> $wordsByLanguage language code => stored words; a missing code is "none"
     */
    public static function resolve(array $wordsByLanguage, string $languageCode, ?string $defaultLanguage = null): string
    {
        $own = trim((string) ($wordsByLanguage[$languageCode] ?? ''));
        if ($own !== '') {
            return $own;
        }

        $default = $defaultLanguage ?? self::defaultLanguage();

        return $default === $languageCode ? '' : trim((string) ($wordsByLanguage[$default] ?? ''));
    }

    /**
     * What the CMS calls something in its lists and headings: the words in
     * the default language, else those of the first language that has any,
     * in the registry's order.
     *
     * The one step beyond resolve(), and admin-only, for the reason
     * App\Service\PageLocalization::name() gives: a nameless row in a list
     * cannot be clicked with any confidence. A visitor never gets this step.
     *
     * @param array<string, string> $wordsByLanguage
     */
    public static function name(array $wordsByLanguage): string
    {
        $name = self::resolve($wordsByLanguage, self::defaultLanguage());
        if ($name !== '') {
            return $name;
        }

        $order = array_map(static fn (SiteLanguage $language): string => $language->code, SiteLanguages::all());

        foreach (array_unique(array_merge($order, array_map('strval', array_keys($wordsByLanguage)))) as $code) {
            $words = trim((string) ($wordsByLanguage[$code] ?? ''));
            if ($words !== '') {
                return $words;
            }
        }

        return '';
    }

    /**
     * TEMPORARY OUTPUT ADAPTER for the V1 public language switch, until the
     * frontend flip: the `data-nl`/`data-en` pair, with each half already
     * resolved by resolve(). The two codes come from the closed V1 registry,
     * not from here, and no new NL/EN storage is involved.
     *
     * Plain text or sanitized markup alike: whether a value may be printed as
     * HTML is the caller's business (App\Service\Language\SiteText), never
     * decided here.
     *
     * @param array<string, string> $wordsByLanguage
     */
    public static function bilingual(array $wordsByLanguage): LocalizedValue
    {
        $default = self::defaultLanguage();
        $values = [];

        // Every language that could have to be printed, not only the V1 pair:
        // the visible half of the result is the REQUEST's language since
        // phase 6 (App\Service\Language\SiteText::visibleOf()), and a third
        // language whose words were dropped here would render the default
        // language's on a URL that promised its own.
        foreach (self::renderableLanguages() as $code) {
            $values[$code] = self::resolve($wordsByLanguage, $code, $default);
        }

        return LocalizedValue::of($values);
    }
}
