<?php

declare(strict_types=1);

namespace App\Service\Personalization;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * THE way into the Personalisatie module's visitor-facing words in any
 * website language (Multilingual 2.0 phase 5 wave D,
 * docs/multilingual/ARCHITECTURE.md, MODULES.md "Personalisatie"). They live
 * in three typed tables, one row per owner per language:
 *
 *   product_personalization_translations       instructions
 *   product_personalization_view_translations  label
 *   product_personalization_zone_translations  label, instructions, placeholder
 *
 * WORDS ARE NOT THE CONFIGURATION, and that line is the whole point of this
 * class. Everything that decides what a customer may DO stays on its own row
 * and is the same in every language: `view_key` and `zone_key` — the keys an
 * order line and the browser refer to a zone by — the geometry, `allow_text`,
 * `allow_image`, `is_enabled`, `is_required`, `allow_rotation`,
 * `max_text_length`, `surcharge`, the font settings, `personalization_mode`,
 * the preview image and every sort order. A visitor switching language reads
 * different labels and gets the same zones, in the same places, at the same
 * price.
 *
 * AND WORDS ARE NOT AN ORDER. What a zone was CALLED when somebody bought it
 * lives in that order line's own `config_snapshot_json` (version 3) and never
 * changes again. Nothing in this class is ever read for a historical order,
 * and nothing here is ever written from one.
 *
 * Nothing else reads or writes these three tables: not a repository, not the
 * content class, not an endpoint. The fallback — the asked-for language, the
 * default language, '' — is App\Service\Language\LanguageFallback's, through
 * App\Service\Language\EntityTranslations. Same shape as
 * App\Service\PortfolioLocalization, App\Service\Blog\BlogLocalization and
 * App\Service\ShopLocalization.
 *
 * NOTHING HERE IS RICH TEXT. An instruction, a label and a placeholder are
 * printed escaped, so there is no sanitizer in this class and there should
 * not be one.
 *
 * MODULE OFF CHANGES NOTHING. Switching Personalisatie off removes no row and
 * no word, and switching it back on shows the same words.
 */
final class PersonalizationLocalization
{
    public const INSTRUCTIONS = 'instructions';
    public const LABEL = 'label';
    public const PLACEHOLDER = 'placeholder';

    public const INSTRUCTIONS_MAX_LENGTH = 500;
    public const LABEL_MAX_LENGTH = 100;
    public const PLACEHOLDER_MAX_LENGTH = 100;

    /** The one field a product's personalization settings have. */
    public const SETTINGS_FIELDS = [self::INSTRUCTIONS => self::INSTRUCTIONS_MAX_LENGTH];

    /** A view has a label and nothing else to say. */
    public const VIEW_FIELDS = [self::LABEL => self::LABEL_MAX_LENGTH];

    /** A zone has all three. */
    public const ZONE_FIELDS = [
        self::LABEL => self::LABEL_MAX_LENGTH,
        self::INSTRUCTIONS => self::INSTRUCTIONS_MAX_LENGTH,
        self::PLACEHOLDER => self::PLACEHOLDER_MAX_LENGTH,
    ];

    private static ?EntityTranslations $settings = null;
    private static ?EntityTranslations $views = null;
    private static ?EntityTranslations $zones = null;

    /** The store of a product's general personalization instructions. */
    public static function settings(): EntityTranslations
    {
        return self::$settings ??= new EntityTranslations(
            new TranslationTable('product_personalization_translations', 'settings_id', self::SETTINGS_FIELDS)
        );
    }

    /** The store of a view's label. */
    public static function views(): EntityTranslations
    {
        return self::$views ??= new EntityTranslations(
            new TranslationTable('product_personalization_view_translations', 'view_id', self::VIEW_FIELDS)
        );
    }

    /** The store of a zone's label, instructions and placeholder. */
    public static function zones(): EntityTranslations
    {
        return self::$zones ??= new EntityTranslations(
            new TranslationTable('product_personalization_zone_translations', 'zone_id', self::ZONE_FIELDS)
        );
    }

    /* ------------------------------------------------------------------ */
    /* Settings                                                            */
    /* ------------------------------------------------------------------ */

    /** A product's general instructions in one language, with the fallback. */
    public static function instructions(int $settingsId, string $languageCode): string
    {
        return self::settings()->value($settingsId, self::INSTRUCTIONS, $languageCode);
    }

    /** The stored instructions in one language, no fallback: what the editor shows. */
    public static function rawInstructions(int $settingsId, string $languageCode): string
    {
        return self::settings()->raw($settingsId, self::INSTRUCTIONS, $languageCode);
    }

    public static function saveInstructions(int $settingsId, string $languageCode, string $instructions): void
    {
        self::settings()->save($settingsId, $languageCode, [self::INSTRUCTIONS => $instructions]);
    }

    /* ------------------------------------------------------------------ */
    /* Views                                                               */
    /* ------------------------------------------------------------------ */

    public static function viewLabel(int $viewId, string $languageCode): string
    {
        return self::views()->value($viewId, self::LABEL, $languageCode);
    }

    public static function rawViewLabel(int $viewId, string $languageCode): string
    {
        return self::views()->raw($viewId, self::LABEL, $languageCode);
    }

    /** What the CMS calls a view in its headings and lists. */
    public static function viewName(int $viewId): string
    {
        return self::views()->name($viewId, self::LABEL);
    }

    public static function saveViewLabel(int $viewId, string $languageCode, string $label): void
    {
        self::views()->save($viewId, $languageCode, [self::LABEL => $label]);
    }

    /** @param list<int> $viewIds */
    public static function preloadViews(array $viewIds): void
    {
        self::views()->preload($viewIds);
    }

    /* ------------------------------------------------------------------ */
    /* Zones                                                               */
    /* ------------------------------------------------------------------ */

    public static function zoneWord(int $zoneId, string $field, string $languageCode): string
    {
        return self::zones()->value($zoneId, $field, $languageCode);
    }

    public static function rawZoneWord(int $zoneId, string $field, string $languageCode): string
    {
        return self::zones()->raw($zoneId, $field, $languageCode);
    }

    /** What the CMS calls a zone in its zone editor and its error messages. */
    public static function zoneName(int $zoneId): string
    {
        return self::zones()->name($zoneId, self::LABEL);
    }

    /**
     * Store one language's words for a zone. Only the fields given are
     * written, so a screen that does not show a field cannot empty it.
     *
     * @param array<string, string|null> $values
     */
    public static function saveZone(int $zoneId, string $languageCode, array $values): void
    {
        self::zones()->save($zoneId, $languageCode, $values);
    }

    /** @param list<int> $zoneIds */
    public static function preloadZones(array $zoneIds): void
    {
        self::zones()->preload($zoneIds);
    }

    /** The language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::settings()->clearCache();
        self::views()->clearCache();
        self::zones()->clearCache();
    }
}
