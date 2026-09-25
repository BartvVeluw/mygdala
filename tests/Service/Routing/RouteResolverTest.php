<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\RouteResolver;
use App\Service\Routing\RouteSegments;
use App\Service\Routing\RouteTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The route table and its matcher (docs/multilingual/ROUTING.md).
 *
 * The contract being defended is COMPATIBILITY: every URL shape the seven
 * `RewriteRule`s in `.htaccess` used to serve must resolve to exactly the
 * template it used to reach, with exactly the parameters Apache's [QSA] used
 * to append — and every URL shape Apache REFUSED must still resolve to
 * nothing at all.
 */
final class RouteResolverTest extends TestCase
{
    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    /** @param list<string> $segments */
    private function resolve(array $segments, string $language = 'nl'): ?array
    {
        $match = RouteResolver::resolve($segments, $language);

        return $match === null ? null : [
            'key' => $match->key,
            'template' => $match->template,
            'query' => $match->query,
            'canonical' => $match->canonicalPath,
        ];
    }

    // ------------------------------------------------------------------- Core

    public function testTheSiteRoot(): void
    {
        $match = $this->resolve([]);

        self::assertSame(RouteTable::HOME_ROUTE, $match['key']);
        self::assertSame('index.php', $match['template']);
    }

    public function testAOneWordPathIsACmsPage(): void
    {
        $match = $this->resolve(['over-ons']);

        self::assertSame(RouteTable::PAGE_ROUTE, $match['key']);
        self::assertSame('pagina.php', $match['template']);
        self::assertSame(['slug' => 'over-ons'], $match['query']);
    }

    public function testARootLevelTemplateKeepsItsOwnRoute(): void
    {
        self::assertSame('contact.php', $this->resolve(['contact.php'])['template']);
        self::assertSame('index.php', $this->resolve(['index.php'])['template']);
        self::assertSame('herroeping.php', $this->resolve(['herroeping.php'])['template']);
    }

    // ------------------------------------------------------------------- Blog

    public function testTheFiveBlogRoutesResolveExactlyAsTheirRewritesDid(): void
    {
        self::assertSame(
            ['key' => 'blog.index', 'template' => 'blog.php', 'query' => [], 'canonical' => null],
            $this->resolve(['blog'])
        );

        self::assertSame(
            ['key' => 'blog.feed', 'template' => 'blog-feed.php', 'query' => [], 'canonical' => null],
            $this->resolve(['blog', 'feed.xml'])
        );

        self::assertSame(
            ['key' => 'blog.post', 'template' => 'blog-post.php', 'query' => ['slug' => 'mijn-bericht'], 'canonical' => null],
            $this->resolve(['blog', 'mijn-bericht'])
        );

        self::assertSame(
            ['key' => 'blog.category', 'template' => 'blog.php', 'query' => ['category' => 'hout'], 'canonical' => null],
            $this->resolve(['blog', 'categorie', 'hout'])
        );

        self::assertSame(
            ['key' => 'blog.tag', 'template' => 'blog.php', 'query' => ['tag' => 'eiken'], 'canonical' => null],
            $this->resolve(['blog', 'tag', 'eiken'])
        );
    }

    public function testTheFeedIsMatchedBeforeAPostCouldClaimIt(): void
    {
        self::assertSame('blog-feed.php', $this->resolve(['blog', 'feed.xml'])['template']);
    }

    // --------------------------------------------------------- Shop, Portfolio

    public function testTheCollectionNamespace(): void
    {
        self::assertSame(
            ['key' => 'shop.collection', 'template' => 'collectie.php', 'query' => ['slug' => 'hout'], 'canonical' => null],
            $this->resolve(['collecties', 'hout'])
        );
    }

    public function testTheShopsOwnTemplates(): void
    {
        self::assertSame('shop.php', $this->resolve(['shop.php'])['template']);
        self::assertSame('product.php', $this->resolve(['product.php'])['template']);
        self::assertSame('cart.php', $this->resolve(['cart.php'])['template']);
        self::assertSame('checkout.php', $this->resolve(['checkout.php'])['template']);
        self::assertSame('bestelling-status.php', $this->resolve(['bestelling-status.php'])['template']);
    }

    public function testTheLegacyPortfolioProjectRoute(): void
    {
        self::assertSame(
            ['key' => 'portfolio.project', 'template' => 'portfolio-detail.php', 'query' => ['slug' => 'kist'], 'canonical' => null],
            $this->resolve(['portfolio', 'kist'])
        );
    }

    public function testThePersonalisationCatalogue(): void
    {
        self::assertSame('personaliseren.php', $this->resolve(['personaliseren.php'])['template']);
    }

    // ------------------------------------------------------ localized segments

    public function testAFixedSegmentIsMatchedInTheRequestsLanguage(): void
    {
        self::assertSame(
            ['key' => 'shop.collection', 'template' => 'collectie.php', 'query' => ['slug' => 'wood'], 'canonical' => null],
            $this->resolve(['collections', 'wood'], 'en')
        );

        self::assertSame(
            ['key' => 'blog.category', 'template' => 'blog.php', 'query' => ['category' => 'wood'], 'canonical' => null],
            $this->resolve(['blog', 'category', 'wood'], 'en')
        );
    }

    public function testAnotherLanguagesWordStillResolvesAndNamesItsCanonicalForm(): void
    {
        // /en/collecties/hout keeps working, and is told where it belongs.
        $match = $this->resolve(['collecties', 'hout'], 'en');

        self::assertSame('shop.collection', $match['key']);
        self::assertSame('/collections/hout', $match['canonical']);
    }

