<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

/**
 * The language a visitor last read the site in, remembered in one small
 * first-party cookie (docs/multilingual/ROUTING.md).
 *
 * WHAT IS IN IT: a website language code, and nothing else. No identifier, no
 * session handle, no timestamp, nothing that could name a person. A value
 * that is not an ACTIVE language of this site is read as "no preference", so
 * a stale cookie from a language that was switched off cannot decide
 * anything, and a hand-written cookie cannot name a language the site does
 * not publish.
 *
 * WHAT IT MAY DECIDE: only step 2 of App\Service\Routing\LanguageResolver's
 * chain, and step 2 runs exclusively when the URL itself named no language.
 * A URL with a language prefix always wins — a link somebody sends you must
 * open in the language it was written in, whatever your last visit was.
 *
 * WHEN IT IS WRITTEN: by the dispatcher and by the public entrypoints
 * (partials/public-request.php), once a request has actually resolved to a
 * page, for the language that page is being rendered in. So it follows what
 * a visitor reads rather than what they clicked, which is what makes the
 * language switch work in both directions without a redirect endpoint of its
 * own, without a return URL, and therefore without an open-redirect surface
 * anywhere near it.
 *
 * It is only ever written when the value would actually change, so an
 * ordinary page view sends no Set-Cookie header at all.
 */
final class LanguagePreference
{
    /**
     * Deliberately generic: this CMS is installed under many names, and a
     * cookie name is visible to anybody who opens their browser's inspector.
     */
    public const COOKIE_NAME = 'site_language';

    /** A year. Long enough to be a preference, short enough to expire. */
    private const LIFETIME_SECONDS = 31536000;

    /** Set by remember(); test seam, see overrideForTests(). */
    private static ?string $overridden = null;
    private static bool $overrideActive = false;

    /**
     * The stored preference, or null when there is none, it is unreadable, or
     * it names a language this site does not currently publish.
     */
    public static function stored(): ?string
    {
        if (self::$overrideActive) {
            return self::$overridden;
        }

        $raw = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($raw)) {
            return null;
        }

        $code = LanguageCode::normalise($raw);

        return ($code !== null && SiteLanguages::isActive($code)) ? $code : null;
    }

    /**
     * Remember this language, unless it is already what is remembered.
     *
     * Silently does nothing for a language this site does not publish, and
     * for a request whose headers have already gone out — a preference is a
     * convenience, and failing to store one must never interrupt a page.
     */
    public static function remember(string $code): void
    {
        $normalised = LanguageCode::normalise($code);

        if ($normalised === null || !SiteLanguages::isActive($normalised)) {
            return;
        }

        if (self::$overrideActive) {
            self::$overridden = $normalised;

            return;
        }

        if (($_COOKIE[self::COOKIE_NAME] ?? null) === $normalised) {
            return;
        }

        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $normalised, self::cookieOptions(time() + self::LIFETIME_SECONDS));

        // So anything later in this same request reads what was just decided
        // rather than what the browser sent.
        $_COOKIE[self::COOKIE_NAME] = $normalised;
    }

    /**
     * The cookie attributes, in one place so the two writers cannot disagree.
     *
     * `secure` follows the request, exactly as the public form session's
     * cookie does (App\Service\Forms\PublicFormSession): this CMS is
     * developed over plain HTTP in Docker and deployed over HTTPS, and a
     * cookie pinned to `secure` would simply never be stored locally.
     *
     * `httponly` because no script reads this: the server decides the
     * language and prints the page in it.
     *
     * @return array<string, mixed>
     */
    private static function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * Test seam: pretend this is (or is not) the stored preference, without a
     * browser. Pass null for "no preference", and call
     * overrideForTests(null, false) in tearDown() to go back to reading the
     * real cookie.
     */
    public static function overrideForTests(?string $code, bool $active = true): void
    {
        self::$overridden = $code;
        self::$overrideActive = $active;
    }
}
