<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * THE way into the words of the Portfolio module in any website language
 * (Multilingual 2.0 phase 5 wave A, docs/multilingual/ARCHITECTURE.md). The
 * words live in three typed tables, one row per owner per language:
 *
 *   portfolio_category_translations    name
 *   portfolio_item_translations        title, subtitle, alt, intro, description
 *   portfolio_item_image_translations  alt
 *
 * Everything else about a category, an item or a photo is language-neutral
 * and stays on its own row: the slug, the image and thumbnail paths, the page
 * an item links to, the categories it has, is_active, is_featured and every
 * sort order. A category slug is generated once from its name in the DEFAULT
 * language and is never renamed (App\Repository\PortfolioCategoryRepository),
 * so a translation can never move an address.
 *
 * Nothing else reads or writes those tables: not the repositories, not
 * App\Service\PortfolioGalleryContent, not an endpoint. The fallback — the
 * asked-for language, the default language, '' — is
 * App\Service\Language\LanguageFallback's, through
 * App\Service\Language\EntityTranslations. Same shape as
 * App\Service\NavigationLocalization, which this class follows.
 *
 * PLAIN AND RICH. `name`, `title`, `subtitle` and `alt` are plain text;
 * `alt` is written into an attribute. `intro` and `description` are the OLD
 * project page's rich text: this class hands them out sanitized
 * (App\Service\RichTextSanitizer), exactly as PortfolioGalleryContent did
 * when they were columns — "sanitize again on read", because the editor that
 * wrote that HTML is gone. There is no second sanitizer.
 *
 * MODULE OFF CHANGES NOTHING. Switching the Portfolio off removes no row and
 * no word, and switching it back on shows the same words: this class never
 * asks whether the module is enabled.
 */
final class PortfolioLocalization
{
    public const NAME = 'name';
    public const TITLE = 'title';
    public const SUBTITLE = 'subtitle';
    public const ALT = 'alt';
    public const INTRO = 'intro';
    public const DESCRIPTION = 'description';

    public const NAME_MAX_LENGTH = 100;
    public const TITLE_MAX_LENGTH = 150;
    public const SUBTITLE_MAX_LENGTH = 150;
    public const ALT_MAX_LENGTH = 255;

    /**
     * The rich fields of the old project page. No screen writes them any
     * more, so this length only guards a value on its way in; it is the one
     * the rich blocks use (App\Service\Blocks\RichTextBlock).
     */
    public const RICH_MAX_LENGTH = 50000;

    /** The item fields that are rich text rather than plain. */
    public const RICH_FIELDS = [self::INTRO, self::DESCRIPTION];

    private static ?EntityTranslations $categories = null;
    private static ?EntityTranslations $items = null;
    private static ?EntityTranslations $images = null;

    /** The category-name store. */
    public static function categories(): EntityTranslations
    {
        return self::$categories ??= new EntityTranslations(
            new TranslationTable('portfolio_category_translations', 'portfolio_category_id', [
                self::NAME => self::NAME_MAX_LENGTH,
            ])
        );
    }

    /** The item-words store: the card's own words and the old project page's text. */
    public static function items(): EntityTranslations
    {
        return self::$items ??= new EntityTranslations(
            new TranslationTable('portfolio_item_translations', 'portfolio_item_id', [
                self::TITLE => self::TITLE_MAX_LENGTH,
                self::SUBTITLE => self::SUBTITLE_MAX_LENGTH,
                self::ALT => self::ALT_MAX_LENGTH,
                self::INTRO => self::RICH_MAX_LENGTH,
                self::DESCRIPTION => self::RICH_MAX_LENGTH,
            ])
        );
    }

