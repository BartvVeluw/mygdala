<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Service\Language\SiteLanguages;

/**
 * WHICH LANGUAGE VERSIONS OF THIS PAGE ACTUALLY EXIST, and at which URL
 * (docs/multilingual/ROUTING.md).
 *
 * One page can be routable in some languages and not in others — an editor
 * wrote a German title and slug for this page but not for that one — and the
 * difference matters twice over:
 *
 *   - the public language switch may only offer a language whose version
 *     really answers, and must show the others as unavailable rather than
 *     link to a 404 or, worse, to a fallback that looks translated;
 *   - `hreflang` may only name URLs that exist. An alternate pointing at a
 *     page that falls back to another language's words is exactly the signal
 *     search engines are told not to trust.
 *
 * SO THERE ARE TWO STATES, and keeping them apart is the whole job:
 *
 *   DECLARED   the route that is rendering said, per language, where its
 *              version lives (declareVersions()). This is authoritative: a language
 *              that is absent from that list has NO version, and
 *              `hreflang` is rendered from it.
 *   ASSUMED    nothing declared anything, so all this class can do is offer
 *              the same path under each language's prefix. Good enough for a
 *              switch on a route whose URL does not change per language (the
 *              cart, the checkout, a `.php` template) — and deliberately NOT
 *              good enough for `hreflang`, which is therefore not rendered at
 *              all until a route declares.
 *
 *              The PATH ONLY: the query string is never copied, because it
 *              may carry tracking, a form status or anything else a visitor
 *              was sent with. A route whose identity lives in the query
 *              (product.php?id=…) must therefore declare, building each
 *              version from its own validated parameter.
 *
 * A route declares once, before it prints its <head>. Nothing polls, nothing
 * scans, and no language version is ever inferred from content that merely
 * fell back.
 */
final class LanguageAlternates
{
    /** @var array<string, string>|null language code => site-relative path, prefix included */
    private static ?array $declared = null;

    /**
     * Say where this page lives in each language. Only languages that really
     * have a routable version belong here; the current language included.
     *
     * Paths are site-relative and already carry their prefix — build them
     * with App\Service\Routing\LocalizedUrl::path(), never by hand.
     *
     * @param array<string, string> $pathsByLanguage
     */
    public static function declareVersions(array $pathsByLanguage): void
    {
        $active = SiteLanguages::activeCodes();
        $declared = [];

        foreach ($pathsByLanguage as $code => $path) {
            $code = (string) $code;
            $path = trim((string) $path);

            if ($path !== '' && in_array($code, $active, true)) {
                $declared[$code] = $path;
            }
        }

        self::$declared = $declared;
    }

    /** Has the rendering route said which versions exist? */
    public static function isDeclared(): bool
    {
        return self::$declared !== null;
    }

    /**
     * The language versions that exist, code => site-relative path.
     *
     * Declared when a route declared, assumed otherwise. Always in the site's
     * own language order, and never containing an inactive language.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $paths = self::$declared ?? self::assumed();
        $ordered = [];

        foreach (SiteLanguages::activeCodes() as $code) {
            if (isset($paths[$code])) {
                $ordered[$code] = $paths[$code];
            }
        }

        return $ordered;
    }

    /**
     * The versions that may be advertised to a crawler: the declared ones, and
     * nothing at all when nothing was declared.
     *
     * This is what keeps `hreflang` honest without every route having to
     * remember to suppress it: a route that has not thought about its
     * language versions simply does not advertise any, which is the state
     * every page of this project was in before phase 6.
     *
     * @return array<string, string>
     */
    public static function forHreflang(): array
    {
        return self::$declared === null ? [] : self::all();
    }

    /** Forget what this request declared. Tests, and nothing else. */
    public static function reset(): void
    {
        self::$declared = null;
    }

    /**
     * The same path under every active language's prefix.
     *
     * @return array<string, string>
     */
    private static function assumed(): array
    {
        [$bare] = LocalizedUrl::strip(
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)
        );

        $paths = [];
        foreach (SiteLanguages::activeCodes() as $code) {
            $paths[$code] = LocalizedUrl::path($bare, $code);
        }

        return $paths;
    }
}
