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
 *   - a module check by name. Whether the website publishes more than its
 *     default language is App\Module\MultilingualModule's to say, and this
 *     class is the one place that asks — by capability, through
 *     ModuleRegistry::publishesTranslations() — so every route, switch,
 *     editor and endpoint that asks this class follows the module without
 *     knowing it exists. See active().
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

    /** Test seam for publishesTranslations(); null asks the module registry. */
    private static ?bool $publishesTranslations = null;

    /** The longest name a language may have (site_languages.name and native_name). */
    public const NAME_MAX_LENGTH = 64;

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

    /**
     * The languages the website PUBLISHES right now, in order.
     *
     * The registry's active rows while the Multilingual module is on. While
     * it is off, only the default language: the other rows keep their own
     * active flag and every translation stays stored, but nothing is
     * published in them — no prefix, no route, no switch, no sitemap entry,
     * no hreflang, no negotiation, no editor field. Switching the module on
     * again brings the same languages back at the same addresses.
     *
     * @return list<SiteLanguage>
     */
    public static function active(): array
    {
        $active = array_values(array_filter(self::all(), static fn (SiteLanguage $l): bool => $l->isActive));

        if (self::publishesTranslations()) {
            return $active;
        }

        return array_values(array_filter($active, static fn (SiteLanguage $l): bool => $l->isDefault));
    }

    /**
     * The languages switched ON in the registry, published or not: what the
     * default language may be chosen from. The default is Core — a website
     * always has one, with the Multilingual module on or off — so choosing it
     * never depends on whether the other languages are published right now
     * (active()).
     *
     * @return list<SiteLanguage>
     */
    public static function switchedOn(): array
    {
        return array_values(array_filter(self::all(), static fn (SiteLanguage $l): bool => $l->isActive));
    }

    /** Does the website publish more than its default language (App\Module\MultilingualModule)? */
    public static function publishesTranslations(): bool
    {
        return self::$publishesTranslations ?? \App\Module\ModuleRegistry::publishesTranslations();
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

    /** Is $code a language the website publishes right now (active())? */
    public static function isActive(string $code): bool
    {
        $normalised = LanguageCode::normalise($code);

        foreach (self::active() as $language) {
            if ($language->code === $normalised) {
                return true;
            }
        }

        return false;
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

    // ----------------------------------------------------- managing the registry

    /**
     * Register a new, non-default language at the end of the order. Inactive
     * until somebody switches it on, so a language nobody has translated yet
     * is never published by accident.
     *
     * @throws \InvalidArgumentException 'code' (not a language code), 'exists', 'name'
     */
    public static function add(string $code, string $name, string $nativeName): void
    {
        $normalised = LanguageCode::normalise($code);
        if ($normalised === null) {
            throw new \InvalidArgumentException('code');
        }
        if (self::exists($normalised)) {
            throw new \InvalidArgumentException('exists');
        }

        [$name, $nativeName] = self::names($name, $nativeName);

        try {
            (new SiteLanguageRepository())->create($normalised, $name, $nativeName, false);
        } catch (\PDOException) {
            // Only the unique code can refuse this insert: somebody else
            // registered the same language a moment ago.
            throw new \InvalidArgumentException('exists');
        }

        self::clearCache();
    }

    /**
     * What a language is called: in English (the registry's `name`) and in
     * itself (what the language switch shows a visitor).
     *
     * @throws \InvalidArgumentException 'unknown', 'name'
     */
    public static function rename(string $code, string $name, string $nativeName): void
    {
        [$name, $nativeName] = self::names($name, $nativeName);

        if (!self::exists($code) || !(new SiteLanguageRepository())->rename((string) LanguageCode::normalise($code), $name, $nativeName)) {
            throw new \InvalidArgumentException('unknown');
        }

        self::clearCache();
    }

    /** @throws \InvalidArgumentException 'unknown' */
    public static function activate(string $code): void
    {
        $normalised = LanguageCode::normalise($code);
        if ($normalised === null || !(new SiteLanguageRepository())->activate($normalised)) {
            throw new \InvalidArgumentException('unknown');
        }

        self::clearCache();
    }

    /**
     * Stop publishing a language. Its words stay stored. Never the default:
     * a website always publishes its default language.
     *
     * @throws \InvalidArgumentException 'default', 'unknown'
     */
    public static function deactivate(string $code): void
    {
        $language = self::find($code);
        if ($language === null) {
            throw new \InvalidArgumentException('unknown');
        }
        if ($language->isDefault) {
            throw new \InvalidArgumentException('default');
        }
        if (!(new SiteLanguageRepository())->deactivate($language->code)) {
            throw new \InvalidArgumentException('unknown');
        }

        self::clearCache();
    }

    /** Move a language one place up (-1) or down (+1) in the order. False at an end. */
    public static function move(string $code, int $direction): bool
    {
        $language = self::find($code);
        if ($language === null || $direction === 0) {
            return false;
        }

        $moved = (new SiteLanguageRepository())->move($language->code, $direction < 0 ? -1 : 1);
        self::clearCache();

        return $moved;
    }

    /**
     * Remove a language from the registry. Only a language that is neither
     * the default nor published, and that holds no words anywhere: every
     * translation table refuses to lose its language (ON DELETE RESTRICT), so
     * a language with words can only be switched off, never taken away with
     * its translations.
     *
     * @throws \InvalidArgumentException 'default', 'active', 'in_use', 'unknown'
     */
    public static function remove(string $code): void
    {
        $language = self::find($code);
        if ($language === null) {
            throw new \InvalidArgumentException('unknown');
        }
        if ($language->isDefault) {
            throw new \InvalidArgumentException('default');
        }
        if ($language->isActive) {
            throw new \InvalidArgumentException('active');
        }

        try {
            $removed = (new SiteLanguageRepository())->delete($language->code);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new \InvalidArgumentException('in_use');
            }

            throw $e;
        }

        if (!$removed) {
            throw new \InvalidArgumentException('unknown');
        }

        self::clearCache();
    }

    /**
     * @return array{0: string, 1: string} the two names, trimmed
     * @throws \InvalidArgumentException 'name'
     */
    private static function names(string $name, string $nativeName): array
    {
        $name = trim($name);
        $nativeName = trim($nativeName);

        foreach ([$name, $nativeName] as $value) {
            if ($value === '' || mb_strlen($value) > self::NAME_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
                throw new \InvalidArgumentException('name');
            }
        }

        return [$name, $nativeName];
    }

    /** Drop the per-request cache. Called after a write, and by tests. */
    public static function clearCache(): void
    {
        self::$languages = null;
    }

    /**
     * Test seam: pretend these are the registered languages, without a
     * database, and that they are published (true), only the default is
     * (false), or that the module registry decides (null). Pass null
     * languages to go back to reading storage and the module registry.
     * Always reset it in tearDown(): the cache is static and outlives one
     * test.
     *
     * @param list<SiteLanguage>|null $languages
     */
    public static function overrideForTests(?array $languages, ?bool $publishesTranslations = true): void
    {
        self::$languages = $languages === null ? null : self::sorted($languages);
        self::$publishesTranslations = $languages === null ? null : $publishesTranslations;
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
