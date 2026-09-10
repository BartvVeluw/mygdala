<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * THE list of languages this CMS knows about.
 *
 * Closed and written in code, for exactly the same reason as
 * App\Service\Blocks\BlockDefinitions, App\Module\ModuleRegistry,
 * App\Service\Theme\ThemeFonts and App\Service\SocialProfiles: a language
 * code arrives from an admin form, from a settings row and from a URL, and
 * the only thing such a value may ever do is hit a key of this list or miss
 * it. Missing is missing; it never becomes a column name, a class name, a
 * file path or a provider parameter.
 *
 * That last point is not decoration. A language code is concatenated into
 * column names (`title_` . $code), so a code that could come from a request
 * would be an SQL injection surface. It cannot: every reader goes through
 * ::has() or ::get() first.
 *
 * V1 registers Dutch and English, which is what the existing `_nl`/`_en`
 * columns can store (MULTILINGUAL.md). Adding German later is one entry
 * here plus storage for it — no editor, no renderer and no provider changes.
 * The two `availableAs*` flags exist so a language can arrive in stages: a
 * curated CMS interface translation and website content storage are separate
 * questions with separate answers.
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
                availableAsContentLanguage: true,
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
                availableAsContentLanguage: true,
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

    /** @return array<string, LanguageDefinition> the ones a website can store content in */
    public static function contentLanguages(): array
    {
        return array_filter(self::all(), static fn (LanguageDefinition $d): bool => $d->availableAsContentLanguage);
    }

    /**
     * Keep only codes this CMS knows, in registry order, without duplicates.
     * Every settings reader and every request handler runs its input through
     * here, so an unknown code is dropped rather than rejected loudly — a
     * stored row from a future version must never lock an editor out.
     *
     * @param string[] $codes
     * @return string[]
     */
    public static function filter(array $codes): array
    {
        $wanted = [];
        foreach ($codes as $code) {
            if (is_string($code) && self::has($code)) {
                $wanted[$code] = true;
            }
        }

        return array_values(array_filter(self::codes(), static fn (string $c): bool => isset($wanted[$c])));
    }

    /** The label of $code inside a CMS interface running in $locale. */
    public static function label(string $code, string $locale = self::DEFAULT_LANGUAGE): string
    {
        $definition = self::get($code);

        return $definition === null ? $code : $definition->labelIn($locale);
    }
}
