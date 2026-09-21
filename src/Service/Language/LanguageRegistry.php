<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * THE languages the CMS ITSELF speaks: the languages its own screens are
 * translated into (App\Service\Language\AdminLocale), what it calls each of
 * them, and the codes a translation provider uses for them.
 *
 * NOT the website's languages. Those are rows in `site_languages`
 * (App\Service\Language\SiteLanguages): a website in German is a row there
 * and nothing here, and a CMS interface in German would be an entry here and
 * nothing there. Multilingual 2.0 phase 7 removed the last place where this
 * list decided what the website publishes.
 *
 * Closed and written in code, for exactly the same reason as
 * App\Service\Blocks\BlockDefinitions, App\Module\ModuleRegistry,
 * App\Service\Theme\ThemeFonts and App\Service\SocialProfiles: a language
 * code arrives from an admin form and from a settings row, and the only
 * thing such a value may ever do is hit a key of this list or miss it.
 * Missing is missing; it never becomes a class name, a file path or a
 * provider parameter.
 */
final class LanguageRegistry
{
    public const DUTCH = 'nl';
    public const ENGLISH = 'en';

    /**
     * The fallback for every "nobody has chosen anything" question. Dutch,
     * because that is what every existing installation of this CMS stores in
     * its unsuffixed and `_nl` columns; changing it would silently reinterpret
     * their content.
     */
    public const DEFAULT_LANGUAGE = self::DUTCH;

    /** @var array<string, LanguageDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string, LanguageDefinition> code => definition, in display order */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        return self::$definitions = [
            self::DUTCH => new LanguageDefinition(
                code: self::DUTCH,
                nativeLabel: 'Nederlands',
                dutchLabel: 'Nederlands',
                englishLabel: 'Dutch',
                deeplSource: 'NL',
                deeplTarget: 'NL',
                availableAsAdminLocale: true,
            ),
            self::ENGLISH => new LanguageDefinition(
                code: self::ENGLISH,
                nativeLabel: 'English',
                dutchLabel: 'Engels',
                englishLabel: 'English',
                deeplSource: 'EN',
                // DeepL wants a variant for English targets; EN-GB is the
                // other option and neither is more correct for a Dutch site.
                deeplTarget: 'EN-GB',
                availableAsAdminLocale: true,
            ),
        ];
    }

    public static function has(string $code): bool
    {
        return isset(self::all()[$code]);
    }

    /** The definition, or null for anything this CMS does not know. */
    public static function get(string $code): ?LanguageDefinition
    {
        return self::all()[$code] ?? null;
    }

    /** @return string[] every registered code, in display order */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, LanguageDefinition> the ones a CMS interface can run in */
    public static function adminLocales(): array
    {
        return array_filter(self::all(), static fn (LanguageDefinition $d): bool => $d->availableAsAdminLocale);
    }

    /** The label of $code inside a CMS interface running in $locale. */
    public static function label(string $code, string $locale = self::DEFAULT_LANGUAGE): string
    {
        $definition = self::get($code);

        return $definition === null ? $code : $definition->labelIn($locale);
    }
}
