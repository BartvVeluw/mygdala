<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * THE way into the label of a menu link, submenu item or header button in
 * any website language (Multilingual 2.0 phase 4,
 * docs/multilingual/ARCHITECTURE.md). The words live in
 * `nav_item_translations`, one row per item per language; everything else
 * about an item — its destination, presentation, parent, order and
 * visibility — is language-neutral and stays in `nav_items`.
 *
 * Nothing else reads or writes that table: not App\Service\NavigationService,
 * not partials/header.php, not an endpoint. The fallback (the asked-for
 * language, the default language, '') is App\Service\Language\
 * LanguageFallback's, through App\Service\Language\EntityTranslations.
 *
 * A PAGE LINK KEEPS ITS OWN LABEL. It points at `target_page_id`, a stable id,
 * and never borrows the page's title: an editor names a menu item for the
 * menu. Localized addresses are the routing phase's business.
 */
final class NavigationLocalization
{
    public const LABEL = 'label';
    public const LABEL_MAX_LENGTH = 100;

    private static ?EntityTranslations $items = null;

    /** The item-label store, for the few callers that need more than the methods below. */
    public static function items(): EntityTranslations
    {
        return self::$items ??= new EntityTranslations(
            new TranslationTable('nav_item_translations', 'nav_item_id', [self::LABEL => self::LABEL_MAX_LENGTH])
        );
    }

    /** The label a visitor reads in one language: its own, else the default language's. */
    public static function label(int $itemId, string $languageCode): string
    {
        return self::items()->value($itemId, self::LABEL, $languageCode);
    }

    /**
     * Does the item have a label in the default language? An item's
     * STRUCTURE is language-neutral, and the default language decides
     * whether it can be shown: a header button without a label there is not
     * rendered, whatever a translation says.
     */
    public static function hasDefaultLabel(int $itemId): bool
    {
        return self::items()->raw($itemId, self::LABEL, LanguageFallback::defaultLanguage()) !== '';
    }

    /** The stored label in one language, no fallback: what the editor shows. */
    public static function raw(int $itemId, string $languageCode): string
    {
        return self::items()->raw($itemId, self::LABEL, $languageCode);
    }

    /** What the CMS calls an item in its lists and headings. */
    public static function name(int $itemId): string
    {
        return self::items()->name($itemId, self::LABEL);
    }

    /** @param list<int> $itemIds */
    public static function preload(array $itemIds): void
    {
        self::items()->preload($itemIds);
    }

    /**
     * Store one language's label. Empty removes that language's row, never
     * another language's.
     */
    public static function save(int $itemId, string $languageCode, string $label): void
    {
        self::items()->save($itemId, $languageCode, [self::LABEL => $label]);
    }

    public static function clearCache(): void
    {
        self::items()->clearCache();
    }
}
