<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\AppUrl;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\RouteSegments;

/**
 * Every public Blog URL, built in exactly one place.
 *
 * The same discipline the rest of this project applies to its content types
 * (App\Service\PageContent::canonicalUrl(), ProductSeo::canonicalUrl()): the
 * canonical tag, the Open Graph URL, the sitemap `<loc>`, the RSS `<link>`,
 * the slug-change redirect and every link the templates print all come from
 * here, so none of them can drift from the others. Absolute URLs resolve
 * against APP_URL (App\Service\AppUrl), never against the request's Host
 * header.
 *
 * THE PATHS, and the routes that serve them are App\Module\BlogModule's
 * publicRoutes() (docs/multilingual/ROUTING.md):
 *
 *   /blog                        the index          -> blog.php
 *   /blog?pagina=2               page 2 of it       -> blog.php
 *   /blog/<slug>                 one post           -> blog-post.php
 *   /blog/categorie/<slug>       a category archive -> blog.php
 *   /blog/tag/<slug>             a tag archive      -> blog.php
 *   /blog/feed.xml               the RSS feed       -> blog-feed.php
 *
 * EVERY ONE OF THEM TAKES A LANGUAGE since Multilingual 2.0 phase 6. The
 * default language's URLs are exactly the ones above; every other language
 * gets the same shape behind its own prefix, with its own slug and its own
 * word for a localized segment:
 *
 *   /en/blog/my-post             /en/blog/category/wood
 *
 * The prefix is App\Service\Routing\LocalizedUrl's and the segment words are
 * App\Service\Routing\RouteSegments', so the builder below and the matcher
 * in App\Service\Routing\RouteResolver read the same catalogue and cannot
 * spell a route differently.
 *
 * THE SLUG THAT IS PASSED IN IS ALREADY THE RIGHT LANGUAGE'S. Picking it is
 * App\Service\Blog\BlogLocalization's job — it owns the store the addresses
 * live in — because only that class can tell "this post has no English
 * address" from "this post's English address is empty", and those mean very
 * different things for a URL.
 *
 * The root word is a constant because three places need to agree on it: these
 * URLs, the reserved slug App\Module\BlogModule hands to
 * App\Service\ReservedRoutes, and the redirect paths a renamed post produces.
 */
final class BlogUrls
{
    /** The single URL segment the whole module lives under. */
    public const ROOT = 'blog';

    /** The query parameter that carries the page number of a listing. */
    public const PAGE_PARAM = 'pagina';

    /** The sub-namespaces of the two archive kinds. */
    public const CATEGORY_SEGMENT = 'categorie';
    public const TAG_SEGMENT = 'tag';

    /* ---------------------------------------------------------------- */
    /* Site-relative paths — what a template prints in an href           */
    /* ---------------------------------------------------------------- */

    public static function indexPath(int $page = 1, ?string $language = null): string
    {
        return self::paged(self::root($language), $page, $language);
    }

    public static function postPath(string $slug, ?string $language = null): string
    {
        return LocalizedUrl::path(self::root($language) . '/' . rawurlencode(trim($slug, '/')), $language);
    }

    public static function categoryPath(string $slug, int $page = 1, ?string $language = null): string
    {
        $path = self::root($language)
            . '/' . RouteSegments::value('blog.category', self::language($language))
            . '/' . rawurlencode(trim($slug, '/'));

        return self::paged($path, $page, $language);
    }

    public static function tagPath(string $slug, int $page = 1, ?string $language = null): string
    {
        $path = self::root($language)
            . '/' . RouteSegments::value('blog.tag', self::language($language))
            . '/' . rawurlencode(trim($slug, '/'));

        return self::paged($path, $page, $language);
    }

    public static function feedPath(?string $language = null): string
    {
        return LocalizedUrl::path(self::root($language) . '/feed.xml', $language);
    }

    /** The module's own first segment, in one language. */
    private static function root(?string $language): string
    {
        return '/' . RouteSegments::value('blog.root', self::language($language));
    }

    private static function language(?string $language): string
    {
        return $language ?? RequestLanguage::current();
    }

    /**
     * The prefix goes on the PATH and the page number after it, so a
     * paginated archive in another language reads /en/blog?pagina=2 rather
     * than something with the query in the middle.
     */
    private static function paged(string $path, int $page, ?string $language): string
    {
        $localized = LocalizedUrl::path($path, $language);

        return $page > 1 ? $localized . '?' . self::PAGE_PARAM . '=' . $page : $localized;
    }

    /* ---------------------------------------------------------------- */
    /* Absolute URLs — canonicals, sitemap, feed, redirects              */
    /* ---------------------------------------------------------------- */

    public static function index(int $page = 1, ?string $language = null): string
    {
        return AppUrl::canonical(self::indexPath($page, $language));
    }

    public static function post(string $slug, ?string $language = null): string
    {
        return AppUrl::canonical(self::postPath($slug, $language));
    }

    public static function category(string $slug, int $page = 1, ?string $language = null): string
    {
        return AppUrl::canonical(self::categoryPath($slug, $page, $language));
    }

    public static function tag(string $slug, int $page = 1, ?string $language = null): string
    {
        return AppUrl::canonical(self::tagPath($slug, $page, $language));
    }

    public static function feed(?string $language = null): string
    {
        return AppUrl::canonical(self::feedPath($language));
    }

    /**
     * The path a renamed post's OLD slug should redirect FROM, in the shape
     * App\Service\Redirects\SlugChangeRedirects takes (it prefixes a "/"
     * itself). Same builder as the post URL above, so the redirect's source
     * and the post's canonical are two halves of one rule.
     */
    public static function postRedirectPath(string $slug, ?string $language = null): string
    {
        return ltrim(self::root($language), '/') . '/' . trim($slug, '/');
    }

    /** The same for a renamed category archive. */
    public static function categoryRedirectPath(string $slug, ?string $language = null): string
    {
        return ltrim(self::root($language), '/')
            . '/' . RouteSegments::value('blog.category', self::language($language))
            . '/' . trim($slug, '/');
    }

    /** The same for a renamed tag archive. */
    public static function tagRedirectPath(string $slug, ?string $language = null): string
    {
        return ltrim(self::root($language), '/')
            . '/' . RouteSegments::value('blog.tag', self::language($language))
            . '/' . trim($slug, '/');
    }
}
