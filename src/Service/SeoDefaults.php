<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The site-wide SEO defaults: the values every page falls back to when it
 * has nothing of its own. The top of the hierarchy described in SEO.md:
 *
 *     global defaults  ->  content-type defaults  ->  per-item overrides
 *
 * Deliberately SMALL, and deliberately built out of settings that already
 * exist wherever one already exists. Three of the four values below are not
 * new fields at all:
 *
 *   title suffix   `site_name` — the Site Setting every title convention in
 *                  this project already ends with. A separate
 *                  `seo_title_suffix` would be a second name for the site,
 *                  free to drift from the first.
 *   social image   `og_image_path` — the "Standaard deel-afbeelding" the
 *                  Vormgeving screen has owned since Theme & Branding V1
 *                  (App\Service\Branding::socialImagePath()). A second
 *                  default-image setting would mean two answers to one
 *                  question.
 *   description    `seo_default_description` — NEW, and empty by default.
 *                  A site that has not written one gets no
 *                  <meta name="description"> rather than an invented
 *                  sentence; an empty or generic description is worse than
 *                  none.
 *   robots         `seo_robots_index_default` — NEW, '1' (index) by default.
 *                  Public content is indexable unless something says
 *                  otherwise, and a missing/unreadable setting can therefore
 *                  never take a live site out of the index.
 *
 * There is no keyword field, no arbitrary custom-meta mechanism, and no
 * per-page Open Graph title/description: OG copy IS the effective title and
 * description (see App\Service\SeoMetadata), so the two cannot drift.
 */
class SeoDefaults
{
    /** The two robots values this application will ever emit. */
    public const ROBOTS_INDEX = 'index,follow';
    public const ROBOTS_NOINDEX = 'noindex,follow';

    /**
     * The site's own name — the suffix every automatic title ends with, and
     * the fallback title for a page that has nothing else.
     */
    public static function siteName(): string
    {
        return trim(SiteSettings::get('site_name'));
    }

    /**
     * The global meta description, or '' when this install has not written
     * one. '' means "emit no description tag at all".
     */
    public static function description(): string
    {
        return trim(SiteSettings::get('seo_default_description'));
    }

    /**
     * The site-wide social sharing image as an absolute URL, or null when
     * this install has none — in which case no og:image is rendered rather
     * than an empty one.
     */
    public static function socialImageUrl(): ?string
    {
        return Seo::absoluteImageUrl(Branding::socialImagePath());
    }

    /**
     * The default robots directive for ordinary public content. Anything but
     * an explicit off value means "index", so a typo in the setting can never
     * silently deindex the site.
     */
    public static function robots(): string
    {
        $value = strtolower(trim(SiteSettings::get('seo_robots_index_default')));

        $off = in_array($value, ['0', 'false', 'off', 'no', 'noindex'], true);

        return $off ? self::ROBOTS_NOINDEX : self::ROBOTS_INDEX;
    }

    /** Is this install configured to let search engines index public pages? */
    public static function indexesByDefault(): bool
    {
        return self::robots() === self::ROBOTS_INDEX;
    }
}
