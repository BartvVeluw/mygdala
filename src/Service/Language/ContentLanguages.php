<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Service\SiteSettings;

/**
 * Which languages the WEBSITE publishes content in.
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
 * Stored in `site_settings` and not in `theme_settings` for the reason the
 * top of THEMING.md gives: this is who the site IS, not how it looks, and
 * "restore the default design" must never be able to take a site's languages
 * with it.
 *
 * WHAT CHANGED AFTER V1, and why this class got smaller.
 *
 * V1 let an owner switch English OFF for the whole site, and that one
 * setting was wired into three unrelated things: whether the public header
 * offered a language switch, whether an editor could see English fields at
 * all, and whether automatic translation was offered. The product is
 * bilingual — Dutch and English, always — so a site that publishes only one
 * of them is not a state this CMS has any reason to produce, and making it
 * reachable cost editors the ability to write English at all.
 *
 * So ::enabled() now answers from the closed LanguageRegistry rather than
 * from a settings row: every language this build can store content in is a
 * language this site publishes. What remains genuinely per-site is
 * ::primary() — which language a visitor gets before they choose, and which
 * one every missing translation falls back to.
 */
final class ContentLanguages
{
    /** The language the site is written in. Everything falls back to it. */
    public const SETTING_PRIMARY = 'primary_content_language';

    /**
     * DEPRECATED, and kept only so stored rows stay readable and writable.
     *
     * It used to be the list of languages the site publishes, and hiding the
     * public language switch and the editor's English fields behind it was
     * the mistake this step corrects. ::enabled() no longer reads it, so
     * nothing a site has stored here can take a language away from a visitor
     * or from an editor. The row itself is left alone rather than deleted:
     * this project does not destroy stored language data.
     *
     * @deprecated Read ::enabled() instead. ::storedEnabled() exposes the raw
     *             row for migrations and diagnostics.
     */
    public const SETTING_ENABLED = 'enabled_content_languages';

    /** One primary plus at most one secondary — Dutch and English in V1. */
    public const MAX_ENABLED = 2;

    /**
     * The site's primary content language.
     *
     * Falls back to Dutch whenever the stored value is missing, empty or a
     * language this build does not know — the same safe direction the rest of
     * the project takes with settings, and the only value under which every
     * existing installation's unsuffixed and `_nl` columns keep meaning what
     * they meant.
     */
    public static function primary(): string
    {
        $stored = trim(SiteSettings::get(self::SETTING_PRIMARY));

        $definition = LanguageRegistry::get($stored);
        if ($definition !== null && $definition->availableAsContentLanguage) {
            return $definition->code;
        }

        return LanguageRegistry::DEFAULT_LANGUAGE;
    }

    /**
     * Every language this website publishes, primary first.
     *
     * Answered from the closed registry, NOT from the stored
     * `enabled_content_languages` row. The product is bilingual: Dutch and
     * English are both always available to a visitor and to an editor, and
     * no settings row may take one of them away. See the class docblock for
     * why that row stopped being consulted.
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
     * The raw `enabled_content_languages` row, for migrations, diagnostics
     * and the deprecation notice on the settings screen. Nothing in the
     * rendering or editing path may branch on this.
     *
     * @return string[]
     * @deprecated Use ::enabled().
     */
    public static function storedEnabled(): array
    {
        return LanguageRegistry::filter(
            array_map('trim', explode(',', SiteSettings::get(self::SETTING_ENABLED)))
        );
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
     * Normalise a submitted default website language into the values that
     * may be stored. The single place that decides what a valid language
     * configuration is, used by the settings endpoint AND by the Setup
     * Wizard so the two cannot drift.
     *
     * An unknown code is dropped rather than rejected — a form, a settings
     * row and a wizard step all reach this, and none of them may lock an
     * owner out of their own site.
     *
     * It still writes `enabled_content_languages`, and it now always writes
     * the full set this build publishes. That keeps the deprecated row
     * truthful for anything that reads the database directly, and it cannot
     * take a language away from anybody, because nothing branches on it any
     * more.
     *
     * $enabled is accepted and ignored, so the wizard and the endpoint keep
     * their existing call shape.
     *
     * @param string[] $enabled
     * @return array{primary_content_language: string, enabled_content_languages: string}
     */
    public static function normalise(string $primary, array $enabled = []): array
    {
        $definition = LanguageRegistry::get(trim($primary));
        $primaryCode = ($definition !== null && $definition->availableAsContentLanguage)
            ? $definition->code
            : LanguageRegistry::DEFAULT_LANGUAGE;

        $codes = [$primaryCode];
        foreach (LanguageRegistry::contentLanguages() as $code => $candidate) {
            if ($code !== $primaryCode) {
                $codes[] = $code;
            }
        }

        return [
            self::SETTING_PRIMARY => $primaryCode,
            self::SETTING_ENABLED => implode(',', array_slice($codes, 0, self::MAX_ENABLED)),
        ];
    }
}