    public function testTheDutchSpellingIsCanonicalInDutch(): void
    {
        $match = $this->resolve(['collections', 'hout'], 'nl');

        self::assertSame('shop.collection', $match['key']);
        self::assertSame('/collecties/hout', $match['canonical']);
    }

    public function testASegmentWithoutAPerLanguageWordIsTheSameEverywhere(): void
    {
        self::assertSame('blog.index', $this->resolve(['blog'], 'en')['key']);
        self::assertSame('blog.index', $this->resolve(['blog'], 'de')['key']);
        self::assertSame('blog', RouteSegments::value('blog.root', 'de'));
    }

    public function testALanguageWithNoCatalogueEntryUsesTheDefaultWord(): void
    {
        // A site that adds German gets a working /de/blog/categorie/... rather
        // than a 404 — a catalogue is fixed per release, languages are not.
        self::assertSame('categorie', RouteSegments::value('blog.category', 'de'));
        self::assertSame('blog.category', $this->resolve(['blog', 'categorie', 'holz'], 'de')['key']);
    }

    // ------------------------------------------------------- what must NOT match

    public function testTheSlugCharsetIsTheOneApacheMatched(): void
    {
        self::assertNull($this->resolve(['Over-Ons']));
        self::assertNull($this->resolve(['oud_pad']));
        self::assertNull($this->resolve(['legacy.html']));
        self::assertNull($this->resolve(['café']));
    }

    /**
     * Since pages nest (docs/pages/NESTING.md) every path of one to
     * RouteTable::MAX_PAGE_SEGMENTS clean segments has the SHAPE of a page
     * path, and is handed to pagina.php. Whether a page lives there is that
     * template's lookup (PageContent::forPath()), which answers the same 404
     * and asks the Redirect Manager exactly as the dispatcher's own 404 did.
     */
    public function testAnUnknownTwoSegmentPathIsAPagePathThatPaginaPhpJudges(): void
    {
        self::assertSame(
            ['key' => 'core.page', 'template' => 'pagina.php', 'query' => ['slug' => 'oude/pagina'], 'canonical' => null],
            $this->resolve(['oude', 'pagina'])
        );
    }

    public function testAnUnknownNamespaceIsAPagePathTooAndNeverAnotherRoute(): void
    {
        self::assertSame('core.page', $this->resolve(['kollektie', 'hout'])['key'] ?? null);
        self::assertSame('core.page', $this->resolve(['blog', 'categorie', 'hout', 'te-diep'])['key'] ?? null);
    }

    public function testAPagePathLongerThanTheDeepestPageResolvesToNothing(): void
    {
        $deepest = array_fill(0, RouteTable::MAX_PAGE_SEGMENTS, 'a');

        self::assertSame('core.page', $this->resolve($deepest)['key'] ?? null);
        self::assertNull($this->resolve([...$deepest, 'a']));
    }

    public function testEverySegmentOfAPagePathKeepsTheSlugCharset(): void
    {
        self::assertNull($this->resolve(['metaal-graveren', 'RVS']));
        self::assertNull($this->resolve(['metaal-graveren', 'oud_pad']));
        self::assertNull($this->resolve(['metaal-graveren', 'rvs', 'x.html']));
    }

    public function testAnArchiveNamespaceWithoutASlugIsAPostSlugLikeItAlwaysWas(): void
    {
        // `.htaccess`'s ^blog/([a-z0-9-]+)/?$ matched /blog/categorie too, and
        // blog-post.php answers its own 404 for a post nobody wrote. Keeping
        // that is the point: a URL that 404s must go on 404ing in the same way.
        self::assertSame(
            ['key' => 'blog.post', 'template' => 'blog-post.php', 'query' => ['slug' => 'categorie'], 'canonical' => null],
            $this->resolve(['blog', 'categorie'])
        );
    }

    public function testAModuleNamespaceIsNeverSwallowedByThePageRoute(): void
    {
        // The page route is one segment and would match "blog" happily; it is
        // last in the table precisely so it never gets the chance.
        self::assertSame('blog.index', $this->resolve(['blog'])['key']);
    }

    // ------------------------------------------------------------- the table

    public function testThePageRouteIsTheVeryLastEntry(): void
    {
        $routes = RouteTable::all();

        self::assertSame(RouteTable::PAGE_ROUTE, $routes[count($routes) - 1]['key']);
    }

    public function testEveryTemplateInTheTableIsAPlainRootLevelPhpName(): void
    {
        foreach (RouteTable::all() as $route) {
            self::assertMatchesRegularExpression(
                '/\A[a-z0-9-]+\.php\z/',
                $route['template'],
                'route ' . $route['key']
            );
        }
    }

    public function testEveryRouteKeyIsUnique(): void
    {
        $keys = array_column(RouteTable::all(), 'key');

        self::assertSame(array_values(array_unique($keys)), $keys);
    }

    public function testEverySegmentKeyUsedInAPatternExists(): void
    {
        foreach (RouteTable::all() as $route) {
            if ($route['pattern'] === '') {
                continue;
            }

            foreach (explode('/', $route['pattern']) as $part) {
                if (!str_starts_with($part, '{') || !str_ends_with($part, '}')) {
                    continue;
                }

                $name = substr($part, 1, -1);
                if (!str_contains($name, '.')) {
                    continue;
                }

                self::assertTrue(
                    RouteSegments::exists($name),
                    'route ' . $route['key'] . ' names unknown segment ' . $name
                );
            }
        }
    }
}