    /** The alt texts of the old project page's extra photos. */
    public static function images(): EntityTranslations
    {
        return self::$images ??= new EntityTranslations(
            new TranslationTable('portfolio_item_image_translations', 'portfolio_item_image_id', [
                self::ALT => self::ALT_MAX_LENGTH,
            ])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Categories                                                          */
    /* ------------------------------------------------------------------ */

    /** The name a visitor reads in one language: its own, else the default language's. */
    public static function categoryName(int $categoryId, string $languageCode): string
    {
        return self::categories()->value($categoryId, self::NAME, $languageCode);
    }

    /** The stored name in one language, no fallback: what the editor shows. */
    public static function rawCategoryName(int $categoryId, string $languageCode): string
    {
        return self::categories()->raw($categoryId, self::NAME, $languageCode);
    }

    /** What the CMS calls a category in its lists, headings and pickers. */
    public static function categoryLabel(int $categoryId): string
    {
        return self::categories()->name($categoryId, self::NAME);
    }

    /**
     * The name in the DEFAULT language: what a new category's slug is
     * generated from, and what decides whether it has a name at all.
     */
    public static function defaultCategoryName(int $categoryId): string
    {
        return self::categories()->raw($categoryId, self::NAME, LanguageFallback::defaultLanguage());
    }

    /** @param list<int> $categoryIds */
    public static function preloadCategories(array $categoryIds): void
    {
        self::categories()->preload($categoryIds);
    }

    /** Store one language's category name. Empty removes that language's row, never another's. */
    public static function saveCategory(int $categoryId, string $languageCode, string $name): void
    {
        self::categories()->save($categoryId, $languageCode, [self::NAME => $name]);
    }

    /* ------------------------------------------------------------------ */
    /* Items                                                               */
    /* ------------------------------------------------------------------ */

    /** One plain item field (title, subtitle, alt) as a visitor reads it in one language. */
    public static function item(int $itemId, string $field, string $languageCode): string
    {
        self::assertPlainItemField($field);

        return self::items()->value($itemId, $field, $languageCode);
    }

    /** The stored words of one item field in one language, no fallback: for the editor. */
    public static function rawItemValue(int $itemId, string $field, string $languageCode): string
    {
        return self::items()->raw($itemId, $field, $languageCode);
    }

    /** What the CMS calls an item in its overview and headings: its title. */
    public static function itemLabel(int $itemId): string
    {
        return self::items()->name($itemId, self::TITLE);
    }

    /**
     * One RICH item field (intro, description) as a visitor reads it in one
     * language, sanitized on the way out — the old project page's text.
     */
    public static function itemRich(int $itemId, string $field, string $languageCode): string
    {
        if (!in_array($field, self::RICH_FIELDS, true)) {
            throw new \InvalidArgumentException('Not a rich Portfolio item field: ' . $field);
        }

        // Sanitized per language BEFORE the fallback runs, so the fallback
        // itself stays LanguageFallback's one rule and never sees markup it
        // did not check. A language whose markup sanitizes away to nothing
        // has no words, exactly like an empty column.
        $html = [];
        foreach (self::items()->words($itemId) as $code => $fields) {
            $markup = RichTextSanitizer::sanitize($fields[$field] ?? null) ?? '';
            if ($markup !== '') {
                $html[$code] = $markup;
            }
        }

        return LanguageFallback::resolve($html, $languageCode);
    }

    /** @param list<int> $itemIds */
    public static function preloadItems(array $itemIds): void
    {
        self::items()->preload($itemIds);
    }

    /**
     * Store one language's words for an item. Only the fields given are
     * written, so the item's own editor cannot empty the old project page's
     * text it does not show.
     *
     * @param array<string, string|null> $values
     */
    public static function saveItem(int $itemId, string $languageCode, array $values): void
    {
        self::items()->save($itemId, $languageCode, $values);
    }

    /* ------------------------------------------------------------------ */
    /* Photos of the old project page                                      */
    /* ------------------------------------------------------------------ */

    /** The alt text of one extra photo as a visitor reads it in one language. */
    public static function imageAlt(int $imageId, string $languageCode): string
    {
        return self::images()->value($imageId, self::ALT, $languageCode);
    }

    /** @param list<int> $imageIds */
    public static function preloadImages(array $imageIds): void
    {
        self::images()->preload($imageIds);
    }

    /** The language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::categories()->clearCache();
        self::items()->clearCache();
        self::images()->clearCache();
    }

    private static function assertPlainItemField(string $field): void
    {
        if (!in_array($field, [self::TITLE, self::SUBTITLE, self::ALT], true)) {
            throw new \InvalidArgumentException('Not a plain Portfolio item field: ' . $field);
        }
    }
}
