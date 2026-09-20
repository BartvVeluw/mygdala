<?php

declare(strict_types=1);

namespace App\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\Routing\LanguageResolver;

/**
 * Builds /sitemap.xml from the database, every request — see sitemap.php and
 * the `^sitemap\.xml$` rewrite in .htaccess.
 *
 * Generated rather than stored, because a stored file is a file somebody has
 * to remember to regenerate. Publishing a product, deactivating one, adding
 * a collection, unpublishing a page: all of it shows up in the next request
 * with nothing to run and nothing to deploy. The site is nowhere near the
 * protocol's 50,000-URL / 50 MB limit (it currently has fewer than 25 URLs),
 * so a sitemap index would be pure ceremony — but every content type is one
 * private collect*() method returning entries, so adding one later, or
 * splitting into an index, is a local change.
 *
 * WHO PUTS WHAT IN. Core collects the one content type it owns: published
 * `pages` rows. Everything else is contributed by an ENABLED module through
 * App\Module\ModuleDefinition::sitemapCollectors() — products and collections
 * by the Shop, the Personalisatie catalogue by Personalisatie, posts by the
 * Blog, project pages by the Portfolio. This class therefore no longer knows
 * what a product or a portfolio item is, and
 * a CMS-only deployment produces a sitemap with no shop URLs in it because
 * there is no code path that could add one.
 *
 * WHAT GOES IN. Only URLs that are genuinely public and indexable, decided by
 * exactly the same rule the page itself applies:
 *
 *   - published, indexable CMS pages — that is the
 *     homepage, /shop.php, /diensten.php, /portfolio.php, /over-mij.php,
 *     /contact.php and every content page the owner has published. Derived
 *     from the `pages` registry, never from a hardcoded list of PHP files,
 *     so a new page appears and a page set back to Concept disappears on its
 *     own. A page served from a MODULE's own template (/shop.php) drops out
 *     while that module is off, because its URL then 404s — see
 *     PageContent::isServedByAnEnabledModule(). A page the owner set to
 *     `noindex` in the page editor drops out for the same reason: the
 *     sitemap and the page's own robots tag are two statements about the
 *     same thing, and App\Service\PageSeo::isIndexable() makes both;
 *   - active products (`products.active = 1`), the shop's own visibility rule;
 *   - published collections (`collections.is_active = 1`), the rule that
 *     decides whether /collecties/<slug> answers 200 or 404;
 *   - Portfolio project pages that are switched on and visible, by
 *     App\Module\PortfolioModule's collector and that module's own rule.
 *
 * WHAT CANNOT GET IN. Nothing here enumerates the filesystem, so /admin/…,
 * /api/…, the login page, /cart.php, /checkout.php, /bestelling-status.php,
 * the Mollie return/webhook endpoints and every other internal
 * route are absent by construction rather than by an exclusion list that
 * could fall out of date. The two `noindex` legal pages (/cookiebeleid.php,
 * /herroeping.php) are likewise not `pages` rows and never appear. A draft
 * page, an inactive product, an unpublished collection and a hidden Portfolio
 * project are all excluded by the queries above — the same conditions that
 * make those URLs 404.
 *
 * CANONICAL URLS. Every <loc> is produced by the content type's OWN
 * canonicalUrl() helper — PageContent::canonicalUrl() here, and each module's
 * own helper inside the collector it contributes — which are the very same
 * calls partials/page-head.php, partials/shop-seo-head.php and
 * portfolio-detail.php use to render <link rel="canonical">. There is
 * therefore no second URL-building implementation that could drift, and a
 * sitemap URL is by construction identical to the canonical tag on the page
 * it points at. All of them resolve against APP_URL (App\Service\AppUrl),
 * never against the request's Host header, so the sitemap is correct whether
 * it is generated on Vimexx or in local Docker.
 *
 * LASTMOD. Only where the application really has a modification timestamp:
 * the row's own `updated_at`. A NULL one simply produces no <lastmod> rather
 * than a made-up value, and the current request time is never used — a
 * sitemap that claims every URL changed the moment it was fetched is worse
 * than one with no lastmod at all. Dates are emitted as a W3C/ISO date
 * (YYYY-MM-DD) rather than a full timestamp: PHP's and MySQL's timezones are
 * configured independently on shared hosting, so an offset derived from one
 * for a timestamp written by the other would be an invented claim of
 * precision. The date is what the application can actually vouch for.
 *
 * No <changefreq> or <priority> is emitted. Google ignores both, and filling
 * them with guessed values would be noise dressed up as information.
 */
