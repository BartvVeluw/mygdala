<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\LocalizedValue;
use App\Service\Language\TranslationTable;

/**
 * THE way into the words of the footer's columns and links in any website
 * language (Multilingual 2.0 phase 4, docs/multilingual/ARCHITECTURE.md):
 * a column's title in `footer_column_translations`, a link's label in
 * `footer_link_translations`, one row per owner per language.
 *
 * Everything else about a column or link — destination, cookie-settings
 * action, order, visibility, the column a link sits in — is language-neutral
 * and stays in `footer_columns`/`footer_links`. So do the social profiles
 * (brand names from a closed catalogue, only a URL is stored) and the
 * copyright template (one value with {{year}}/{{site_name}}). The footer's
 * description and closing line are site settings, and their languages are
 * App\Service\LocalizedSiteSettings's business.
 *
 * Nothing else reads or writes the two tables. The fallback is
 * App\Service\Language\LanguageFallback's.
 */
final class FooterLocalization
{
    public const TITLE = 'title';
    public const LABEL = 'label';
    public const TITLE_MAX_LENGTH = 100;
    public const LABEL_MAX_LENGTH = 100;

    private static ?EntityTranslations $columns = null;
    private static ?EntityTranslations $links = null;

    public static function columns(): EntityTranslations
    {
        return self::$columns ??= new EntityTranslations(
            new TranslationTable('footer_column_translations', 'footer_column_id', [self::TITLE => self::TITLE_MAX_LENGTH])
        );
    }

    public static function links(): EntityTranslations
    {
        return self::$links ??= new EntityTranslations(
            new TranslationTable('footer_link_translations', 'footer_link_id', [self::LABEL => self::LABEL_MAX_LENGTH])
        );
    }

    /** A column's title pair for partials/footer.php (the temporary V1 adapter). */
    public static function columnTitle(int $columnId): LocalizedValue
    {
        return self::columns()->bilingual($columnId, self::TITLE);
    }

    /** A link's label pair for partials/footer.php (the temporary V1 adapter). */
    public static function linkLabel(int $linkId): LocalizedValue
    {
        return self::links()->bilingual($linkId, self::LABEL);
    }

    public static function rawColumnTitle(int $columnId, string $languageCode): string
    {
        return self::columns()->raw($columnId, self::TITLE, $languageCode);
    }

    public static function rawLinkLabel(int $linkId, string $languageCode): string
    {
        return self::links()->raw($linkId, self::LABEL, $languageCode);
    }

    /** What the CMS calls a column in its lists and headings. */
    public static function columnName(int $columnId): string
    {
        return self::columns()->name($columnId, self::TITLE);
    }

    /** What the CMS calls a link in its lists and headings. */
    public static function linkName(int $linkId): string
    {
        return self::links()->name($linkId, self::LABEL);
    }

    /**
     * Has the link a label in the default language? The default language
     * decides whether a link can be shown, like a header button
     * (App\Service\NavigationLocalization::hasDefaultLabel()).
     */
    public static function hasDefaultLinkLabel(int $linkId): bool
    {
        return self::links()->raw($linkId, self::LABEL, LanguageFallback::defaultLanguage()) !== '';
    }

    /**
     * @param list<int> $columnIds
     * @param list<int> $linkIds
     */
    public static function preload(array $columnIds, array $linkIds): void
    {
        self::columns()->preload($columnIds);
        self::links()->preload($linkIds);
    }

    public static function saveColumnTitle(int $columnId, string $languageCode, string $title): void
    {
        self::columns()->save($columnId, $languageCode, [self::TITLE => $title]);
    }

    public static function saveLinkLabel(int $linkId, string $languageCode, string $label): void
    {
        self::links()->save($linkId, $languageCode, [self::LABEL => $label]);
    }

    public static function clearCache(): void
    {
        self::columns()->clearCache();
        self::links()->clearCache();
    }
}
