<?php

declare(strict_types=1);

namespace App\Service\Articles;

use App\Service\AppUrl;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\RouteSegments;

/**
 * Every public Articles URL, built in one place (the BlogUrls discipline):
 *
 *   /artikelen                       the listing          -> articles.php
 *   /artikelen?pagina=2              page 2 of it
 *   /artikelen/onderwerp/<slug>      one topic's listing  -> articles.php
 *   /artikelen/<slug>                one article          -> article.php
 *
 * In another language the same shape behind its prefix, with its own words
 * for the two fixed segments (App\Module\ArticlesModule::routeSegments()):
 * /en/articles/<slug>, /en/articles/topic/<slug>. The slug passed in is
 * already that language's (ArticleLocalization).
 */
final class ArticleUrls
{
    public const ROOT_SEGMENT_KEY = 'articles.root';
    public const TOPIC_SEGMENT_KEY = 'articles.topic';

    public const ROOT = 'artikelen';
    public const TOPIC_SEGMENT = 'onderwerp';
    public const PAGE_PARAM = 'pagina';

    public static function indexPath(int $page = 1, ?string $language = null): string
    {
        return self::paged(self::root($language), $page, $language);
    }

    public static function articlePath(string $slug, ?string $language = null): string
    {
        return LocalizedUrl::path(self::root($language) . '/' . rawurlencode(trim($slug, '/')), $language);
    }

    public static function topicPath(string $slug, int $page = 1, ?string $language = null): string
    {
        return self::paged(self::topicBase($language) . '/' . rawurlencode(trim($slug, '/')), $page, $language);
    }

    public static function article(string $slug, ?string $language = null): string
    {
        return AppUrl::canonical(self::articlePath($slug, $language));
    }

    /** The path a renamed article's old slug redirects from, in SlugChangeRedirects' shape. */
    public static function articleRedirectPath(string $slug, ?string $language = null): string
    {
        return ltrim(self::root($language), '/') . '/' . trim($slug, '/');
    }

    /** The same for a renamed topic. */
    public static function topicRedirectPath(string $slug, ?string $language = null): string
    {
        return ltrim(self::topicBase($language), '/') . '/' . trim($slug, '/');
    }

    private static function root(?string $language): string
    {
        return '/' . RouteSegments::value(self::ROOT_SEGMENT_KEY, $language ?? RequestLanguage::current());
    }

    private static function topicBase(?string $language): string
    {
        return self::root($language) . '/' . RouteSegments::value(self::TOPIC_SEGMENT_KEY, $language ?? RequestLanguage::current());
    }

    private static function paged(string $path, int $page, ?string $language): string
    {
        $localized = LocalizedUrl::path($path, $language);

        return $page > 1 ? $localized . '?' . self::PAGE_PARAM . '=' . $page : $localized;
    }
}
