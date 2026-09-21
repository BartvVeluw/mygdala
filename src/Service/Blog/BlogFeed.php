<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogPostRepository;
use App\Service\Routing\RequestLanguage;
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
 * ONE LANGUAGE PER FEED: the request's. Every language has its own feed at its
 * own address — /blog/feed.xml for the default language, /en/blog/feed.xml
 * behind a prefix (docs/multilingual/ROUTING.md) — and each one is written
 * entirely in that language: the channel title and description, every item's
 * title and description, `<language>`, and the links. A feed that said
 * `<language>en</language>` over Dutch words would be exactly the mixed
 * signal the per-language URLs exist to remove.
 *
 * The words fall back the way they do on the page itself, and nowhere else:
 * BlogContent::title()/excerpt() and BlogLocalizedSettings::title()/intro()
 * give the requested language, then the default language, then '' — or the
 * code default where the Blog has one ("Blog" for its title). This class
 * adds no fallback of its own. It carries no category or tag names, so there
 * are no taxonomy words to localize either.
 *
 * WHICH POSTS: the same ones in every language — the listing's own rule, as
 * /en/blog shows them. A post without an address in the feed's language
 * links its default-language URL, which is what every other internal link to
 * it does (BlogContent::postUrl()).
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
        // Resolved once, so the channel, every item and <language> can never
        // disagree about which language this document is in.
        $language = RequestLanguage::current();
        $channelTitle = self::channelTitle($language);
        $channelDescription = self::channelDescription($language);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <channel>' . "\n";
        $xml .= '    <title>' . self::escape($channelTitle) . '</title>' . "\n";
        $xml .= '    <link>' . self::escape(BlogUrls::index()) . '</link>' . "\n";
        $xml .= '    <description>' . self::escape($channelDescription) . '</description>' . "\n";
        // The language this feed is published in: the request's, which is
        // the default language for /blog/feed.xml and the prefixed one for
        // /en/blog/feed.xml.
        $xml .= '    <language>' . self::escape($language) . '</language>' . "\n";
        // The feed's own address, which is what a reader stores and what
        // tells an aggregator it has not been moved.
        $xml .= '    <atom:link href="' . self::escape(BlogUrls::feed()) . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($posts as $post) {
            $xml .= self::item($post, $language);
        }

        $xml .= '  </channel>' . "\n";
        $xml .= '</rss>' . "\n";

        return $xml;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function item(array $post, string $language): string
    {
        // An item's link is the post's address IN THE FEED'S OWN LANGUAGE: a
        // Dutch feed links Dutch URLs and /en/blog/feed.xml links English
        // ones, both resolved exactly as the page itself resolves them.
        $url = BlogContent::postCanonical($post);
        $description = Seo::plainText(BlogContent::excerpt($post, $language));

        $item = '    <item>' . "\n";
        $item .= '      <title>' . self::escape(BlogContent::title($post, $language)) . '</title>' . "\n";
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

    private static function channelTitle(string $language): string
    {
        $siteName = SeoDefaults::siteName();
        $blogTitle = BlogLocalizedSettings::title($language);

        if ($siteName === '' || $siteName === $blogTitle) {
            return $blogTitle;
        }

        return $blogTitle . ' — ' . $siteName;
    }

    private static function channelDescription(string $language): string
    {
        $intro = Seo::plainText(BlogLocalizedSettings::intro($language));

        if ($intro !== '') {
            return $intro;
        }

        // The site-wide default description is one value for every language
        // (App\Service\SeoDefaults), exactly as every page's <head> uses it.
        $default = SeoDefaults::description();

        // A channel description is required by the format, so an install
        // that has written neither gets the one thing that is always true.
        return $default !== '' ? $default : self::channelTitle($language);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
