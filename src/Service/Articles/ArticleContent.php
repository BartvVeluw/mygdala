<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Repository\ArticleRepository;
use App\Repository\ArticleTopicRepository;
use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;
use App\Service\Publishing\PublishingClock;
use App\Service\Routing\RequestLanguage;

/**
 * What the two public Articles templates print (articles.php, article.php),
 * read in ONE language: the request's.
 *
 * AN ARTICLE EXISTS IN A LANGUAGE WHEN IT HAS AN ADDRESS THERE, and is then
 * read in that language's own words (ArticleLocalization, no fallback). The
 * detail page answers only at that address; the listing shows only articles
 * that have a version in the language being read. A Dutch-only article is
 * therefore absent from /en/articles, never shown there in Dutch.
 *
 * VISIBILITY is the Publishing Engine's: the listing reads LISTED rows, the
 * detail page REACHABLE ones (an archived article keeps its address, as
 * noindex — ArticleSeo). A draft, a future scheduled article and an unknown
 * slug are one answer: null, which the template renders as the site's 404.
 *
 * QUERIES. A listing costs one query for the rows, one for the count, one for
 * all their words, one for all their pictures and one for the topics' words;
 * never one per card.
 */
final class ArticleContent
{
    public const PER_PAGE = 12;

    /**
     * One article's page, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function article(string $slug, ?string $language = null): ?array
    {
        $slug = trim($slug);
        $language ??= RequestLanguage::current();

        if ($slug === '') {
            return null;
        }

        $id = ArticleLocalization::articles()->ownerForSlug($slug, $language);
        $row = $id === null ? null : (new ArticleRepository())->findReachableById($id, PublishingClock::nowForSql());

        if ($row === null || ArticleLocalization::word((int) $row['id'], ArticleLocalization::TITLE, $language) === '') {
            return null;
        }

        return self::decorateMany([$row], $language)[0];
    }

    /**
     * The listing: every listed article with a version in this language, or
     * those of one topic. Null for an unknown topic, so it can be a 404.
     *
     * @param array{topic?: string, page?: int} $request
     * @return array{mode: string, topic: array<string, mixed>|null, articles: list<array<string, mixed>>, page: int, pages: int, topics: list<array<string, mixed>>}|null
     */
    public static function listing(array $request, ?string $language = null): ?array
    {
        $language ??= RequestLanguage::current();
        $now = PublishingClock::nowForSql();
        $repository = new ArticleRepository();
        $topic = null;

        $topicSlug = trim((string) ($request['topic'] ?? ''));
        if ($topicSlug !== '') {
            $topicId = ArticleLocalization::topics()->ownerForSlug($topicSlug, $language);
            $topicRow = $topicId === null ? null : (new ArticleTopicRepository())->find($topicId);

            if ($topicRow === null) {
                return null;
            }

            $topic = self::decorateTopic($topicRow, $language);
        }

        $topicId = $topic === null ? null : (int) $topic['id'];
        $versions = ArticleLocalization::articles()->ownersWithSlug($language);
        $total = $repository->countListed($now, $versions, $topicId);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, (int) ($request['page'] ?? 1));

        // A page past the end is not a page.
        if ($page > $pages) {
            return null;
        }

        $rows = $repository->findListed($now, $versions, self::PER_PAGE, ($page - 1) * self::PER_PAGE, $topicId);

