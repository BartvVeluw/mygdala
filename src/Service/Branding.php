<?php

namespace App\Service;

use App\Service\Media\MediaItem;
use App\Service\Media\MediaService;

/**
 * The site's visual identity as the templates need it: the logo, the
 * alternate logo, the favicon and the default social-sharing image.
 *
 * The VALUES live in App\Service\SiteSettings, where they always have — this
 * class does not store anything. What it owns are the small decisions that
 * were previously copied into every template, or not made at all:
 *
 *   - a path is normalised to one root-relative form, so a stored value with
 *     or without a leading slash renders the same;
 *   - the alternate logo falls back to the primary one, so "not set" is a
 *     complete answer rather than an empty <img>;
 *   - the favicon's type is read from the file it points at instead of the
 *     hardcoded type="image/png" that sixteen templates carried, which was
 *     wrong the moment anybody uploaded an .ico or an .svg.
 *
 * ## Media Library, and why the paths are still here
 *
 * Since Media Library V1 each of the four assets has TWO stored values: a
 * `*_media_id` naming an item in the library, and the original `*_path`.
 * This class is the single place that precedence is written down:
 *
 *     media id set and the item exists  ->  the media item's path
 *     otherwise                         ->  the stored path
 *
 * Both, rather than a migration that rewrites the paths away, because that
 * is what lets the library arrive without a breaking change — and because
 * the fallback is genuinely load-bearing: a media id pointing at a row that
 * has since disappeared must not blank out a site's logo. The legacy keys
 * are retired later, deliberately, not as a side effect. MEDIA.md tracks it.
 *
 * The library never took over the SVG case. This site's own logo is an SVG,
 * the uploaders refuse to accept one (it can carry script), and the adoption
 * migration nonetheless gave it a media row — so it has central alt text and
 * a usage count like everything else, while staying a file only a deploy can
 * replace. That is deliberate, and it is why nothing here asks what format
 * an asset is.
 *
 * Appearance settings (colours, fonts, button shape) are NOT here: those are
 * App\Service\Theme\ThemeSettings. Identity and appearance stay apart.
 */
final class Branding
{
    /**
     * File extension to the MIME type a <link rel="icon"> should advertise.
     * Anything not in this list gets no type attribute at all, which is
     * valid and lets the browser sniff, rather than a confident lie.
     *
     * @var array<string, string>
     */
    private const ICON_TYPES = [
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
        'gif' => 'image/gif',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** Which media-id setting backs which legacy path setting. */
    public const MEDIA_KEYS = [
        'logo_path' => 'logo_media_id',
        'logo_alt_path' => 'logo_alt_media_id',
        'favicon_path' => 'favicon_media_id',
        'og_image_path' => 'og_image_media_id',
    ];

    /** The primary logo, root-relative. Empty when no logo is configured. */
    public static function logoPath(): string
    {
        return self::resolve('logo_path');
    }

    /**
     * The alternate logo, root-relative — or the primary logo when none is
     * set. V1 uses it in the footer only; there is deliberately no automatic
     * light/dark switching behind it.
     */
    public static function alternateLogoPath(): string
    {
        $alternate = self::resolve('logo_alt_path');

        return $alternate !== '' ? $alternate : self::logoPath();
    }

    /** Whether an alternate logo was actually chosen, rather than inherited. */
    public static function hasAlternateLogo(): bool
    {
        return self::resolve('logo_alt_path') !== '';
    }

    /** The favicon, root-relative. Empty when none is configured. */
    public static function faviconPath(): string
    {
        return self::resolve('favicon_path');
    }

    /**
     * The MIME type for the configured favicon, or null when the extension
     * is one this project has no confident answer for.
     */
    public static function faviconType(): ?string
    {
        $media = self::mediaFor('favicon_path');

        // A media item knows its real MIME type from the file's own header,
        // which beats guessing from an extension — but only when it is one a
        // <link rel="icon"> may sensibly advertise.
        if ($media !== null && in_array($media->mimeType, self::ICON_TYPES, true)) {
            return $media->mimeType;
        }

        $path = self::faviconPath();

        if ($path === '') {
            return null;
        }

        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

        return self::ICON_TYPES[$extension] ?? null;
    }

    /** The default Open Graph image, root-relative. Empty when none is set. */
    public static function socialImagePath(): string
    {
        return self::resolve('og_image_path');
    }

    /**
     * The media item behind one of the four assets, or null when this
     * install has not picked one (or picked one that has since been
     * removed). The admin screens use it for a preview and a name; the
     * public site only ever needs the path.
     */
    public static function mediaFor(string $pathKey): ?MediaItem
    {
        $mediaKey = self::MEDIA_KEYS[$pathKey] ?? null;

        if ($mediaKey === null) {
            return null;
        }

        return MediaService::find((int) SiteSettings::get($mediaKey));
    }

    /**
     * The media item if there is one, otherwise the legacy path — the whole
     * transitional rule, in one place.
     */
    private static function resolve(string $pathKey): string
    {
        $media = self::mediaFor($pathKey);

        if ($media !== null) {
            return $media->publicPath();
        }

        return self::normalise(SiteSettings::get($pathKey));
    }

    /**
     * One root-relative form for a stored path: exactly one leading slash,
     * and an empty string for "nothing configured". An absolute URL is
     * returned untouched, so a site serving its logo from a CDN still works.
     */
    private static function normalise(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return '/' . ltrim($path, '/');
    }
}
