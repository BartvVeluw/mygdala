<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The /robots.txt document, generated per request — see robots.php and the
 * `^robots\.txt$` rewrite in .htaccess.
 *
 * There used to be a static robots.txt in the project root with the
 * production domain typed into it:
 *
 *     Sitemap: https://www.<production-domain>/sitemap.xml
 *
 * That is one line, and it is also the reason a second deployment of this
 * codebase would have advertised somebody else's sitemap. The URL now comes
 * from App\Service\AppUrl, exactly like every canonical tag, every og:url
 * and every <loc> in the sitemap itself — one configured base URL, one
 * answer.
 *
 * WHAT IT SAYS, and why it is this short:
 *
 *   Allow: /            the directive the previous file had; the public site
 *                       is meant to be crawled.
 *   Disallow: /admin/   the CMS. It renders no public SEO head at all and
 *   Disallow: /api/     every screen is behind a login, but there is no
 *                       reason for a crawler to spend requests on either.
 *   Sitemap:            the generated sitemap, at the configured base URL.
 *
 * The cart, the checkout and the order status page are deliberately NOT
 * disallowed. They are `noindex,follow` in their own <head>
 * (App\Service\SeoMetadata), and a crawler has to be allowed to FETCH a page
 * to see that it may not INDEX it — disallowing them would leave a URL that
 * can still surface in results with no snippet, which is the outcome the
 * noindex exists to prevent. Blocking crawling and refusing indexing are
 * different things, and this project uses each for what it is for.
 *
 * NON-PRODUCTION. A deployment that says so in `APP_ENV` serves
 * `Disallow: /` instead: a staging or test copy of the site must not compete
 * with the real one in the index. The default is production
 * (App\Service\AppEnvironment), so a missing or misspelt value can never
 * take the live site out of the index — the failure mode of the safe
 * default is "a test server was crawled", not "the shop disappeared from
 * Google".
 */
class Robots
{
    /** The public path, and the file .htaccess rewrites to robots.php. */
    public const PATH = 'robots.txt';

    public const CONTENT_TYPE = 'text/plain; charset=UTF-8';

    /**
     * Paths no crawler needs to fetch. Not an indexability rule — see the
     * class docblock on why the transactional shop routes are absent.
     *
     * @var list<string>
     */
    private const DISALLOWED = ['/admin/', '/api/'];

    /** The complete document, ending in a newline. */
    public static function txt(): string
    {
        return AppEnvironment::isProduction()
            ? self::productionTxt()
            : self::nonProductionTxt();
    }

    private static function productionTxt(): string
    {
        $lines = ['User-agent: *', 'Allow: /'];

        foreach (self::DISALLOWED as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: ' . AppUrl::canonical(Sitemap::PATH);

        return implode("\n", $lines) . "\n";
    }

    /**
     * A non-production copy asks not to be crawled at all, and advertises no
     * sitemap: pointing a crawler at a list of staging URLs is the opposite
     * of what the Disallow is for.
     */
    private static function nonProductionTxt(): string
    {
        return implode("\n", [
            '# Non-production deployment (APP_ENV=' . AppEnvironment::name() . ').',
            'User-agent: *',
            'Disallow: /',
        ]) . "\n";
    }
}
