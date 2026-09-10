<?php

namespace App\Service\Analytics;

/**
 * Reduces a user agent to one of three words — 'mobile', 'tablet' or
 * 'desktop' — which is the only thing about the browser that is ever stored.
 *
 * This is the whole point of the class: the raw user agent is a rich
 * fingerprinting surface (exact OS build, device model, browser patch
 * version), so it is used for two decisions inside a single request — is this
 * a bot, and which of these three buckets — and then dropped. What lands in
 * the database is one of three constants.
 *
 * Accuracy is deliberately modest. "Is this a phone" is answered by the
 * conventions browser vendors have kept stable for a decade, not by a device
 * database that would need updating forever:
 *
 *  - Android says "Mobile" in the token list on a phone and omits it on a
 *    tablet — that omission IS the official signal, so tablet is checked
 *    before mobile.
 *  - iPadOS 13+ deliberately claims to be a Mac ("Macintosh; Intel Mac OS X")
 *    to get desktop websites. Nothing in a user agent can undo that, so an
 *    iPad reads as desktop here. Accepted: this number exists to answer
 *    "should I care about the phone layout", and it answers that correctly.
 *  - Anything unrecognised falls back to 'desktop' rather than to an
 *    'unknown' bucket, so the three numbers always add up to the pageview
 *    total and the dashboard never has to explain a fourth category.
 */
final class DeviceType
{
    public const DESKTOP = 'desktop';
    public const TABLET = 'tablet';
    public const MOBILE = 'mobile';

    public static function fromUserAgent(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        if ($ua === '') {
            return self::DESKTOP;
        }

        // Tablets first: an Android tablet's user agent contains "android"
        // but not "mobile", and matching mobile first would misfile every
        // iPad-era Android tablet.
        if (
            str_contains($ua, 'ipad')
            || str_contains($ua, 'tablet')
            || str_contains($ua, 'kindle')
            || str_contains($ua, 'silk/')
            || str_contains($ua, 'playbook')
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))
        ) {
            return self::TABLET;
        }

        if (
            str_contains($ua, 'mobile')
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod')
            || str_contains($ua, 'android')
            || str_contains($ua, 'windows phone')
            || str_contains($ua, 'blackberry')
            || str_contains($ua, 'opera mini')
        ) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
