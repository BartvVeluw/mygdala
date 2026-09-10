<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Service\Media\BlockImage;
use App\Service\RichTextSanitizer;
use App\Service\Seo;

/**
 * The public read model of the Blog: what /blog, /blog/<slug>,
 * /blog/categorie/<slug> and /blog/tag/<slug> render, already resolved.
 *
 * Same shape as every other *Content class in this project
 * (App\Service\PageContent, CollectionContent, PortfolioGalleryContent):
 * static, null-tolerant, and the only place a template's data comes from. A
 * template asks one question and prints the answer; it runs no query, decides
 * no visibility and builds no URL.
 *
 * WHAT "PUBLIC" MEANS is decided in exactly one place —
 * App\Repository\BlogPostRepository's PUBLIC_WHERE, with
 * App\Service\Blog\BlogPostStatus::isPublic() as its in-PHP twin — so a
 * draft, a post scheduled for tomorrow and a slug nobody owns are one answer:
 * null. The listing, the archives, the related posts, the feed and the
 * sitemap all read through the same rule.
 *
 * BILINGUAL like the rest of the site: the Dutch value is the real content,
 * an empty English one falls back to it (App\Service\Seo::pick()), and both
 * ride along in data-nl/data-en so assets/js/core.js can swap them in the
 * browser. There are no separate English URLs (SEO.md).
 *
 * RICH TEXT is re-sanitised on the way OUT as well as on the way in, exactly
 * as PortfolioGalleryContent does: the body was already cleaned when it was
 * saved, and cleaning it again means a row written before a rule tightened,
 * or by hand in the database, still cannot put a script on a public page.
 */
final class BlogContent
{
    /** How many posts a related-posts row may show. */
    public const RELATED_LIMIT = BlogSettings::RELATED_POSTS_LIMIT;

    /**
     * One page of a listing — the index, a category archive or a tag archive.
     *
     * Returns null when the archive itself does not exist (an unknown or
     * inactive category, an unknown tag), which is the route's 404. An
     * EXISTING archive with no posts is not null: it renders its own heading
     * and an honest "nog geen berichten", because the URL is real.
     *
     * @param array{category?: string, tag?: string, page?: int} $request
     *
     * @return array{
     *     mode: string,
     *     posts: list<array<string, mixed>>,
     *     page: int,
     *     pages: int,
     *     total: int,
     *     per_page: int,
     *     category: array<string, mixed>|null,
     *     tag: array<string, mixed>|null,
     *     categories: list<array<string, mixed>>
     * }|null
     */
    public static function listing(array $request): ?array
    {
        $posts = new BlogPostRepository();
        $now = BlogClock::nowForSql();

        $categorySlug = trim((string) ($request['category'] ?? ''));
        $tagSlug = trim((string) ($request['tag'] ?? ''));

        $category = null;
        $tag = null;
        $mode = 'index';

        if ($categorySlug !== '') {
            $category = (new BlogCategoryRepository())->findActiveBySlug($categorySlug);
            if ($category === null) {
                return null;
            }
            $mode = 'category';
        } elseif ($tagSlug !== '') {
            $tag = (new BlogTagRepository())->findBySlug($tagSlug);
            if ($tag === null) {
                return null;
            }
            $mode = 'tag';
        }

        $perPage = BlogSettings::postsPerPage();
        $total = $posts->countPublic($now, $category === null ? null : (int) $category['id'], $tag === null ? null : (int) $tag['id']);
        $pages = max(1, (int) ceil($total / $perPage));

        // A page number beyond the last one is clamped rather than 404'd: it
        // is a stale link or a crawler walking past the end, and the last
        // page is the honest answer. The canonical tag then points at the
        // page actually rendered, so nothing is indexed twice.
        $page = max(1, (int) ($request['page'] ?? 1));
        $page = min($page, $pages);

        $rows = $posts->findPublic(
            $now,
            $perPage,
            ($page - 1) * $perPage,
            $category === null ? null : (int) $category['id'],
            $tag === null ? null : (int) $tag['id']
        );

        return [
            'mode' => $mode,
            'posts' => self::decorateMany($rows, $posts),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
            'category' => $category === null ? null : self::decorateCategory($category),
            'tag' => $tag === null ? null : self::decorateTag($tag),
            // The filter row above a listing: only categories that actually
            // have something public in them, so a visitor is never offered a
            // link to an empty archive.
            'categories' => self::publicCategories($posts, $now),
        ];
    }

