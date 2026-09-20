<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;

/**
 * THE one place a language prefix is put on a URL (docs/multilingual/ROUTING.md).
 *
 * THE CONTRACT, and nothing anywhere else may implement it:
 *
 *     default language      /            /over-ons      /blog/mijn-bericht
 *     any other language    /en/         /en/about-us   /en/blog/my-post
 *
 * "Default" is whatever App\Service\Language\SiteLanguages currently says it
 * is, looked up per call. Nothing here believes that unprefixed means Dutch:
 * make English the default and the English URLs lose their prefix while the
 * Dutch ones gain one, with no code change and no hardcoded pair of codes.
 *
 * WHAT CALLERS PASS IN is an ALREADY-LOCALIZED, UNPREFIXED, site-relative
 * path: "/about-us", not "/over-ons" and not "/en/about-us". Picking the
 * right slug for a language is the owning domain's job —
 * App\Service\PageContent for a page, App\Service\Blog\BlogUrls for the Blog,
 * App\Service\CollectionContent for a collection — because only that domain
 * knows where its slugs live. This class adds the prefix and nothing else,
 * which is exactly why no template ever has to concatenate "/en/" by hand.
 *
 * A QUERY STRING SURVIVES. path('/blog?pagina=2', 'en') is
 * '/en/blog?pagina=2': the prefix goes on the path, never in front of the
 * whole string.
 *
 * ABSOLUTE URLs go through App\Service\AppUrl, unchanged — the base URL is
 * still configuration and is still never taken from the request's Host
 * header, prefix or no prefix.
 */
final class LocalizedUrl
{
    /**
     * The prefix this language's URLs carry: '' for the default language,
     * '/xx' for every other one.
     *
     * A language this site does not publish gets no prefix rather than an
     * invented one — a link is better pointing at the default language than
     * at a URL that cannot resolve.
     */
    public static function prefix(?string $language = null): string
    {
        $code = $language ?? RequestLanguage::current();

        if ($code === '' || LanguageResolver::isDefault($code) || !SiteLanguages::isActive($code)) {
            return '';
        }

        return '/' . $code;
    }

    /**
     * A site-relative path in one language.
     *
     * $path is unprefixed and site-relative ('/', '/about-us',
     * '/blog?pagina=2'). A path that does not start with a slash gets one, so
     * a caller handing over 'shop.php' still produces a root-relative URL
     * rather than one that means something different on every page.
     */
    public static function path(string $path, ?string $language = null): string
    {
        $prefix = self::prefix($language);

        [$bare, $query] = self::split($path);

        $bare = '/' . ltrim($bare, '/');

        if ($prefix === '') {
            return $bare . $query;
        }

        // The language home keeps its trailing slash — '/en/' rather than
        // '/en' — so it reads as a root and matches what the dispatcher
        // normalizes towards.
        if ($bare === '/') {
            return $prefix . '/' . $query;
        }

        return $prefix . $bare . $query;
    }

    /**
     * The absolute form of path(), for a canonical tag, an hreflang
     * alternate, a sitemap <loc>, an RSS link or a redirect target.
     */
    public static function absolute(string $path, ?string $language = null): string
    {
        return AppUrl::canonical(self::path($path, $language));
    }

    /** The site root in one language: '/' or '/en/'. */
    public static function home(?string $language = null): string
    {
        return self::path('/', $language);
    }

    /**
     * Take a language prefix back OFF a path, whatever language it names.
     *
     * The inverse of path(), for the handful of places that hold a URL which
     * may already carry one — a stored return path, a form's source path —
     * and need the bare route before they can re-localize it.
     *
     * @return array{0: string, 1: string|null} the bare path, and the language
     *                                          the prefix named (null: none)
     */
    public static function strip(string $path): array
    {
        [$bare, $query] = self::split($path);

        $bare = '/' . ltrim($bare, '/');

        $first = explode('/', trim($bare, '/'))[0] ?? '';

        if ($first === '' || !SiteLanguages::isActive($first)) {
            return [$bare . $query, null];
        }

        $rest = substr($bare, strlen($first) + 1);
        $rest = ($rest === '' || $rest === '/') ? '/' : rtrim($rest, '/');

        return [$rest . $query, $first];
    }

    /**
     * @return array{0: string, 1: string} path, and the query/fragment tail
     *                                     including its leading '?' or '#'
     */
    private static function split(string $path): array
    {
        $cut = strcspn($path, '?#');

        return [substr($path, 0, $cut), substr($path, $cut)];
    }
}
