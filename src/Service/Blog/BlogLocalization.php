<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\LocalizedValue;
use App\Service\Language\TranslationTable;
use App\Service\RichTextSanitizer;

/**
 * THE way into the Blog's words in any website language (Multilingual 2.0
 * phase 5 wave B, docs/multilingual/ARCHITECTURE.md, BLOG.md). They live in
 * three typed tables, one row per owner per language:
 *
 *   blog_post_translations      title, excerpt, body, meta_title, meta_description
 *   blog_category_translations  name, description
 *   blog_tag_translations       name
 *
 * Everything else about a post, a category or a tag is language-neutral and
 * stays on its own row: THE SLUG above all — /blog/<slug>,
 * /blog/categorie/<slug> and /blog/tag/<slug> are one address each and a
 * translation never moves one — plus the status, the publication date, the
 * author, the featured and share image, noindex, is_active and sort order.
 * A slug per language needs a router that uses it, which is phase 6.
 *
 * Nothing else reads or writes those tables: not a repository, not
 * App\Service\Blog\BlogContent, not an endpoint. The fallback — the asked-for
 * language, the default language, '' — is App\Service\Language\
 * LanguageFallback's, through App\Service\Language\EntityTranslations. Same
 * shape as App\Service\PortfolioLocalization and
 * App\Service\NavigationLocalization, which this class follows.
 *
 * `body` IS THE ONE RICH FIELD, and it keeps the sanitizer it always had
 * (App\Service\RichTextSanitizer, applied on read in
 * App\Service\Blog\BlogContent::body()). This class hands out the stored
 * markup; who may print it as HTML stays BlogContent's business, so there is
 * no second sanitizer and no new one.
 *
 * MODULE OFF CHANGES NOTHING. Switching the Blog off removes no row and no
 * word, and switching it back on shows the same words: this class never asks
 * whether the module is enabled.
 */
final class BlogLocalization
{
    public const TITLE = 'title';
    public const EXCERPT = 'excerpt';
    public const BODY = 'body';
    public const META_TITLE = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';

    /** The post fields, with the lengths App\Service\Blog\BlogPostService validates. */
    public const POST_FIELDS = [
        self::TITLE => BlogPostService::MAX_TITLE_LENGTH,
        self::EXCERPT => BlogPostService::MAX_EXCERPT_LENGTH,
        self::BODY => self::BODY_MAX_LENGTH,
        self::META_TITLE => BlogPostService::MAX_META_TITLE_LENGTH,
        self::META_DESCRIPTION => BlogPostService::MAX_META_DESCRIPTION_LENGTH,
    ];

    /**
     * The body has no length rule of its own in the editor; this is the one
     * the rich blocks use (App\Service\Blocks\RichTextBlock), so a runaway
     * paste is refused rather than silently cut by MySQL.
     */
    public const BODY_MAX_LENGTH = 50000;

    public const CATEGORY_NAME_MAX_LENGTH = 150;
    public const CATEGORY_DESCRIPTION_MAX_LENGTH = 500;
    public const TAG_NAME_MAX_LENGTH = 100;

    private static ?EntityTranslations $posts = null;
    private static ?EntityTranslations $categories = null;
    private static ?EntityTranslations $tags = null;

    /** The post-words store, for the few callers that need more than the methods below. */
    public static function posts(): EntityTranslations
    {
        return self::$posts ??= new EntityTranslations(
            new TranslationTable('blog_post_translations', 'blog_post_id', self::POST_FIELDS)
        );
    }

    public static function categories(): EntityTranslations
    {
        return self::$categories ??= new EntityTranslations(
            new TranslationTable('blog_category_translations', 'blog_category_id', [
                self::NAME => self::CATEGORY_NAME_MAX_LENGTH,
                self::DESCRIPTION => self::CATEGORY_DESCRIPTION_MAX_LENGTH,
            ])
        );
    }

    public static function tags(): EntityTranslations
    {
        return self::$tags ??= new EntityTranslations(
            new TranslationTable('blog_tag_translations', 'blog_tag_id', [
                self::NAME => self::TAG_NAME_MAX_LENGTH,
            ])
        );
    }

    /* ------------------------------------------------------------------ */
    /* Posts                                                               */
    /* ------------------------------------------------------------------ */

    /** The words a visitor gets for one post field, with the fallback. */
    public static function post(int $postId, string $field, string $languageCode): string
    {
        return self::posts()->value($postId, $field, $languageCode);
    }

    /** The stored words in one language, no fallback: what the editor shows. */
    public static function rawPost(int $postId, string $field, string $languageCode): string
    {
        return self::posts()->raw($postId, $field, $languageCode);
    }

