<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Repository\ArticleRepository;
use App\Service\Language\SiteText;
use App\Service\Media\MediaService;
use App\Service\Publishing\PublishingClock;
use App\Service\Search\SearchCandidates;
use App\Service\Search\SearchDocument;
use App\Service\Search\SearchProvider;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchText;

/**
 * Articles in the current site search (SEARCH.md), with exactly what the
 * other providers give: title, intro, address, type and picture.
 *
 * Only LISTED, indexable articles (ArticleSeo::isIndexable()): no draft, no
 * future scheduled article, nothing archived, nothing marked noindex. Only
 * in a language the article has a version in, at that version's address and
 * in its own words.
 *
 * NOT the text of its content blocks. Search 2.0 is where block text gets
 * indexed (docs/publishing/ARCHITECTURE.md, "Zoeken").
 */
final class ArticleSearchProvider implements SearchProvider
{
    public function label(string $language): string
    {
        return SiteText::pick(['nl' => 'Artikel', 'en' => 'Article'], $language);
    }

    public function documents(SearchQuery $query, string $language, int $limit): array
    {
        $ids = SearchCandidates::ids(ArticleLocalization::articles(), [ArticleLocalization::TITLE, ArticleLocalization::EXCERPT], $query);
        if ($ids === []) {
            return [];
        }

        $rows = array_values(array_filter(
            (new ArticleRepository())->findListedByIds($ids, PublishingClock::nowForSql()),
            static fn (array $row): bool => ArticleSeo::isIndexable($row)
        ));
        if ($rows === []) {
            return [];
        }

        ArticleLocalization::preload(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $pictures = MediaService::findMany(array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['featured_media_id'] ?? 0),
            $rows
        ))));

        $documents = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $slug = ArticleLocalization::slug($id, $language);
            $title = trim(ArticleLocalization::word($id, ArticleLocalization::TITLE, $language));

            if ($slug === null || $title === '') {
                continue;
            }

            $documents[] = new SearchDocument(
                $title,
                SearchText::plain(ArticleLocalization::word($id, ArticleLocalization::EXCERPT, $language)),
                ArticleUrls::articlePath($slug, $language),
                ($pictures[(int) ($row['featured_media_id'] ?? 0)] ?? null)?->displayPath()
            );

            if (count($documents) >= $limit) {
                break;
            }
        }

        return $documents;
    }
}
