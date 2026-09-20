<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use App\Service\Language\LanguageFallback;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\LocalizedValue;
use App\Service\Media\BlockImage;
use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;
use App\Service\Routing\LanguageResolver;
use App\Service\Routing\RequestLanguage;
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
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 5 wave B). A post's title,
 * excerpt, body and SEO copy, a category's name and description and a tag's
 * name are stored per website language and read through
 * App\Service\Blog\BlogLocalization, which states the one fallback: the
 * asked-for language, the default language, ''. This class offers both shapes
 * of the same words — title()/excerpt()/body() for ONE language, which is what
 * the SEO head, the RSS feed and the JSON-LD want, and titleValue() and
 * friends as one LocalizedValue, which is what a template prints through
 * App\Service\Language\SiteText. It decides no language itself, and a third
 * language is a row in `site_languages`.
 *
 * Everything else a post has is language-neutral: THE SLUG above all — there
 * are no separate English URLs (SEO.md) and a translation never moves an
 * address — plus the status, the publication date, the author, the images and
 * the taxonomy relations. Which categories and tags a post has, and in which
 * order, is the same in every language; only their labels differ.
 *
 * RICH TEXT is re-sanitised on the way OUT as well as on the way in, exactly
 * as PortfolioGalleryContent does: the body was already cleaned when it was
 * saved, and cleaning it again means a row written before a rule tightened,
 * or by hand in the database, still cannot put a script on a public page.
 * BlogLocalization is where that happens, once, for every reader.
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

        // Same rule as a post's address: this language's own slug, with the
        // neutral column answering for the default language.
        $language = RequestLanguage::current();

        if ($categorySlug !== '') {
            $category = self::activeCategoryBySlug($categorySlug, $language);
            if ($category === null) {
                return null;
            }
            $mode = 'category';
        } elseif ($tagSlug !== '') {
            $tag = self::tagBySlug($tagSlug, $language);
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
    public static function post(string $slug, ?string $language = null): ?array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $repository = new BlogPostRepository();
        $now = BlogClock::nowForSql();
        $language ??= RequestLanguage::current();

        /**
         * THE ADDRESS BELONGS TO ONE LANGUAGE (docs/multilingual/ROUTING.md):
         * /en/blog/my-post asks for the post whose ENGLISH address it is, and
         * gets nothing when only its Dutch address matches.
         *
         * The neutral `blog_posts.slug` still answers for the DEFAULT
         * language, so every URL that existed before phase 6 keeps working
         * even for a post whose localized row was never written.
         */
        $postId = BlogLocalization::posts()->ownerForSlug($slug, $language);
        $row = $postId === null ? null : $repository->findPublicById($postId, $now);

        if ($row === null && $language === LanguageResolver::defaultLanguage()) {
            $row = $repository->findPublicBySlug($slug, $now);
        }

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
        return BlogLocalization::post(self::idOf($post), BlogLocalization::TITLE, $lang);
    }

    /**
     * The same five values as one LocalizedValue each, the fallback already
     * applied — what a public template prints through
     * App\Service\Language\SiteText. The $lang accessors above stay for the
     * callers that genuinely want ONE language: the SEO head's V1 pair, the
     * RSS feed (Dutch) and the JSON-LD.
     *
     * @param array<string, mixed> $post
     */
    public static function titleValue(array $post): LocalizedValue
    {
        return BlogLocalization::postValue(self::idOf($post), BlogLocalization::TITLE);
    }

    /**
     * The teaser's pair, with the same "own excerpt, else the opening of the
     * body" rule per language as excerpt() below.
     *
     * @param array<string, mixed> $post
     */
    public static function excerptValue(array $post): LocalizedValue
    {
        $words = [];
        foreach (LanguageRegistry::codes() as $code) {
            $teaser = self::excerpt($post, $code);
            if ($teaser !== '') {
                $words[$code] = $teaser;
            }
        }

        return LanguageFallback::bilingual($words);
    }

    /** @param array<string, mixed> $post */
    public static function bodyValue(array $post): LocalizedValue
    {
        return BlogLocalization::bodyValue(self::idOf($post));
    }

    /** @param array<string, mixed> $category */
    public static function categoryNameValue(array $category): LocalizedValue
    {
        return BlogLocalization::categoryNameValue(self::idOf($category));
    }

    /** @param array<string, mixed> $tag */
    public static function tagNameValue(array $tag): LocalizedValue
    {
        return BlogLocalization::tagNameValue(self::idOf($tag));
    }

    /** @param array<string, mixed> $row */
    private static function idOf(array $row): int
    {
        return (int) ($row['id'] ?? 0);
    }

    /**
     * Tag rows in the order their CMS labels read, as the alphabet of the
     * DEFAULT language: the same order on every language of the site.
     *
     * @param list<array<string, mixed>> $tags
     * @return list<array<string, mixed>>
     */
    private static function sortedByLabel(array $tags): array
    {
        usort($tags, static fn (array $a, array $b): int => strnatcasecmp(
            BlogLocalization::tagLabel((int) $a['id']),
            BlogLocalization::tagLabel((int) $b['id'])
        ));

        return $tags;
    }

    /**
     * Every id in a per-post map of taxonomy rows, once.
     *
     * @param array<int, array<int, array<string, mixed>>> $byPostId
     * @return list<int>
     */
    private static function idsOf(array $byPostId): array
    {
        $ids = [];
        foreach ($byPostId as $rows) {
            foreach ($rows as $row) {
                $ids[(int) $row['id']] = true;
            }
        }

        return array_map('intval', array_keys($ids));
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
        $own = BlogLocalization::post(self::idOf($post), BlogLocalization::EXCERPT, $lang);

        if (trim($own) !== '') {
            return trim($own);
        }

        return Seo::excerpt(self::body($post, $lang));
    }

    /**
     * The sanitised rich-text body, ready to print as markup. The sanitizer is
     * App\Service\Blog\BlogLocalization's, which is the Blog's only one.
     *
     * @param array<string, mixed> $post
     */
    public static function body(array $post, string $lang = 'nl'): string
    {
        return BlogLocalization::body(self::idOf($post), $lang);
    }

    /** @param array<string, mixed> $category */
    public static function categoryName(array $category, string $lang = 'nl'): string
    {
        return BlogLocalization::categoryName(self::idOf($category), $lang);
    }

    /** @param array<string, mixed> $tag */
    public static function tagName(array $tag, string $lang = 'nl'): string
    {
        return BlogLocalization::tagName(self::idOf($tag), $lang);
    }

    /**
     * A category's own short introduction above its archive, as a pair.
     *
     * @param array<string, mixed> $category
     */
    public static function categoryDescriptionValue(array $category): LocalizedValue
    {
        return BlogLocalization::categoryDescriptionValue(self::idOf($category));
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

        // And the words of the lot in one query per store, so a page of nine
        // cards with their category and tag chips costs three lookups rather
        // than three per card.
        BlogLocalization::preloadPosts($ids);
        BlogLocalization::preloadCategories(self::idsOf($categories));
        BlogLocalization::preloadTags(self::idsOf($tags));

        $decorated = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $decorated[] = self::withDecoration($row, $categories[$id] ?? [], $tags[$id] ?? []);
        }

        return $decorated;
    }

    /* ------------------------------------------------------------------ */
    /* Addresses                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * One post's URL in the language this page is being read in.
     *
     * A post with no address in that language is linked at its DEFAULT
     * language address instead of not at all: a card, a neighbour link or a
     * related post is somebody asking to go there, and landing on a real post
     * in another language beats landing on nothing. The page they land on
     * says in its own canonical tag and <html lang> which language it is.
     *
     * The language SWITCH is the one place that rule is reversed — see
     * docs/multilingual/ROUTING.md.
     *
     * @param array<string, mixed> $row a `blog_posts` row
     */
    public static function postUrl(array $row): string
    {
        $language = RequestLanguage::current();
        $slug = BlogLocalization::postSlug($row, $language);

        if ($slug !== null) {
            return BlogUrls::postPath($slug, $language);
        }

        $default = LanguageResolver::defaultLanguage();

        return BlogUrls::postPath(
            BlogLocalization::postSlug($row, $default) ?? (string) ($row['slug'] ?? ''),
            $default
        );
    }

    /**
     * The ABSOLUTE form of postUrl(), for a canonical tag, a feed item and
     * structured data — three things that must never name three addresses.
     *
     * A decorated row already carries it; a raw row is resolved on the spot,
     * which is what the feed hands over.
     *
     * @param array<string, mixed> $row
     */
    public static function postCanonical(array $row): string
    {
        $canonical = trim((string) ($row['canonical_url'] ?? ''));

        return $canonical !== '' ? $canonical : AppUrl::canonical(self::postUrl($row));
    }

    /** @param array<string, mixed> $category a `blog_categories` row */
    public static function categoryUrl(array $category, int $page = 1): string
    {
        $language = RequestLanguage::current();
        $slug = BlogLocalization::categorySlug($category, $language);

        if ($slug !== null) {
            return BlogUrls::categoryPath($slug, $page, $language);
        }

        $default = LanguageResolver::defaultLanguage();

        return BlogUrls::categoryPath(
            BlogLocalization::categorySlug($category, $default) ?? (string) ($category['slug'] ?? ''),
            $page,
            $default
        );
    }

    /** @param array<string, mixed> $tag a `blog_tags` row */
    public static function tagUrl(array $tag, int $page = 1): string
    {
        $language = RequestLanguage::current();
        $slug = BlogLocalization::tagSlug($tag, $language);

        if ($slug !== null) {
            return BlogUrls::tagPath($slug, $page, $language);
        }

        $default = LanguageResolver::defaultLanguage();

        return BlogUrls::tagPath(
            BlogLocalization::tagSlug($tag, $default) ?? (string) ($tag['slug'] ?? ''),
            $page,
            $default
        );
    }

    /**
     * Every language one post can be READ in, code => site-relative path, for
     * App\Service\Routing\LanguageAlternates. Only the languages whose
     * address really exists: an alternate may never name a URL that 404s.
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public static function postAlternates(array $row): array
    {
        $paths = [];

        foreach (SiteLanguages::activeCodes() as $code) {
            $slug = BlogLocalization::postSlug($row, $code);

            if ($slug !== null) {
                $paths[$code] = BlogUrls::postPath($slug, $code);
            }
        }

        return $paths;
    }

    /**
     * The active category one address names in one language, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function activeCategoryBySlug(string $slug, string $language): ?array
    {
        $categories = new BlogCategoryRepository();

        $id = BlogLocalization::categories()->ownerForSlug($slug, $language);
        $category = $id === null ? null : $categories->find($id);

        if ($category !== null && (int) ($category['is_active'] ?? 0) !== 1) {
            $category = null;
        }

        if ($category === null && $language === LanguageResolver::defaultLanguage()) {
            $category = $categories->findActiveBySlug($slug);
        }

        return $category;
    }

    /**
     * The tag one address names in one language, or null.
     *
     * @return array<string, mixed>|null
     */
    private static function tagBySlug(string $slug, string $language): ?array
    {
        $tags = new BlogTagRepository();

        $id = BlogLocalization::tags()->ownerForSlug($slug, $language);
        $tag = $id === null ? null : $tags->find($id);

        if ($tag === null && $language === LanguageResolver::defaultLanguage()) {
            $tag = $tags->findBySlug($slug);
        }

        return $tag;
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
        // The address of the language this page is being read in, and the
        // default language's when this one has none: a card must land a
        // visitor on a real post rather than nowhere
        // (docs/multilingual/ROUTING.md, "Links to content without a route").
        $row['url'] = self::postUrl($row);
        $row['canonical_url'] = AppUrl::canonical($row['url']);

        // The featured image comes straight from the Media Library: there is
        // no legacy path column on this table and no per-post alt override,
        // so the item's own alt text and dimensions are used as they are
        // (MEDIA.md). BlockImage is asked for a path column that does not
        // exist, which is precisely how it reports "no image" when the post
        // has none.
        $row['image'] = BlockImage::fromRow($row, 'featured_media_id', 'featured_image_path');
        $row['has_image'] = $row['image']['image_path'] !== '';

        $row['categories'] = array_map(static fn (array $category): array => self::decorateCategory($category), $categories);
        // Tags have no sort order of their own, so they used to be ordered on
        // their Dutch name in SQL. They are sorted here instead, on what the
        // CMS calls them, because the column is gone and an order that follows
        // the reader's language would shuffle the chips per language.
        $row['tags'] = self::sortedByLabel(array_map(
            static fn (array $tag): array => self::decorateTag($tag),
            $tags
        ));
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

        // Its id travels along, because that is what its words hang off since
        // Multilingual 2.0 phase 5 wave B: the template asks
        // BlogContent::titleValue() for the pair it prints.
        return [
            'id' => (int) $row['id'],
            'url' => self::postUrl($row),
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
        $category['url'] = self::categoryUrl($category);
        $category['canonical_url'] = AppUrl::canonical($category['url']);

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
        $tag['url'] = self::tagUrl($tag);
        $tag['canonical_url'] = AppUrl::canonical($tag['url']);

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

        $active = (new BlogCategoryRepository())->allActive();
        BlogLocalization::preloadCategories(array_map(
            static fn (array $category): int => (int) $category['id'],
            $active
        ));

        $categories = [];
        foreach ($active as $category) {
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