    /** The pair a public partial prints (the temporary V1 adapter). */
    public static function postValue(int $postId, string $field): LocalizedValue
    {
        return self::posts()->bilingual($postId, $field);
    }

    /**
     * The sanitized body of one post in one language. The sanitizer is the one
     * the Blog always had; it runs here so that no reader can forget it.
     */
    public static function body(int $postId, string $languageCode): string
    {
        return (string) (RichTextSanitizer::sanitize(self::post($postId, self::BODY, $languageCode)) ?? '');
    }

    /**
     * The body's pair, each half sanitized BEFORE the fallback runs, so a
     * language whose markup sanitizes away to nothing simply has no body and
     * the fallback takes over. Same rule as
     * App\Service\PortfolioLocalization::itemRichValue().
     */
    public static function bodyValue(int $postId): LocalizedValue
    {
        $html = [];
        foreach (self::posts()->words($postId) as $code => $fields) {
            $markup = RichTextSanitizer::sanitize($fields[self::BODY] ?? null) ?? '';
            if ($markup !== '') {
                $html[$code] = $markup;
            }
        }

        return LanguageFallback::bilingual($html);
    }

    /** What the CMS calls a post in its lists and headings: its title. */
    public static function postName(int $postId): string
    {
        return self::posts()->name($postId, self::TITLE);
    }

    /** @param list<int> $postIds */
    public static function preloadPosts(array $postIds): void
    {
        self::posts()->preload($postIds);
    }

    /**
     * The posts whose title contains $needle, in ANY website language: what
     * the search box on admin/blog.php narrows the overview to. An editor
     * looking for a post looks for a title they remember, and which language
     * they remember it in is not the search's business — which is exactly what
     * the two fixed columns used to do for Dutch and English.
     *
     * @return list<int>
     */
    public static function postIdsMatchingTitle(string $needle): array
    {
        return self::posts()->ownersMatching(self::TITLE, $needle);
    }

    /**
     * Store one language's words for a post. Only the fields given are
     * written, so a screen that does not show a field cannot empty it.
     *
     * @param array<string, string|null> $values
     */
    public static function savePost(int $postId, string $languageCode, array $values): void
    {
        self::posts()->save($postId, $languageCode, $values);
    }

    /* ------------------------------------------------------------------ */
    /* Categories and tags                                                 */
    /* ------------------------------------------------------------------ */

    public static function categoryName(int $categoryId, string $languageCode): string
    {
        return self::categories()->value($categoryId, self::NAME, $languageCode);
    }

    public static function categoryNameValue(int $categoryId): LocalizedValue
    {
        return self::categories()->bilingual($categoryId, self::NAME);
    }

    public static function categoryDescription(int $categoryId, string $languageCode): string
    {
        return self::categories()->value($categoryId, self::DESCRIPTION, $languageCode);
    }

    public static function categoryDescriptionValue(int $categoryId): LocalizedValue
    {
        return self::categories()->bilingual($categoryId, self::DESCRIPTION);
    }

    public static function rawCategory(int $categoryId, string $field, string $languageCode): string
    {
        return self::categories()->raw($categoryId, $field, $languageCode);
    }

    /** What the CMS calls a category in its lists, headings and pickers. */
    public static function categoryLabel(int $categoryId): string
    {
        return self::categories()->name($categoryId, self::NAME);
    }

    /** @param list<int> $categoryIds */
    public static function preloadCategories(array $categoryIds): void
    {
        self::categories()->preload($categoryIds);
    }

    /** @param array<string, string|null> $values */
    public static function saveCategory(int $categoryId, string $languageCode, array $values): void
    {
        self::categories()->save($categoryId, $languageCode, $values);
    }

    public static function tagName(int $tagId, string $languageCode): string
    {
        return self::tags()->value($tagId, self::NAME, $languageCode);
    }

    public static function tagNameValue(int $tagId): LocalizedValue
    {
        return self::tags()->bilingual($tagId, self::NAME);
    }

    public static function rawTagName(int $tagId, string $languageCode): string
    {
        return self::tags()->raw($tagId, self::NAME, $languageCode);
    }

    /** What the CMS calls a tag in its lists and its picker. */
    public static function tagLabel(int $tagId): string
    {
        return self::tags()->name($tagId, self::NAME);
    }

    /** @param list<int> $tagIds */
    public static function preloadTags(array $tagIds): void
    {
        self::tags()->preload($tagIds);
    }

    public static function saveTagName(int $tagId, string $languageCode, string $name): void
    {
        self::tags()->save($tagId, $languageCode, [self::NAME => $name]);
    }

    /** The language every field falls back to. */
    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::posts()->clearCache();
        self::categories()->clearCache();
        self::tags()->clearCache();
    }
}
