<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Repository\SiteLanguageRepository;

/**
 * THE languages of this WEBSITE: which exist, which are active, in which
 * order, and which one is the default (docs/multilingual/ARCHITECTURE.md).
 *
 * The Core API of Multilingual 2.0. Everything it answers comes from the
 * `site_languages` registry; it knows no language by name, so a site with
 * German or French needs rows, not code. The rules about that registry are
 * enforced by App\Repository\SiteLanguageRepository.
 *
 * WHAT THIS IS NOT:
 *
 *   - the CMS interface language: that is a preference per administrator,
 *     App\Service\Language\AdminLocale, with its own list. Nothing here
 *     reads it, and it does not read this.
 *   - the V1 bilingual contract: App\Service\Language\ContentLanguages
 *     still decides what the NL/EN switch and the editors show. Until the
 *     frontend flip only its primary language comes from here.
 *   - a module check. Multilingual as a module does not exist yet, and when
 *     it does, Core still asks this class and nothing else.
 *
 * Read once per request, like App\Service\SiteSettings. When the registry
 * cannot be read, the failure is logged once and the registry counts as
 * empty; defaultLanguage() then throws, so a caller never mistakes a broken
 * installation for a language choice.
 */
final class SiteLanguages
{
    /** @var list<SiteLanguage>|null */
    private static ?array $languages = null;

    /** @return list<SiteLanguage> every registered language, in order */
    public static function all(): array
    {
        if (self::$languages !== null) {
            return self::$languages;
        }

        $languages = [];
        try {
            foreach ((new SiteLanguageRepository())->findAll() as $row) {
                $language = SiteLanguage::fromRow($row);
                if ($language !== null) {
                    $languages[] = $language;
                }
            }
        } catch (\Throwable $e) {
            error_log('[SiteLanguages] the language registry could not be read: ' . $e->getMessage());
        }

        return self::$languages = self::sorted($languages);
    }

    /** @return list<SiteLanguage> the active languages, in order */
    public static function active(): array
    {
        return array_values(array_filter(self::all(), static fn (SiteLanguage $l): bool => $l->isActive));
    }

    /** @return list<string> the codes of the active languages, in order */
    public static function activeCodes(): array
    {
        return array_map(static fn (SiteLanguage $l): string => $l->code, self::active());
    }

    /**
     * The default language.
     *
     * @throws \RuntimeException when the registry does not have exactly one
     *                           default, or that default is not active
     */
    public static function defaultLanguage(): SiteLanguage
    {
        $defaults = array_values(array_filter(self::all(), static fn (SiteLanguage $l): bool => $l->isDefault));

        if (count($defaults) !== 1) {
            throw new \RuntimeException(sprintf(
                'The website language registry has %d default languages; it needs exactly one.',
                count($defaults)
            ));
        }

        if (!$defaults[0]->isActive) {
            throw new \RuntimeException(sprintf('The default website language "%s" is not active.', $defaults[0]->code));
        }

        return $defaults[0];
    }

    /** @throws \RuntimeException see defaultLanguage() */
    public static function defaultCode(): string
    {
        return self::defaultLanguage()->code;
    }

    /** The registered language with this code, or null for anything else. */
    public static function find(string $code): ?SiteLanguage
    {
        $normalised = LanguageCode::normalise($code);
        if ($normalised === null) {
            return null;
        }

        foreach (self::all() as $language) {
            if ($language->code === $normalised) {
                return $language;
            }
        }

        return null;
    }

    public static function exists(string $code): bool
    {
        return self::find($code) !== null;
    }

    public static function isActive(string $code): bool
    {
        return self::find($code)?->isActive ?? false;
    }

    /**
     * Make a registered, active language the default.
     *
     * Takes part in a transaction that is already open on the shared
     * connection, as the Setup Wizard's is.
     *
     * @throws \InvalidArgumentException when $code is not a registered, active language
     */
    public static function setDefault(string $code): void
    {
        $normalised = LanguageCode::normalise($code);

        if ($normalised === null || !(new SiteLanguageRepository())->setDefault($normalised)) {
            throw new \InvalidArgumentException('Only a registered, active website language can be the default.');
        }

        self::clearCache();
    }

    /** Drop the per-request cache. Called after a write, and by tests. */
    public static function clearCache(): void
    {
        self::$languages = null;
    }

    /**
     * Test seam: pretend these are the registered languages, without a
     * database. Pass null to go back to reading storage. Always reset it in
     * tearDown(): the cache is static and outlives one test.
     *
     * @param list<SiteLanguage>|null $languages
     */
    public static function overrideForTests(?array $languages): void
    {
        self::$languages = $languages === null ? null : self::sorted($languages);
    }

    /**
     * By sort_order. The sort is stable, so rows that share a position keep
     * the order they arrived in, which for storage is the id.
     *
     * @param list<SiteLanguage> $languages
     * @return list<SiteLanguage>
     */
    private static function sorted(array $languages): array
    {
        usort($languages, static fn (SiteLanguage $a, SiteLanguage $b): int => $a->sortOrder <=> $b->sortOrder);

        return $languages;
    }
}