        return [
            'mode' => $topic === null ? 'index' : 'topic',
            'topic' => $topic,
            'articles' => self::decorateMany($rows, $language),
            'page' => $page,
            'pages' => $pages,
            'topics' => self::publicTopics($language),
        ];
    }

    /**
     * The topics that hold at least one listed article in this language, in
     * their own order: the row of links above the listing.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicTopics(string $language): array
    {
        $counts = (new ArticleRepository())->listedCountsByTopic(PublishingClock::nowForSql(), ArticleLocalization::articles()->ownersWithSlug($language));
        if ($counts === []) {
            return [];
        }

        $topics = array_values(array_filter(
            (new ArticleTopicRepository())->all(),
            static fn (array $topic): bool => ($counts[(int) $topic['id']] ?? 0) > 0
        ));
        ArticleLocalization::preloadTopics(array_map(static fn (array $topic): int => (int) $topic['id'], $topics));

        $decorated = [];
        foreach ($topics as $topic) {
            $topic = self::decorateTopic($topic, $language);
            if ($topic['url'] !== null && $topic['name'] !== '') {
                $decorated[] = $topic;
            }
        }

        return $decorated;
    }

    /**
     * Every language this article can be read in, code => path: the
     * hreflang set and the language switch. Only real versions.
     *
     * @return array<string, string>
     */
    public static function alternates(int $articleId): array
    {
        $paths = [];
        foreach (SiteLanguages::activeCodes() as $code) {
            $slug = ArticleLocalization::slug($articleId, $code);
            if ($slug !== null && ArticleLocalization::word($articleId, ArticleLocalization::TITLE, $code) !== '') {
                $paths[$code] = ArticleUrls::articlePath($slug, $code);
            }
        }

        return $paths;
    }

    /** @return array<string, string> */
    public static function topicAlternates(int $topicId, int $page = 1): array
    {
        $paths = [];
        foreach (SiteLanguages::activeCodes() as $code) {
            $slug = ArticleLocalization::topicSlug($topicId, $code);
            if ($slug !== null) {
                $paths[$code] = ArticleUrls::topicPath($slug, $page, $code);
            }
        }

        return $paths;
    }

    /** The article's address in this language, or null when it has no version there. */
    public static function path(int $articleId, string $language): ?string
    {
        return self::alternates($articleId)[$language] ?? null;
    }

    /**
     * Rows plus what a card or the detail page prints, all words and pictures
     * loaded in one go.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function decorateMany(array $rows, string $language): array
    {
        if ($rows === []) {
            return [];
        }

        ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        ArticleLocalization::preloadTopics(array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['topic_id'] ?? 0), $rows))));
        $pictures = MediaService::findMany(array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['featured_media_id'] ?? 0),
            $rows
        ))));
        $topics = [];
        $topicRows = null;

        $decorated = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slug = ArticleLocalization::slug($id, $language);
            $topicId = (int) ($row['topic_id'] ?? 0);

            if ($topicId > 0 && $topicRows === null) {
                // The whole (short, hand-ordered) list once, not one per card.
                $topicRows = [];
                foreach ((new ArticleTopicRepository())->all() as $topicRow) {
                    $topicRows[(int) $topicRow['id']] = $topicRow;
                }
            }
            if ($topicId > 0 && !array_key_exists($topicId, $topics)) {
                $topics[$topicId] = isset($topicRows[$topicId]) ? self::decorateTopic($topicRows[$topicId], $language) : null;
            }

            $decorated[] = $row + [
                'title' => ArticleLocalization::word($id, ArticleLocalization::TITLE, $language),
                'excerpt' => ArticleLocalization::word($id, ArticleLocalization::EXCERPT, $language),
                'url' => $slug === null ? null : ArticleUrls::articlePath($slug, $language),
                'canonical_url' => $slug === null ? null : AppUrl::canonical(ArticleUrls::articlePath($slug, $language)),
                'image' => self::image($pictures[(int) ($row['featured_media_id'] ?? 0)] ?? null),
                'topic' => $topicId > 0 ? $topics[$topicId] : null,
                'author' => trim((string) ($row['author_name'] ?? '')),
                'date' => PublishingClock::forReader($row['published_at'] ?? null, $language),
                'date_attribute' => PublishingClock::forAtom($row['published_at'] ?? null),
            ];
        }

        return $decorated;
    }

    /**
     * A featured image as a template prints it: the full file, its library
     * alt text and size, and the library's thumbnail as a smaller candidate
     * so a card does not download the full file (MEDIA.md).
     *
     * @return array{src: string, alt: string, width: int|null, height: int|null, srcset: string}|null
     */
    public static function image(?MediaItem $media): ?array
    {
        if ($media === null || !$media->isPicture()) {
            return null;
        }

        $srcset = '';
        if ($media->thumbnailPath !== null && $media->hasDimensions() && (int) $media->width > 480) {
            $srcset = '/' . $media->thumbnailPath . ' 480w, ' . $media->publicPath() . ' ' . (int) $media->width . 'w';
        }

        return [
            'src' => $media->publicPath(),
            'alt' => $media->altText,
            'width' => $media->hasDimensions() ? $media->width : null,
            'height' => $media->hasDimensions() ? $media->height : null,
            'srcset' => $srcset,
        ];
    }

    /**
     * @param array<string, mixed> $topic an `article_topics` row
     * @return array<string, mixed>
     */
    private static function decorateTopic(array $topic, string $language): array
    {
        $id = (int) $topic['id'];
        $slug = ArticleLocalization::topicSlug($id, $language);

        return $topic + [
            'name' => ArticleLocalization::topicWord($id, ArticleLocalization::NAME, $language),
            'description' => ArticleLocalization::topicWord($id, ArticleLocalization::DESCRIPTION, $language),
            'url' => $slug === null ? null : ArticleUrls::topicPath($slug, 1, $language),
        ];
    }
}
