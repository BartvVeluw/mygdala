<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\RouteResolver;
use App\Service\Routing\RouteTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * HOW EVERY PUBLIC ROUTE BEHAVES IN A LANGUAGE: a closed list, kept complete
 * against the route table (docs/multilingual/ROUTING.md, §9, §10 and §14).
 *
 * Phase 6 gave every route a URL per language, and the system pages kept
 * missing one piece of it at a time, each found by hand: a canonical built
 * with App\Service\AppUrl alone (cart, checkout, cookie policy, withdrawal,
 * storefront, personalisation, the old project pages), a link that dropped the
 * prefix (order status to withdrawal), a POST whose answer went back to the
 * default language (withdrawal). Nothing asked those questions when the route
 * was written. This list asks them, for every route the dispatcher can serve,
 * and a new route fails here until somebody answers:
 *
 *   witness    the route's URL in the default language, unprefixed, with its
 *              identity where it has one ({order}, {product} and {project} are
 *              filled in by the HTTP test). Null for a route whose address is a
 *              per-language slug, with the reason: its URL cannot be derived
 *              from one path, and its own domain builds it
 *   canonical  the unprefixed path its canonical names, which in every
 *              language is that path under the language's prefix; null where
 *              the page has none
 *   post       how the answer to a form on it finds the visitor's language
 *              back, or that it has no form
 *
 * The query identity itself is Tests\Service\Routing\QueryIdentityRoutesTest's
 * list; this one only insists that a witness carries it.
 *
 * Tests\Service\PublicRouteLanguageTest requests every witness over HTTP in
 * three languages and proves the rest: the canonical, every link to a system
 * route, the switch, and every form's answer. This file needs nothing but
 * PHP: the route table and its matcher are built from code alone.
 */
final class PublicRouteContractTest extends TestCase
{
    /** No form on this page posts anywhere. */
    public const POST_NONE = 'none';

    /**
     * A CMS page is behind this route, which may carry a Core Forms block: the
     * answer goes back to `form-source`, the path the form stood on, taken from
     * REQUEST_URI and so prefixed already (App\Service\Forms\FormSourcePath).
     * The page's content is the editor's, so the HTTP sweep checks only the
     * site's own links there.
     */
    public const POST_FORM_SOURCE = 'form-source';

    /**
     * The form sends its page's language as a hidden `language` field, believed
     * only as an active website language, and the endpoint puts the prefix on
     * its own fixed path (api/withdrawal-request.php).
     */
    public const POST_LANGUAGE_FIELD = 'language-field';

    /**
     * No form post at all: fetch() sends the language in its payload, and
     * Mollie returns the customer to the order page in it (api/checkout.php,
     * App\Service\MolliePaymentData).
     */
    public const POST_FETCH_PAYLOAD = 'fetch-payload';

    private const POST_POLICIES = [self::POST_NONE, self::POST_FORM_SOURCE, self::POST_LANGUAGE_FIELD, self::POST_FETCH_PAYLOAD];

