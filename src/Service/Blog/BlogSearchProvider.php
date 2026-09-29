<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogPostRepository;
use App\Service\Language\SiteText;
use App\Service\Media\MediaService;
use App\Service\Routing\LanguageResolver;
use App\Service\Search\SearchDocument;
use App\Service\Search\SearchProvider;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchText;

/**
 * The Blog's contribution to the site search (App\Module\BlogModule::
 * searchProviders()): posts, by title and excerpt.
 *
 * VISIBILITY is the post page's own rule: BlogPostRepository's one public
 * predicate (not a draft, published_at set and not in the future, with the
 * Blog's own clock), so a scheduled post turns up exactly when its page
 * does. A post marked noindex is left out, as BlogSeo::isIndexable() leaves
 * it out of the sitemap — the same honour Core's page search gives a page.
 *
 * THE ADDRESS is the one the blog overview links to in that language
 * (BlogContent::postUrl()'s rule, with the language passed in): the post's
 * own address there, else its default-language address.
 *
 * COST, per search: one LIKE per field over blog_post_translations (any
 * language, escaped), one query for the public rows, one for their words,
 * one for their featured pictures. Newest first, the Blog's own order.
 */
final class BlogSearchProvider implements SearchProvider
{
    public function label(string $language): string
    {
        return SiteText::pick(['nl' => 'Blogbericht', 'en' => 'Blog post'], $language);
    }

    public function documents(SearchQuery $query, string $language, int $limit): array
    {
        $translations = BlogLocalization::posts();
        $ids = array_values(array_unique(array_merge(
            $translations->ownersMatching(BlogLocalization::TITLE, $query->text),
            $translations->ownersMatching(BlogLocalization::EXCERPT, $query->text)
        )));
        if ($ids === []) {
            return [];
        }

        $posts = array_values(array_filter(
            (new BlogPostRepository())->findPublicByIds($ids, BlogClock::nowForSql()),
            static fn (array $post): bool => BlogSeo::isIndexable($post)
        ));
        if ($posts === []) {
            return [];
        }

        BlogLocalization::preloadPosts(array_map(static fn (array $post): int => (int) $post['id'], $posts));
        $pictures = MediaService::findMany(array_values(array_filter(array_map(
            static fn (array $post): int => (int) ($post['featured_media_id'] ?? 0),
            $posts
        ))));

        $documents = [];
        foreach ($posts as $post) {
            $id = (int) $post['id'];
            $title = trim(BlogLocalization::post($id, BlogLocalization::TITLE, $language));
            if ($title === '') {
                continue;
            }

            $picture = $pictures[(int) ($post['featured_media_id'] ?? 0)] ?? null;

            $documents[] = new SearchDocument(
                $title,
                SearchText::plain(BlogLocalization::post($id, BlogLocalization::EXCERPT, $language)),
                self::postUrl($post, $language),
                $picture?->displayPath()
            );

            if (count($documents) >= $limit) {
                break;
            }
        }

        return $documents;
    }

    /** BlogContent::postUrl(), for a language given rather than the request's. */
    private static function postUrl(array $post, string $language): string
    {
        $slug = BlogLocalization::postSlug($post, $language);
        if ($slug !== null) {
            return BlogUrls::postPath($slug, $language);
        }

        $default = LanguageResolver::defaultLanguage();

        return BlogUrls::postPath(BlogLocalization::postSlug($post, $default) ?? (string) ($post['slug'] ?? ''), $default);
    }
}
