<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogPostRepository;
use App\Service\Seo;
use App\Service\SeoDefaults;

/**
 * /blog/feed.xml — an RSS 2.0 document built from the database on every
 * request, exactly like App\Service\Sitemap builds /sitemap.xml and for the
 * same reason: a stored file is a file somebody has to remember to
 * regenerate.
 *
 * WHAT GOES IN. The newest PUBLIC posts, through the very same rule the
 * listing uses (App\Repository\BlogPostRepository's PUBLIC_WHERE), so a draft
 * and a post scheduled for next week cannot appear here either. A `noindex`
 * post DOES stay in the feed: noindex is an instruction to a search engine
 * about its result pages, and a subscriber who asked for this blog's posts
 * has asked for all of them.
 *
 * WHAT IT CARRIES. Per item: the title, the post's own canonical URL as both
 * link and guid, its publication date, and its excerpt as the description.
 * The full body is deliberately NOT syndicated — a feed that reprints an
 * article gives a reader no reason to open it, and this project has no
 * content:encoded namespace to put it in properly.
 *
 * IDENTITY comes from the site's own settings and APP_URL
 * (App\Service\AppUrl through BlogUrls): the channel title is the Blog's
 * title, the link is the Blog index, the description is the Blog's
 * introduction or the site's default. No domain name and no company name is
 * written into this class.
 *
 * ONE LANGUAGE: the site's DEFAULT one. A feed is a single document at a
 * single address and carries no language switch, so it says what a visitor who
 * has not chosen sees. It used to say "Dutch" in so many words; since
 * Multilingual 2.0 phase 5 wave B it asks
 * App\Service\Blog\BlogLocalization::defaultLanguage(), which on every
 * existing installation is exactly that. A feed per language belongs to the
 * routing phase, with the URLs that would carry it.
 *
 * ESCAPING. Every value goes through htmlspecialchars(ENT_XML1) on its way
 * in, like Sitemap does. Titles and excerpts are administrator-typed content
 * and may hold ampersands and angle brackets; a feed reader is a parser, not
 * a browser, and a broken document simply does not load.
 */
final class BlogFeed
{
    public const CONTENT_TYPE = 'application/rss+xml; charset=UTF-8';

    /** How many posts one feed document holds. A window, not an archive. */
    public const MAX_ITEMS = 20;

    /** The complete document. */
    public static function xml(): string
    {
        $posts = (new BlogPostRepository())->findPublicForFeed(BlogClock::nowForSql(), self::MAX_ITEMS);

        return self::toXml($posts);
    }

    /**
     * @param array<int, array<string, mixed>> $posts
     */
    public static function toXml(array $posts): string
    {
        $channelTitle = self::channelTitle();
        $channelDescription = self::channelDescription();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . self::escape($channelTitle) . '</title>' . "\n";
        $xml .= '    <link>' . self::escape(BlogUrls::index()) . '</link>' . "\n";
        $xml .= '    <description>' . self::escape($channelDescription) . '</description>' . "\n";
        $xml .= '    <language>nl</language>' . "\n";
        // The feed's own address, which is what a reader stores and what
        // tells an aggregator it has not been moved.
        $xml .= '    <atom:link href="' . self::escape(BlogUrls::feed()) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($posts as $post) {
            $xml .= self::item($post);
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function item(array $post): string
    {
        $url = BlogUrls::post((string) ($post['slug'] ?? ''));
        $description = Seo::plainText(BlogContent::excerpt($post, BlogLocalization::defaultLanguage()));

        $item = '    <item>' . "\n";
        $item .= '      <title>' . self::escape(BlogContent::title($post, BlogLocalization::defaultLanguage())) . '</title>' . "\n";
        $item .= '      <link>' . self::escape($url) . '</link>' . "\n";
        // The canonical URL is a permanent, unique identifier for this post,
        // so it is also its guid — isPermaLink is then true by definition.
        $item .= '      <guid isPermaLink="true">' . self::escape($url) . '</guid>' . "\n";

        $pubDate = BlogClock::forRss($post['published_at'] ?? null);
        if ($pubDate !== null) {
            $item .= '      <pubDate>' . self::escape($pubDate) . '</pubDate>' . "\n";
        }

        if ($description !== '') {
            $item .= '      <description>' . self::escape($description) . '</description>' . "\n";
        }

        return $item . '    </item>' . "\n";
    }

    private static function channelTitle(): string
    {
        $siteName = SeoDefaults::siteName();
        $blogTitle = BlogLocalizedSettings::title(BlogLocalizedSettings::defaultLanguage());

        if ($siteName === '' || $siteName === $blogTitle) {
            return $blogTitle;
        }

        return $blogTitle . ' — ' . $siteName;
    }

    private static function channelDescription(): string
    {
        $intro = Seo::plainText(BlogLocalizedSettings::intro(BlogLocalizedSettings::defaultLanguage()));

        if ($intro !== '') {
            return $intro;
        }

        $default = SeoDefaults::description();

        // A channel description is required by the format, so an install
        // that has written neither gets the one thing that is always true.
        return $default !== '' ? $default : self::channelTitle();
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
