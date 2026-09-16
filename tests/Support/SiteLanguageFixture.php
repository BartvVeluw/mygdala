<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\Language\SiteLanguage;
use App\Service\Language\SiteLanguages;

/**
 * An in-memory website language registry for tests that must not open a
 * database (the `unit` and `contract` tiers, TESTING.md).
 *
 * ::bilingual() is the registry every existing installation got from
 * db/migrations/20260917120000: Dutch and English, both active, the default
 * first. It replaces what those tests used to do with
 * SiteSettings::overrideForTests(['primary_content_language' => …]), a row
 * that no longer exists.
 *
 * Always call ::reset() in tearDown(): the override is static.
 */
final class SiteLanguageFixture
{
    private const NAMES = [
        'nl' => ['Dutch', 'Nederlands'],
        'en' => ['English', 'English'],
        'de' => ['German', 'Deutsch'],
        'fr' => ['French', 'Français'],
    ];

    /** Pretend the registry is Dutch plus English with $default as the default. */
    public static function useBilingual(string $default = 'nl'): void
    {
        SiteLanguages::overrideForTests(self::bilingual($default));
    }

    /** @param list<SiteLanguage> $languages */
    public static function useLanguages(array $languages): void
    {
        SiteLanguages::overrideForTests($languages);
    }

    public static function reset(): void
    {
        SiteLanguages::overrideForTests(null);
    }

    /** @return list<SiteLanguage> */
    public static function bilingual(string $default = 'nl'): array
    {
        $other = $default === 'en' ? 'nl' : 'en';

        return [
            self::language($default, isDefault: true, sortOrder: 0),
            self::language($other, sortOrder: 1),
        ];
    }

    public static function language(
        string $code,
        bool $isDefault = false,
        bool $isActive = true,
        int $sortOrder = 0,
    ): SiteLanguage {
        [$name, $nativeName] = self::NAMES[$code] ?? [$code, $code];

        return new SiteLanguage($code, $name, $nativeName, $isDefault, $isActive, $sortOrder);
    }
}
