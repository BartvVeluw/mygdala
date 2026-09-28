<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;
use App\Service\Routing\LinkTargets;

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
 * THE TITLE OF THE DESTINATION (Pages & Destinations 3.0, HEADER-FOOTER.md
 * "Tekst van een menu-item"). A page link may carry no label of its own:
 * "Gebruik titel van bestemming" in the editor. It then shows its page's
 * title, read per render in the language of the request (the page's own
 * fallback, through App\Service\Routing\LinkTargets::title()), so renaming or
 * translating the page moves the menu along — nothing is copied. Whether an
 * item follows is decided by the DEFAULT language, which decides whether
 * anything about an item exists (MULTILINGUAL.md): no label there means the
 * item follows, in every language; a label there means it keeps its own
 * words, per language, with the usual fallback — exactly as before this
 * existed, so every item that already had a label reads the same.
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

    /** The item's OWN label in one language: its own, else the default language's. */
    public static function label(int $itemId, string $languageCode): string
    {
        return self::items()->value($itemId, self::LABEL, $languageCode);
    }

    /**
     * The label a visitor reads for an item in one language: its own words
     * when it has a label in the default language, else the title of its
     * destination in that language, else '' (and then nothing is rendered).
     *
     * @param array<string, mixed> $item a `nav_items` row
     */
    public static function labelFor(array $item, string $languageCode): string
    {
        $itemId = (int) ($item['id'] ?? 0);

        if (self::hasDefaultLabel($itemId)) {
            return self::label($itemId, $languageCode);
        }

        return self::destinationTitle($item, $languageCode) ?? '';
    }

    /**
     * Does this item show its destination's title? Only a page link without a
     * label in the default language does; any other kind needs words of its
     * own.
     *
     * @param array<string, mixed> $item a `nav_items` row
     */
    public static function followsDestination(array $item): bool
    {
        return (string) ($item['link_type'] ?? '') === 'page'
            && (int) ($item['target_page_id'] ?? 0) > 0
            && !self::hasDefaultLabel((int) ($item['id'] ?? 0));
    }

    /**
     * The title of an item's destination in one language, or null when it
     * has none to give (not a page link, a page without a title).
     *
     * @param array<string, mixed> $item a `nav_items` row
     */
    public static function destinationTitle(array $item, string $languageCode): ?string
    {
        if ((string) ($item['link_type'] ?? '') !== 'page' || (int) ($item['target_page_id'] ?? 0) < 1) {
            return null;
        }

        return LinkTargets::title(LinkTargets::PAGE, (int) $item['target_page_id'], $languageCode);
    }

    /**
     * Remove the item's own label in EVERY language: what "Gebruik titel van
     * bestemming" stores, so no stray translation outlives the choice.
     */
    public static function clear(int $itemId): void
    {
        foreach (array_keys(self::items()->words($itemId)) as $languageCode) {
            self::items()->save($itemId, (string) $languageCode, [self::LABEL => null]);
        }
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

    /** What the CMS calls an item in its lists and headings, by its own words. */
    public static function name(int $itemId): string
    {
        return self::items()->name($itemId, self::LABEL);
    }

    /**
     * What the CMS calls an item, whatever it shows: its own words, or — for
     * an item that follows its page — that page's name, which is what the
     * menu shows. Every admin list and heading of an item asks this.
     *
     * @param array<string, mixed> $item a `nav_items` row
     */
    public static function adminName(array $item): string
    {
        return self::followsDestination($item)
            ? PageLocalization::name((int) $item['target_page_id'])
            : self::name((int) ($item['id'] ?? 0));
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
