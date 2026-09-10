<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Service\AppUrl;

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
 * THE PATHS, and their rewrites live in .htaccess:
 *
 *   /blog                        the index          -> blog.php
 *   /blog?pagina=2               page 2 of it       -> blog.php
 *   /blog/<slug>                 one post           -> blog-post.php
 *   /blog/categorie/<slug>       a category archive -> blog.php
 *   /blog/tag/<slug>             a tag archive      -> blog.php
 *   /blog/feed.xml               the RSS feed       -> blog-feed.php
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

    public static function indexPath(int $page = 1): string
    {
        $path = '/' . self::ROOT;

        return $page > 1 ? $path . '?' . self::PAGE_PARAM . '=' . $page : $path;
    }

    public static function postPath(string $slug): string
    {
        return '/' . self::ROOT . '/' . rawurlencode(trim($slug, '/'));
    }

    public static function categoryPath(string $slug, int $page = 1): string
    {
        $path = '/' . self::ROOT . '/' . self::CATEGORY_SEGMENT . '/' . rawurlencode(trim($slug, '/'));

        return $page > 1 ? $path . '?' . self::PAGE_PARAM . '=' . $page : $path;
    }

    public static function tagPath(string $slug, int $page = 1): string
    {
        $path = '/' . self::ROOT . '/' . self::TAG_SEGMENT . '/' . rawurlencode(trim($slug, '/'));

        return $page > 1 ? $path . '?' . self::PAGE_PARAM . '=' . $page : $path;
    }

    public static function feedPath(): string
    {
        return '/' . self::ROOT . '/feed.xml';
    }

    /* ---------------------------------------------------------------- */
    /* Absolute URLs — canonicals, sitemap, feed, redirects              */
    /* ---------------------------------------------------------------- */

    public static function index(int $page = 1): string
    {
        return AppUrl::canonical(self::indexPath($page));
    }

    public static function post(string $slug): string
    {
        return AppUrl::canonical(self::postPath($slug));
    }

    public static function category(string $slug, int $page = 1): string
    {
        return AppUrl::canonical(self::categoryPath($slug, $page));
    }

    public static function tag(string $slug, int $page = 1): string
    {
        return AppUrl::canonical(self::tagPath($slug, $page));
    }

    public static function feed(): string
    {
        return AppUrl::canonical(self::feedPath());
    }

    /**
     * The path a renamed post's OLD slug should redirect FROM, in the shape
     * App\Service\Redirects\SlugChangeRedirects takes (it prefixes a "/"
     * itself). Same builder as the post URL above, so the redirect's source
     * and the post's canonical are two halves of one rule.
     */
    public static function postRedirectPath(string $slug): string
    {
        return self::ROOT . '/' . trim($slug, '/');
    }

    /** The same for a renamed category archive. */
    public static function categoryRedirectPath(string $slug): string
    {
        return self::ROOT . '/' . self::CATEGORY_SEGMENT . '/' . trim($slug, '/');
    }

    /** The same for a renamed tag archive. */
    public static function tagRedirectPath(string $slug): string
    {
        return self::ROOT . '/' . self::TAG_SEGMENT . '/' . trim($slug, '/');
    }
}
