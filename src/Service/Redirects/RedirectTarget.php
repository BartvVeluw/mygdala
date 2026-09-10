<?php

declare(strict_types=1);

namespace App\Service\Redirects;

use App\Module\ModuleRegistry;
use App\Service\AppUrl;

/**
 * What a redirect may point AT, and what that becomes in a Location header.
 *
 * Two kinds, and the difference between them is not decoration — they are
 * validated differently, they can become unavailable for different reasons,
 * and only one of them leaves this website:
 *
 *   internal  a site-relative path, optionally with its own query string:
 *             /services-new, /shop.php, /contact.php?onderwerp=offerte. Made
 *             absolute against APP_URL (App\Service\AppUrl) at redirect time,
 *             exactly like every canonical tag and sitemap entry on this site,
 *             so a redirect can never be pointed somewhere else by a forged
 *             Host header.
 *   external  an absolute http(s) URL. Only ever created by an authenticated
 *             editor who typed it; no destination is ever read from public
 *             request input, which is what keeps this table from becoming an
 *             open redirect.
 *
 * THE ONE THING A TARGET CAN LOSE. An internal target may name a route owned
 * by a module that is currently switched off (/shop.php with the Shop
 * disabled). That URL answers 404, so executing the redirect would move the
 * visitor from one 404 to another and lose the original URL on the way. The
 * row is kept exactly as stored — disabling a module is not an uninstall
 * (MODULES.md) — the redirect simply does not fire while the module is off,
 * and the admin says so. This mirrors what App\Service\LinkResolver already
 * does with a header button or a menu item pointing at a disabled module.
 */
final class RedirectTarget
{
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';

    /** @var list<string> */
    public const TYPES = [self::TYPE_INTERNAL, self::TYPE_EXTERNAL];

    /** Matches `redirects.target_value`'s column width. */
    public const MAX_LENGTH = 2048;

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        self::TYPE_INTERNAL => 'Pad op deze site',
        self::TYPE_EXTERNAL => 'Externe URL',
    ];

    public static function isValidType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * Normalizes a target of the given type, or returns null when it is not
     * usable. Both branches are strict: an unusable destination must be
     * refused at save time, because a redirect is only discovered to be broken
     * by the visitor it broke.
     */
    public static function normalize(string $type, string $raw): ?string
    {
        return match ($type) {
            self::TYPE_INTERNAL => self::normalizeInternal($raw),
            self::TYPE_EXTERNAL => self::normalizeExternal($raw),
            default => null,
        };
    }

    /**
     * A site-relative destination: the same normalized path a source uses,
     * plus — optionally — the query string the editor deliberately typed.
     * The fragment is dropped: it never reaches the server, so storing one
     * would only be a value nothing can act on.
     */
    public static function normalizeInternal(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '' || str_contains($value, '://') || str_starts_with($value, '//')) {
            return null;
        }

        $value = (string) preg_replace('/#.*$/s', '', $value);

        $questionMark = strpos($value, '?');
        $pathPart = $questionMark === false ? $value : substr($value, 0, $questionMark);
        $query = $questionMark === false ? '' : substr($value, $questionMark + 1);

        $path = RedirectPath::normalize($pathPart);
        if ($path === null) {
            return null;
        }

        if ($query === '') {
            return $path;
        }

        // A query string is kept verbatim (it is the editor's, not a
        // visitor's), but it may not smuggle whitespace or a control
        // character into a Location header.
        if (preg_match('/[\x00-\x20\x7F]/', $query) === 1) {
            return null;
        }

        $withQuery = $path . '?' . $query;

        return strlen($withQuery) > self::MAX_LENGTH ? null : $withQuery;
    }

    /**
     * An absolute destination on another site. Rejected: anything that is not
     * http(s) — which is how javascript:, data: and mailto: are refused as a
     * class rather than by blocklist — a URL carrying credentials, and a URL
     * with no host.
     */
    public static function normalizeExternal(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (trim((string) ($parts['host'] ?? '')) === '') {
            return null;
        }

        // user:password@host is how a phishing URL is dressed up to look like
        // a familiar domain, and no legitimate redirect destination needs it.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    /**
     * The module that owns this target's route and is currently switched off,
     * or null when the target is fine to execute. External targets and Core
     * paths always answer null — this only knows about a module's own fixed
     * routes, via App\Module\ModuleRegistry, so Core still does not know what
     * a shop is.
     */
    public static function disabledModuleFor(string $type, string $value): ?string
    {
        if ($type !== self::TYPE_INTERNAL) {
            return null;
        }

        $path = RedirectPath::normalize(explode('?', $value, 2)[0]);

        if ($path === null || $path === '/') {
            return null;
        }

        return ModuleRegistry::disabledModuleForRoutePath($path);
    }

    /**
     * The absolute URL to put in the Location header. Internal targets resolve
     * against APP_URL and never against the request's Host header — the same
     * rule SEO.md states for every canonical and og:url on this site, so a
     * redirect lands on exactly the URL the destination page calls its own.
     */
    public static function absoluteUrl(string $type, string $value): string
    {
        if ($type === self::TYPE_EXTERNAL) {
            return $value;
        }

        return AppUrl::canonical($value);
    }
}