class Sitemap
{
    /** The public path, and the file .htaccess rewrites to sitemap.php. */
    public const PATH = 'sitemap.xml';

    public const CONTENT_TYPE = 'application/xml; charset=UTF-8';

    /**
     * Every URL the sitemap should contain, in a stable, readable order:
     * pages first, then whatever the enabled modules contribute, module by
     * module in registration order.
     *
     * A failing lookup for one content type is logged and skipped rather
     * than fataling the whole document — an incomplete sitemap is a far
     * smaller problem than a 500 at /sitemap.xml.
     *
     * @return list<array{loc:string, lastmod:?string, alternates:array<string, string>}>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (self::collectors() as $label => $collector) {
            $entries = array_merge($entries, self::collect($label, $collector));
        }

        return $entries;
    }

    /**
     * Every content type that contributes URLs, in document order: Core's own
     * first, then one per collector each ENABLED module contributes.
     *
     * Core knows about pages, and nothing else. It used to call five
     * hardcoded collectors, two of which reached straight into
     * App\Repository\ProductRepository and CollectionRepository; those now live
     * in App\Module\ShopModule, the Personalisatie catalogue entry in
     * App\Module\PersonalizationModule, and the Portfolio's project pages in
     * App\Module\PortfolioModule. A CMS-only deployment therefore
     * produces a sitemap with no shop URLs in it because there is no code path
     * that could add one — not because a condition happened to be false.
     *
     * @return array<string, callable(): list<array{loc: string, lastmod: ?string}>>
     */
    private static function collectors(): array
    {
        $core = [
            'pages' => static function (): array {
                $entries = [];
                $pages = (new PageRepository())->findAllPublished();

                // Every page's addresses in ONE query. Without it each page
                // asked for its own — measured: the document grew by exactly
                // one query per page (docs/multilingual/ROUTING.md).
                PageLocalization::preload(array_map(static fn (array $page): int => (int) $page['id'], $pages));

                foreach ($pages as $page) {
                    // ONE indexability rule for the whole application. A page
                    // whose own template belongs to a switched-off module
                    // answers 404, and a page marked `noindex` in the CMS
                    // says outright that it does not belong in search
                    // results — listing either in the sitemap would be the
                    // application contradicting its own <meta name="robots">.
                    // App\Service\PageSeo::isIndexable() is the same call
                    // partials/seo-head.php renders that tag from, so the two
                    // cannot drift.
                    if (!PageSeo::isIndexable($page)) {
                        continue;
                    }

                    // One <url> per language version this page really has,
                    // and none at all for a language it has no address in
                    // (docs/multilingual/ROUTING.md).
                    foreach (self::entriesForVersions(PageContent::localizedPaths($page), $page['updated_at'] ?? null) as $entry) {
                        $entries[] = $entry;
                    }
                }

                return $entries;
            },
        ];

        // "+" rather than array_merge(): a module cannot replace a Core
        // collector by reusing its label.
        return $core + ModuleRegistry::collectMap('sitemapCollectors');
    }

    /**
     * The complete sitemap document.
     */
    public static function xml(): string
    {
        return self::toXml(self::entries());
    }

