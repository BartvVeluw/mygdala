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
 *   - which language the CMS interface runs in   -> AdminLocale, per admin user
 *   - which language the website's content IS    -> here, per site
 *   - which extra language it is translated into -> here, per site
 *
 * Changing the CMS interface language must never change a word of public
 * content, and vice versa. Two settings, two classes, no shared state.
 *
 * Stored in `site_settings` and not in `theme_settings` for the reason the
 * top of THEMING.md gives: this is who the site IS, not how it looks, and
 * "restore the default design" must never be able to take a site's languages
 * with it.
 *
 * V1 supports one primary language plus at most one secondary. That ceiling
 * is enforced here and nowhere else, so lifting it later is a change to this
 * class rather than to every editor: everything downstream already asks for
 * a LIST (::enabled()) and loops over it.
 */
final class ContentLanguages
{
    /** The language the site is written in. Everything falls back to it. */
    public const SETTING_PRIMARY = 'primary_content_language';

    /**
     * Comma-separated list of every language the site publishes, primary
     * included. A single value means a single-language site, which is what a
     * fresh install gets and what removes the duplicate fields an editor
     * complained about (MULTILINGUAL.md).
     */
    public const SETTING_ENABLED = 'enabled_content_languages';

    /** V1 ceiling: one primary plus at most one secondary. */
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
     * Every enabled content language, primary first, then registry order.
     *
     * The primary language is ALWAYS a member, whatever the stored list says:
     * a site that publishes nothing is not a state this CMS can render, and a
     * settings row must not be able to create one.
     *
     * @return string[]
     */
    public static function enabled(): array
    {
        $primary = self::primary();

        $stored = LanguageRegistry::filter(
            array_map('trim', explode(',', SiteSettings::get(self::SETTING_ENABLED)))
        );

        $enabled = [$primary];
        foreach ($stored as $code) {
            $definition = LanguageRegistry::get($code);
            if ($code === $primary || $definition === null || !$definition->availableAsContentLanguage) {
                continue;
            }

            $enabled[] = $code;
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
     * Normalise a submitted primary language + enabled list into the two
     * values that may be stored. The single place that decides what a valid
     * language configuration is, used by the settings endpoint AND by the
     * Setup Wizard so the two cannot drift.
     *
     * Unknown codes are dropped rather than rejected, the primary is forced
     * into the enabled list, and the V1 ceiling is applied.
     *
     * @param string[] $enabled
     * @return array{primary_content_language: string, enabled_content_languages: string}
     */
    public static function normalise(string $primary, array $enabled): array
    {
        $definition = LanguageRegistry::get(trim($primary));
        $primaryCode = ($definition !== null && $definition->availableAsContentLanguage)
            ? $definition->code
            : LanguageRegistry::DEFAULT_LANGUAGE;

        $codes = [$primaryCode];
        foreach (LanguageRegistry::filter($enabled) as $code) {
            $candidate = LanguageRegistry::get($code);
            if ($code === $primaryCode || $candidate === null || !$candidate->availableAsContentLanguage) {
                continue;
            }

            $codes[] = $code;
        }

        return [
            self::SETTING_PRIMARY => $primaryCode,
            self::SETTING_ENABLED => implode(',', array_slice($codes, 0, self::MAX_ENABLED)),
        ];
    }
}
