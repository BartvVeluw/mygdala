<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Service\Language\EntityTranslations;
use App\Service\Language\LanguageFallback;
use App\Service\Language\TranslationTable;

/**
 * THE way into the words and addresses of Articles (ARTICLES.md), in two
 * typed translation tables of Multilingual 2.0:
 *
 *   article_translations        slug, title, excerpt, meta_title, meta_description
 *   article_topic_translations  slug, name, description
 *
 * NO FALLBACK ON THE PUBLIC SIDE. An article exists in a language when it has
 * an address there; that language version is then read in its own words
 * only (word()), never in the default language's. A Dutch article is not
 * published at /en/articles/… with Dutch words, and the English listing does
 * not show it. That is the difference with the Blog, whose words fall back
 * (BlogLocalization), and it is deliberate: an article is long-lived
 * editorial content that is either translated or not.
 *
 * The CMS uses name() (the default language, then any) to call an article or
 * a topic something in its lists.
 *
 * NO NEUTRAL SLUG. Unlike blog_posts.slug there is no language-neutral
 * column: Articles is new, so there is no old URL to keep. An article's
 * address in a language is exactly its row's `slug` there.
 *
 * MODULE OFF CHANGES NOTHING: nothing here asks whether the module runs.
 */
final class ArticleLocalization
{
    public const SLUG = TranslationTable::SLUG;
    public const TITLE = 'title';
    public const EXCERPT = 'excerpt';
    public const META_TITLE = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';
    public const NAME = 'name';
    public const DESCRIPTION = 'description';

    public const MAX_TITLE_LENGTH = 200;
    public const MAX_EXCERPT_LENGTH = 500;
    public const MAX_META_TITLE_LENGTH = 255;
    public const MAX_META_DESCRIPTION_LENGTH = 500;
    public const MAX_TOPIC_NAME_LENGTH = 150;
    public const MAX_TOPIC_DESCRIPTION_LENGTH = 500;

    private static ?EntityTranslations $articles = null;
    private static ?EntityTranslations $topics = null;

    public static function articles(): EntityTranslations
    {
        return self::$articles ??= new EntityTranslations(new TranslationTable('article_translations', 'article_id', [
            self::SLUG => ArticleSlug::MAX_LENGTH,
            self::TITLE => self::MAX_TITLE_LENGTH,
            self::EXCERPT => self::MAX_EXCERPT_LENGTH,
            self::META_TITLE => self::MAX_META_TITLE_LENGTH,
            self::META_DESCRIPTION => self::MAX_META_DESCRIPTION_LENGTH,
        ]));
    }

    public static function topics(): EntityTranslations
    {
        return self::$topics ??= new EntityTranslations(new TranslationTable('article_topic_translations', 'article_topic_id', [
            self::SLUG => ArticleSlug::MAX_LENGTH,
            self::NAME => self::MAX_TOPIC_NAME_LENGTH,
            self::DESCRIPTION => self::MAX_TOPIC_DESCRIPTION_LENGTH,
        ]));
    }

    /** An article's address in one language, or null: that language has no version. */
    public static function slug(int $articleId, string $language): ?string
    {
        return self::articles()->slug($articleId, $language);
    }

    /** One field of an article in exactly this language: no fallback (see the class docblock). */
    public static function word(int $articleId, string $field, string $language): string
    {
        return self::articles()->raw($articleId, $field, $language);
    }

    /** What the CMS calls an article: its title in the default language, else in any. */
    public static function name(int $articleId): string
    {
        return self::articles()->name($articleId, self::TITLE);
    }

    /** Every language this article has an address in, in the site's order. */
    public static function languages(int $articleId): array
    {
        return self::articles()->routableLanguages($articleId);
    }

    /** @param list<int> $articleIds */
    public static function preload(array $articleIds): void
    {
        self::articles()->preload($articleIds);
    }

    /** @param array<string, string|null> $values */
    public static function save(int $articleId, string $language, array $values): void
    {
        self::articles()->save($articleId, $language, $values);
    }

    public static function topicSlug(int $topicId, string $language): ?string
    {
        return self::topics()->slug($topicId, $language);
    }

    public static function topicWord(int $topicId, string $field, string $language): string
    {
        return self::topics()->raw($topicId, $field, $language);
    }

    public static function topicName(int $topicId): string
    {
        return self::topics()->name($topicId, self::NAME);
    }

    /** @param list<int> $topicIds */
    public static function preloadTopics(array $topicIds): void
    {
        self::topics()->preload($topicIds);
    }

    /** @param array<string, string|null> $values */
    public static function saveTopic(int $topicId, string $language, array $values): void
    {
        self::topics()->save($topicId, $language, $values);
    }

    public static function defaultLanguage(): string
    {
        return LanguageFallback::defaultLanguage();
    }

    public static function clearCache(): void
    {
        self::articles()->clearCache();
        self::topics()->clearCache();
    }
}