    /**
     * Renders entries as a Sitemap-protocol document.
     *
     * Every value is escaped with htmlspecialchars(..., ENT_XML1) on its way
     * in — a URL is built from database content (a collection slug, a page
     * slug) and must never be concatenated into XML raw, whatever ends up in
     * those columns.
     *
     * @param list<array{loc:string, lastmod:?string}> $entries
     */
    public static function toXml(array $entries): string
    {
        // The xhtml namespace is declared only when something uses it, so a
        // single-language site's sitemap is byte-for-byte what it was before
        // Multilingual 2.0 phase 6.
        $hasAlternates = false;
        foreach ($entries as $entry) {
            if (($entry['alternates'] ?? []) !== []) {
                $hasAlternates = true;
                break;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
        $xml .= $hasAlternates ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '';
        $xml .= '>' . "\n";

        foreach ($entries as $entry) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . self::escape($entry['loc']) . '</loc>' . "\n";

            if ($entry['lastmod'] !== null) {
                $xml .= '    <lastmod>' . self::escape($entry['lastmod']) . '</lastmod>' . "\n";
            }

            // Every version of this thing, on EVERY version's <url>, plus
            // x-default pointing at the default language's — the reciprocity
            // is the signal, and a one-sided alternate is worse than none.
            $alternates = $entry['alternates'] ?? [];
            foreach ($alternates as $code => $href) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="' . self::escape((string) $code)
                    . '" href="' . self::escape((string) $href) . '"/>' . "\n";
            }

            $default = $alternates[LanguageResolver::defaultLanguage()] ?? null;
            if ($default !== null) {
                $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="'
                    . self::escape((string) $default) . '"/>' . "\n";
            }

            $xml .= '  </url>' . "\n";
        }

        return $xml . '</urlset>' . "\n";
    }

    /**
     * One sitemap entry, with the row's own `updated_at` turned into a
     * publishable date. Public because a module's collector builds entries in
     * exactly this shape and must not reimplement lastmod() — see
     * App\Module\ShopModule.
     *
     * @return array{loc:string, lastmod:?string}
     */
    public static function entryFor(string $loc, mixed $updatedAt, array $alternates = []): array
    {
        return [
            'loc' => $loc,
            'lastmod' => self::lastmod($updatedAt),
            // Every language version of this one thing, code => absolute URL
            // (Multilingual 2.0 phase 6). Empty means "this URL has one
            // version", which is what every entry meant before phase 6 and
            // what a single-language site still means.
            'alternates' => $alternates,
        ];
    }

    /**
     * One entry per language version of one thing, each carrying the full set
     * of alternates (docs/multilingual/ROUTING.md).
     *
     * The Sitemap protocol wants every version listed as its own <url>, with
     * the same <xhtml:link> block on each — that reciprocity is the whole
     * signal. A collector hands over the site-relative paths it has already
     * decided really exist, and gets back the <url> entries for them.
     *
     * Fewer than two versions produces one plain entry with no alternates:
     * an hreflang block naming a single URL says nothing.
     *
     * @param array<string, string> $pathsByLanguage language code => site-relative path
     * @return list<array{loc: string, lastmod: ?string, alternates: array<string, string>}>
     */
    public static function entriesForVersions(array $pathsByLanguage, mixed $updatedAt): array
    {
        $absolute = [];
        foreach ($pathsByLanguage as $code => $path) {
            $absolute[(string) $code] = AppUrl::canonical((string) $path);
        }

        if ($absolute === []) {
            return [];
        }

        if (count($absolute) === 1) {
            return [self::entryFor((string) reset($absolute), $updatedAt)];
        }

        $entries = [];
        foreach ($absolute as $loc) {
            $entries[] = self::entryFor($loc, $updatedAt, $absolute);
        }

        return $entries;
    }

    /**
     * A stored `updated_at` as a W3C date, or null when there is nothing
     * trustworthy to publish (NULL column, empty string, MySQL's zero date,
     * or anything that does not parse). Never falls back to "now".
     */
    private static function lastmod(mixed $updatedAt): ?string
    {
        $value = trim((string) ($updatedAt ?? ''));

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param callable(): list<array{loc:string, lastmod:?string}> $collector
     * @return list<array{loc:string, lastmod:?string}>
     */
    private static function collect(string $label, callable $collector): array
    {
        try {
            return $collector();
        } catch (\Throwable $e) {
            error_log('[Sitemap] could not collect ' . $label . ': ' . $e->getMessage());

            return [];
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
