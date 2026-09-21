<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The small set of SEO primitives shared by every content type that has
 * editable SEO fields — CMS pages (App\Service\PageContent), shop products
 * (App\Service\ProductSeo) and shop collections
 * (App\Service\CollectionContent).
 *
 * Deliberately NOT a "SEO framework": it owns no page-specific knowledge and
 * decides nothing about which title or description a given page gets. It only
 * holds the four things that would otherwise be re-implemented (slightly
 * differently) in each of those classes:
 *
 *   - the field limits, which are the database column widths;
 *   - the site-title convention for shop pages;
 *   - "turn sanitized rich-text HTML into a clean plain-text sentence";
 *   - "turn a stored image path into an absolute URL, with the global
 *     og_image_path Site Setting as the last resort".
 *
 * Canonical URLs are NOT here: those are built with App\Service\AppUrl by
 * each content type's own canonicalUrl() method, so there stays exactly one
 * place per content type that knows its public route.
 */
class Seo
{
    /**
     * Matched to the `meta_title` / `meta_description` column widths on
     * `pages`, `products` and `collections` — see
     * db/migrations/20260908180000_add_seo_fields_to_products_and_collections.php.
     * App\Service\PageService carries the same two numbers for the page
     * editor, which predates this class.
     */
    public const MAX_META_TITLE_LENGTH = 255;
    public const MAX_META_DESCRIPTION_LENGTH = 500;

    /**
     * How long an AUTOMATICALLY DERIVED meta description may get before it is
     * cut on a word boundary. This never applies to text the administrator
     * typed: a custom meta description is rendered exactly as entered (the
     * database column is the only limit), because silently rewriting somebody's
     * SEO copy is worse than a long tag.
     */
    public const FALLBACK_DESCRIPTION_LENGTH = 160;

    /**
     * The shop's page-title convention: "<name> | Shop — <site name>". This
     * is the wording product.php and collectie.php already rendered before
     * either had editable SEO fields, so turning the fields on cannot change
     * a single existing title.
     */
    public static function shopTitle(string $name): string
    {
        $siteName = SiteSettings::get('site_name');
        $name = trim($name);

        if ($name === '') {
            return $siteName;
        }

        return $name . ' | Shop — ' . $siteName;
    }

    /**
     * The title convention for a fixed application route that has no CMS row
     * behind it — the cart, the checkout, the order status page, the cookie
     * policy, the withdrawal form: "<name> | <site name>".
     *
     * Exactly the wording those pages already rendered, in one place instead
     * of six, so the site name is never concatenated into a template again.
     * Two guards the hand-written versions did not have: a name that IS the
     * site name is not repeated after it, and an install with no site name
     * yet gets the bare name rather than a title ending in a stray pipe.
     */
    public static function routeTitle(string $name): string
    {
        $siteName = trim(SiteSettings::get('site_name'));
        $name = trim($name);

        if ($name === '' || $name === $siteName) {
            return $siteName !== '' ? $siteName : $name;
        }

        return $siteName === '' ? $name : $name . ' | ' . $siteName;
    }

    /**
     * Sanitized rich-text HTML as plain text fit for a <meta> attribute or a
     * JSON-LD string: tags removed, HTML entities decoded back to the real
     * characters, and all whitespace (including the newlines Quill leaves
     * between block elements) collapsed to single spaces.
     *
     * Decoding matters: descriptions are stored as sanitized HTML, so an
     * ampersand is stored as "&amp;". Without decoding it here, the caller's
     * own htmlspecialchars() would render "&amp;amp;" in the tag — and a
     * JSON-LD string would carry the raw entity into structured data that is
     * supposed to match the visible text.
     */
    public static function plainText(?string $html): string
    {
        // strip_tags() alone would run consecutive blocks together
        // ("…Vierdaagse.De onderzetters…"), so every block boundary and <br>
        // becomes a space first. Inline tags (<strong>, <em>, <a>) are left
        // to strip_tags() untouched, so a bold word inside a sentence is not
        // split in half.
        $html = preg_replace(
            '#<(?:br\s*/?|/p|/div|/li|/ul|/ol|/h[1-6]|/blockquote|/tr|/td|/th)\s*>#i',
            ' ',
            (string) $html
        ) ?? (string) $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces survive the decode as U+00A0 and would show up
        // as odd characters in a search result.
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * A short plain-text teaser from rich-text HTML — the automatic fallback
     * used when no meta description was typed. Cut on a word boundary and
     * closed with an ellipsis, never mid-word.
     */
    public static function excerpt(?string $html, int $maxLength = self::FALLBACK_DESCRIPTION_LENGTH): string
    {
        $text = self::plainText($html);

        if ($text === '' || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B.,;:") . '…';
    }

    /**
     * Absolute URL for a stored image path, or null for an empty one. An
     * already-absolute http(s) URL is returned untouched; everything else is
     * resolved against App\Service\AppUrl — the same base URL canonical tags
     * and og:url use, never the request's Host header.
     */
    public static function absoluteImageUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return AppUrl::asset(ltrim($path, '/'));
    }

    /**
     * The site-wide social sharing image path (the `og_image_path` Site
     * Setting) — the last link in every social-image fallback chain, and
     * already what partials/og-meta.php renders when a page supplies no
     * image of its own.
     */
    public static function defaultSocialImagePath(): string
    {
        return SiteSettings::get('og_image_path');
    }
}
