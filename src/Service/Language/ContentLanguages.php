<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * Which languages the WEBSITE publishes content in, in the shape the V1
 * bilingual code still expects.
 *
 * This is one of three language questions that this project deliberately
 * keeps apart (MULTILINGUAL.md):
 *
 *   - which language the CMS interface runs in     -> AdminLocale, per admin user
 *   - which language version an editor is writing  -> ContentEditingLanguage, per admin user
 *   - which languages the website publishes        -> here, per site
 *
 * Changing either per-person preference must never change a word of public
 * content, and changing the site's languages must never change either
 * person's preference. Three states, three owners, no shared storage.
 *
 * A COMPATIBILITY ADAPTER SINCE MULTILINGUAL 2.0 PHASE 1
 * (docs/multilingual/ARCHITECTURE.md). The site's default language is no
 * longer a settings row: it is the default of the website language registry,
 * App\Service\Language\SiteLanguages, and ::primary() reads it from there.
 * Everything else here is V1 and stays exactly as it was until the frontend
 * flip:
 *
 *   - ::enabled() answers from the closed V1 LanguageRegistry, NOT from the
 *     registry's active languages. Every language the `_nl`/`_en` columns
 *     can store is published, and no stored flag can take one away from a
 *     visitor or an editor.
 *   - ::primary() is narrowed to a language those columns can store. A
 *     registry default they cannot store falls back to Dutch, as an unknown
 *     settings value did before.
 *
 * WHAT CHANGED AFTER V1, and why ::enabled() does not read a row. V1 let an
 * owner switch English OFF for the whole site, and that one setting was wired
 * into three unrelated things: whether the public header offered a language
 * switch, whether an editor could see English fields at all, and whether
 * automatic translation was offered. The product is bilingual — Dutch and
 * English, always — so a site that publishes only one of them is not a state
 * this CMS has any reason to produce, and making it reachable cost editors
 * the ability to write English at all.
 */
final class ContentLanguages
{
    /** One primary plus at most one secondary — Dutch and English in V1. */
    public const MAX_ENABLED = 2;

    /**
     * The site's primary content language: the default of the website
     * language registry.
     *
     * Falls back to Dutch whenever the registry cannot answer, or answers
     * with a language this build cannot store in its `_nl`/`_en` columns —
     * the only value under which every existing installation's unsuffixed
     * and `_nl` columns keep meaning what they meant. SiteLanguages has
     * already logged a registry it could not read.
     */
    public static function primary(): string
    {
        try {
            $code = SiteLanguages::defaultCode();
        } catch (\RuntimeException) {
            return LanguageRegistry::DEFAULT_LANGUAGE;
        }

        $definition = LanguageRegistry::get($code);
        if ($definition !== null && $definition->availableAsContentLanguage) {
            return $definition->code;
        }

        return LanguageRegistry::DEFAULT_LANGUAGE;
    }

    /**
     * Every language this website publishes, primary first.
     *
     * Answered from the closed V1 registry, NOT from a stored row: Dutch and
     * English are both always available to a visitor and to an editor. See
     * the class docblock for why.
     *
     * The primary language is ALWAYS first, because "first" is what the
     * public switch, the editor's default and the fallback rule all mean by
     * it.
     *
     * @return string[]
     */
    public static function enabled(): array
    {
        $primary = self::primary();

        $enabled = [$primary];
        foreach (LanguageRegistry::contentLanguages() as $code => $definition) {
            if ($code !== $primary) {
                $enabled[] = $code;
            }
        }

        return array_slice($enabled, 0, self::MAX_ENABLED);
    }

    /**
     * The languages that are NOT the primary one, in the order an editor
     * meets them. Empty on a single-language site, and an editor that gets an
     * empty list here renders no language tabs at all.
     *
     * @return string[]
     */
    public static function secondaries(): array
    {
        $primary = self::primary();

        return array_values(array_filter(self::enabled(), static fn (string $c): bool => $c !== $primary));
    }

    /** The single secondary language in V1, or null on a single-language site. */
    public static function secondary(): ?string
    {
        return self::secondaries()[0] ?? null;
    }

    public static function isEnabled(string $code): bool
    {
        return in_array($code, self::enabled(), true);
    }

    /** Does this site publish more than one language? */
    public static function isMultilingual(): bool
    {
        return count(self::enabled()) > 1;
    }

    /**
     * The definitions of the enabled languages, primary first.
     *
     * @return LanguageDefinition[]
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::enabled() as $code) {
            $definition = LanguageRegistry::get($code);
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /**
     * The primary language a submitted value may become: a language the V1
     * columns can store, or Dutch for anything else.
     *
     * An unknown code is dropped rather than rejected — a form and a wizard
     * step both reach this, and neither may lock an owner out of their own
     * site.
     */
    public static function normalisePrimary(string $primary): string
    {
        $definition = LanguageRegistry::get(trim($primary));

        return ($definition !== null && $definition->availableAsContentLanguage)
            ? $definition->code
            : LanguageRegistry::DEFAULT_LANGUAGE;
    }

    /**
     * Store a submitted primary language as the registry's default and return
     * the code that was stored. The one writer, used by the settings endpoint
     * AND by the Setup Wizard, so the two cannot drift.
     *
     * @throws \InvalidArgumentException when the registry has no active row
     *                                   for that language
     */
    public static function savePrimary(string $primary): string
    {
        $code = self::normalisePrimary($primary);

        SiteLanguages::setDefault($code);

        return $code;
    }
}
