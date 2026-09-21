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
     * to. THE one place that answers it when the registry cannot be read (the
     * reason is already logged by App\Service\Language\SiteLanguages): the
     * project's own default, a language every installation has words in.
     * Every other "default language, even when the registry is down" asks
     * this method rather than keeping a second answer.
     */
    public static function defaultLanguage(): string
    {
        try {
            return SiteLanguages::defaultCode();
        } catch (\RuntimeException) {
            return LanguageRegistry::DEFAULT_LANGUAGE;
        }
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
}