    /**
     * One post's public page, or null when there is nothing to show at this
     * URL. Includes everything the detail template renders, so it makes one
     * call and prints the result.
     *
     * @return array<string, mixed>|null
     */
    public static function post(string $slug): ?array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $repository = new BlogPostRepository();
        $now = BlogClock::nowForSql();

        $row = $repository->findPublicBySlug($slug, $now);

        if ($row === null) {
            return null;
        }

        $post = self::decorate($row, $repository);

        $post['previous'] = self::decorateNeighbour($repository->findNeighbour($row, $now, 'previous'));
        $post['next'] = self::decorateNeighbour($repository->findNeighbour($row, $now, 'next'));
        $post['related'] = BlogSettings::relatedPostsEnabled()
            ? self::decorateMany(
                $repository->findRelated(
                    (int) $row['id'],
                    array_column($post['categories'], 'id'),
                    array_column($post['tags'], 'id'),
                    $now,
                    self::RELATED_LIMIT
                ),
                $repository
            )
            : [];

        return $post;
    }

    /**
     * The row the ADMIN edits, decorated the same way, so the editor's
     * preview of an image or a category reads from one implementation. Unlike
     * post() this ignores visibility: an editor must be able to open a draft.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function decorateForAdmin(array $row): array
    {
        return self::decorate($row, new BlogPostRepository());
    }

    /* ------------------------------------------------------------------ */
    /* Per-language values                                                 */
    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $post */
    public static function title(array $post, string $lang = 'nl'): string
    {
        return Seo::pick($post['title'] ?? '', $post['title_en'] ?? '', $lang);
    }

    /**
     * The listing teaser. The editor's own excerpt if there is one; otherwise
     * a short plain-text opening of the body, cut on a word boundary by the
     * shared helper every other content type here uses.
     *
     * @param array<string, mixed> $post
     */
    public static function excerpt(array $post, string $lang = 'nl'): string
    {
        $own = Seo::pick($post['excerpt'] ?? '', $post['excerpt_en'] ?? '', $lang);

        if (trim($own) !== '') {
            return trim($own);
        }

        return Seo::excerpt(self::body($post, $lang));
    }

    /**
     * The sanitised rich-text body, ready to print as markup.
     *
     * @param array<string, mixed> $post
     */
    public static function body(array $post, string $lang = 'nl'): string
    {
        $html = Seo::pick($post['body'] ?? '', $post['body_en'] ?? '', $lang);

        return (string) (RichTextSanitizer::sanitize($html) ?? '');
    }

    /** @param array<string, mixed> $category */
    public static function categoryName(array $category, string $lang = 'nl'): string
    {
        return Seo::pick($category['name'] ?? '', $category['name_en'] ?? '', $lang);
    }

    /** @param array<string, mixed> $tag */
    public static function tagName(array $tag, string $lang = 'nl'): string
    {
        return Seo::pick($tag['name'] ?? '', $tag['name_en'] ?? '', $lang);
    }

    /**
     * The publication date as a Dutch long date ("4 maart 2026"), or '' when
     * there is nothing to print. The month names are spelled out here rather
     * than left to strftime(), which is deprecated and locale-dependent —
     * shared hosting has no guaranteed Dutch locale installed.
     */
    public static function publicationDate(mixed $publishedAt, string $lang = 'nl'): string
    {
        $moment = BlogClock::parse($publishedAt);

        if ($moment === null) {
            return '';
        }

        if ($lang === 'en') {
            return $moment->format('j F Y');
        }

        $months = [
            1 => 'januari', 'februari', 'maart', 'april', 'mei', 'juni',
            'juli', 'augustus', 'september', 'oktober', 'november', 'december',
        ];

        return $moment->format('j') . ' ' . $months[(int) $moment->format('n')] . ' ' . $moment->format('Y');
    }

    /** The machine-readable half of a <time> element, or ''. */
    public static function publicationDateAttribute(mixed $publishedAt): string
    {
        return BlogClock::parse($publishedAt)?->format(\DateTimeInterface::ATOM) ?? '';
    }

    /**
     * The author line, or '' when this post has none or the site has switched
     * bylines off. One question, one answer, so no template repeats the
     * setting check.
     *
     * @param array<string, mixed> $post
     */
    public static function author(array $post): string
    {
        if (!BlogSettings::showAuthor()) {
            return '';
        }

        return trim((string) ($post['author_name'] ?? ''));
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * One post row plus everything a card or a detail page needs: its image,
     * its taxonomy and its URL.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decorate(array $row, BlogPostRepository $repository): array
    {
        $id = (int) $row['id'];

        $categories = $repository->categoriesForPosts([$id])[$id] ?? [];
        $tags = $repository->tagsForPosts([$id])[$id] ?? [];

        return self::withDecoration($row, $categories, $tags);
    }

    /**
     * The same for a whole page of posts, in TWO queries for the lot rather
     * than two per post — the listing shows nine cards and each one names its
     * category.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function decorateMany(array $rows, BlogPostRepository $repository): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $categories = $repository->categoriesForPosts($ids);
        $tags = $repository->tagsForPosts($ids);

        $decorated = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $decorated[] = self::withDecoration($row, $categories[$id] ?? [], $tags[$id] ?? []);
        }

        return $decorated;
    }

    /**
     * @param array<string, mixed>             $row
     * @param array<int, array<string, mixed>> $categories
     * @param array<int, array<string, mixed>> $tags
     *
     * @return array<string, mixed>
     */
    private static function withDecoration(array $row, array $categories, array $tags): array
    {
        $row['url'] = BlogUrls::postPath((string) $row['slug']);
        $row['canonical_url'] = BlogUrls::post((string) $row['slug']);

        // The featured image comes straight from the Media Library: there is
        // no legacy path column on this table and no per-post alt override,
        // so the item's own alt text and dimensions are used as they are
        // (MEDIA.md). BlockImage is asked for a path column that does not
        // exist, which is precisely how it reports "no image" when the post
        // has none.
        $row['image'] = BlockImage::fromRow($row, 'featured_media_id', 'featured_image_path');
        $row['has_image'] = $row['image']['image_path'] !== '';

        $row['categories'] = array_map(static fn (array $category): array => self::decorateCategory($category), $categories);
        $row['tags'] = array_map(static fn (array $tag): array => self::decorateTag($tag), $tags);
        // The PRIMARY category is simply the first one in the editor's own
        // ordering — there is no "is primary" column that could disagree with
        // it. A card shows this one; the detail page shows them all.
        $row['primary_category'] = $row['categories'][0] ?? null;

        return $row;
    }

    /**
     * A neighbour link needs a title and a URL and nothing else, so it is not
     * decorated with images and taxonomy nobody prints.
     *
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>|null
     */
    private static function decorateNeighbour(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'title_en' => (string) ($row['title_en'] ?? ''),
            'url' => BlogUrls::postPath((string) $row['slug']),
        ];
    }

    /**
     * @param array<string, mixed> $category
     *
     * @return array<string, mixed>
     */
    private static function decorateCategory(array $category): array
    {
        $category['id'] = (int) $category['id'];
        $category['url'] = BlogUrls::categoryPath((string) $category['slug']);
        $category['canonical_url'] = BlogUrls::category((string) $category['slug']);

        return $category;
    }

    /**
     * @param array<string, mixed> $tag
     *
     * @return array<string, mixed>
     */
    private static function decorateTag(array $tag): array
    {
        $tag['id'] = (int) $tag['id'];
        $tag['url'] = BlogUrls::tagPath((string) $tag['slug']);
        $tag['canonical_url'] = BlogUrls::tag((string) $tag['slug']);

        return $tag;
    }

    /**
     * Active categories that hold at least one public post, with their count.
     * Two queries whatever the number of categories.
     *
     * @return list<array<string, mixed>>
     */
    public static function publicCategories(?BlogPostRepository $posts = null, ?string $now = null): array
    {
        $posts ??= new BlogPostRepository();
        $counts = $posts->publicCountsByCategory($now ?? BlogClock::nowForSql());

        $categories = [];
        foreach ((new BlogCategoryRepository())->allActive() as $category) {
            $count = $counts[(int) $category['id']] ?? 0;

            if ($count < 1) {
                continue;
            }

            $decorated = self::decorateCategory($category);
            $decorated['post_count'] = $count;
            $categories[] = $decorated;
        }

        return $categories;
    }
}