    /**
     * route key => its witness, canonical and form policy (see above).
     *
     * @var array<string, array{witness: string|null, canonical: string|null, post: string, why?: string}>
     */
    public const ROUTES = [
        'core.home' => ['witness' => '/', 'canonical' => '/', 'post' => self::POST_FORM_SOURCE],
        'core.home.file' => ['witness' => '/index.php', 'canonical' => '/', 'post' => self::POST_FORM_SOURCE],
        'core.contact' => ['witness' => '/contact.php', 'canonical' => '/contact.php', 'post' => self::POST_FORM_SOURCE],
        'core.diensten' => ['witness' => '/diensten.php', 'canonical' => '/diensten.php', 'post' => self::POST_FORM_SOURCE],
        'core.over-mij' => ['witness' => '/over-mij.php', 'canonical' => '/over-mij.php', 'post' => self::POST_FORM_SOURCE],
        'core.cookiebeleid' => ['witness' => '/cookiebeleid.php', 'canonical' => '/cookiebeleid.php', 'post' => self::POST_NONE],
        'core.herroeping' => ['witness' => '/herroeping.php?order={order}', 'canonical' => '/herroeping.php', 'post' => self::POST_LANGUAGE_FIELD],
        'blog.feed' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_NONE,
            'why' => 'an RSS document, not a page: no canonical tag, no switch, no form; Tests\Blog\BlogFeedLanguageTest proves it is wholly in the language of its address',
        ],
        'blog.index' => ['witness' => '/blog', 'canonical' => '/blog', 'post' => self::POST_NONE],
        'blog.category' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_NONE,
            'why' => 'a per-language slug under a per-language word: App\Service\Blog\BlogUrls builds it and blog.php declares its versions',
        ],
        'blog.tag' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_NONE,
            'why' => 'a per-language slug: App\Service\Blog\BlogUrls builds it and blog.php declares its versions',
        ],
        'blog.post' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_NONE,
            'why' => 'a per-language slug: App\Service\Blog\BlogUrls builds it and blog-post.php declares its versions',
        ],
        'personalization.catalog' => ['witness' => '/personaliseren.php', 'canonical' => '/personaliseren.php', 'post' => self::POST_NONE],
        'portfolio.index' => ['witness' => '/portfolio', 'canonical' => '/portfolio', 'post' => self::POST_FORM_SOURCE],
        'portfolio.index.file' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_FORM_SOURCE,
            'why' => 'the overview\'s old address: portfolio.php answers a GET there with a 301 to /portfolio in the same language (App\Service\PortfolioUrls), so it has no canonical of its own',
        ],
        'portfolio.project' => ['witness' => '/portfolio/{project}', 'canonical' => '/portfolio/{project}', 'post' => self::POST_NONE],
        'shop.index' => ['witness' => '/shop.php', 'canonical' => '/shop.php', 'post' => self::POST_FORM_SOURCE],
        'shop.product' => ['witness' => '/product.php?id={product}', 'canonical' => '/product.php?id={product}', 'post' => self::POST_NONE],
        'shop.cart' => ['witness' => '/cart.php', 'canonical' => '/cart.php', 'post' => self::POST_NONE],
        'shop.checkout' => ['witness' => '/checkout.php', 'canonical' => '/checkout.php', 'post' => self::POST_FETCH_PAYLOAD],
        'shop.order-status' => ['witness' => '/bestelling-status.php?order={order}', 'canonical' => null, 'post' => self::POST_NONE],
        'shop.collection' => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_NONE,
            'why' => 'a per-language slug under a per-language word: App\Service\CollectionContent builds it and collectie.php declares its versions',
        ],
        RouteTable::PAGE_ROUTE => [
            'witness' => null,
            'canonical' => null,
            'post' => self::POST_FORM_SOURCE,
            'why' => 'a per-language slug: App\Service\PageContent builds it and partials/page-head.php declares its versions',
        ],
    ];

    /** What stands in for a fixture's value when a witness is resolved without a database. */
    private const PLACEHOLDER_VALUE = '7';

    protected function setUp(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
    }

    protected function tearDown(): void
    {
        SiteLanguageFixture::reset();
    }

    public function testEveryRouteIsClassifiedAndEveryClassifiedRouteExists(): void
    {
        $keys = array_map(static fn (array $route): string => $route['key'], RouteTable::all());

        self::assertSame(
            [],
            array_values(array_diff($keys, array_keys(self::ROUTES))),
            'a public route nobody has classified: give it a witness (its URL in the default language), '
                . 'its canonical and how a form on it answers in the visitor\'s language, '
                . 'or a reason why its address cannot be a witness'
        );
        self::assertSame([], array_values(array_diff(array_keys(self::ROUTES), $keys)), 'a classified route that is no longer in the route table');
    }

    public function testEveryEntryAnswersEveryQuestion(): void
    {
        foreach (self::ROUTES as $key => $route) {
            self::assertContains($route['post'], self::POST_POLICIES, $key . ': how does a form on it answer?');

            if ($route['witness'] === null) {
                self::assertNotSame('', trim($route['why'] ?? ''), $key . ': a route without a witness says why');
                self::assertNull($route['canonical'], $key . ': a canonical is only proven through a witness');

                continue;
            }

            self::assertArrayNotHasKey('why', $route, $key . ': a witness needs no excuse');
            self::assertStringStartsWith('/', $route['witness'], $key);
            self::assertStringStartsNotWith('//', $route['witness'], $key);

            if ($route['canonical'] !== null) {
                self::assertStringStartsWith('/', $route['canonical'], $key);
                self::assertStringStartsNotWith('//', $route['canonical'], $key);
            }
        }
    }

    /**
     * A witness is the route's own URL, and the SAME path in every language —
     * which is what lets the HTTP test put a prefix on it and nothing else.
     * Proven with the dispatcher's own matcher, in every language, rather than
     * by reading the witness.
     */
    public function testEveryWitnessIsItsRoutesOwnUrlInEveryLanguage(): void
    {
        foreach (self::ROUTES as $key => $route) {
            if ($route['witness'] === null) {
                continue;
            }

            $path = (string) parse_url(self::fill($route['witness']), PHP_URL_PATH);
            $segments = $path === '/' ? [] : explode('/', trim($path, '/'));

            foreach (['nl', 'en', 'de'] as $language) {
                $match = RouteResolver::resolve($segments, $language);

                self::assertNotNull($match, $key . ': ' . $path . ' resolves in ' . $language);
                self::assertSame($key, $match->key, $path . ' is ' . $key . ' in ' . $language);
                self::assertNull($match->canonicalPath, $path . ' is already the canonical spelling in ' . $language);
            }
        }
    }

    /**
     * A route whose query string names its resource (QueryIdentityRoutesTest)
     * is witnessed WITH that identity, so the HTTP test sees the switch carry
     * it; every other witness has no query at all.
     */
    public function testAWitnessCarriesAQueryIdentityAndNothingElse(): void
    {
        foreach (RouteTable::all() as $table) {
            $route = self::ROUTES[$table['key']] ?? null;
            if ($route === null || $route['witness'] === null) {
                continue;
            }

            $query = (string) parse_url($route['witness'], PHP_URL_QUERY);
            $identity = QueryIdentityRoutesTest::QUERY_IDENTITY[$table['template']] ?? null;

            if ($identity === null) {
                self::assertSame('', $query, $table['key'] . ' has no query identity, so its witness has no query');
            } else {
                self::assertMatchesRegularExpression('/\A' . preg_quote($identity, '/') . '=\{[a-z]+\}\z/', $query, $table['key'] . ' is witnessed with its identity, ?' . $identity . '=');
            }
        }
    }

    /**
     * The witness (or canonical) with every {placeholder} filled in.
     *
     * @param array<string, int|string> $values placeholder => value; a missing one gets a stand-in
     */
    public static function fill(string $witness, array $values = []): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z]+)\}/',
            static fn (array $match): string => (string) ($values[$match[1]] ?? self::PLACEHOLDER_VALUE),
            $witness
        );
    }
}
