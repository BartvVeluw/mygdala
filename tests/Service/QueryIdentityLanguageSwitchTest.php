<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\Language\SiteLanguages;
use App\Service\ProductSeo;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Service\Routing\QueryIdentityRoutesTest;
use Tests\Support\BuiltInServer;

/**
 * THE QUERY-IDENTITY CONTRACT over HTTP (docs/multilingual/ROUTING.md, §9):
 * every route in Tests\Service\Routing\QueryIdentityRoutesTest::QUERY_IDENTITY
 * keeps the resource it shows when a visitor switches language.
 *
 * For each route and every active language:
 *   - it declares its versions: the switch link carries the identity, which
 *     LanguageAlternates' assumed paths never do, since they copy no query;
 *   - that link is the same route under the language's prefix with the
 *     identity and nothing else: no tracking, no status, no `lang`, nothing of
 *     the page's own view state;
 *   - the page behind it shows the same resource.
 *
 * "Shows" means as the page itself reads it, and the switch must read it that
 * way too, neither stricter nor looser. The reader differs per route, so each
 * route has its own witness here (WITNESSES).
 *
 * One battery of awkward values (padding, a plus, a leading zero, an exponent,
 * an array, a repeated parameter, a URL, nothing at all) runs through all
 * three. Where the page shows a resource, every language shows that same one;
 * where it shows none, the switch offers the bare route.
 *
 * Over real HTTP with the dispatcher router in front, because what counts is
 * what a visitor clicks and what that shows.
 */
final class QueryIdentityLanguageSwitchTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-queryidentity-';

    private const CUSTOMER_EMAIL = 'query-identity-switch@__test__.invalid';

    /**
     * route => who reads the resource on that page, and so what shown() asks.
     * The product's body is drawn by shop.js too, but the server decides which
     * product the page is: its status, its canonical, its related products.
     */
    private const WITNESSES = [
        'product.php' => 'the server: the product its canonical names',
        'bestelling-status.php' => 'assets/js/shop/shop.js: the first `order`, trimmed as JavaScript trims, the digits of a positive integer',
        'herroeping.php' => 'the server: the order its form is filled in with',
    ];

    /**
     * Everything a visitor may arrive with that is not the resource: tracking,
     * a refused form's status, a debug flag, a stray `lang`, a redirect target,
     * a Blog page number, a cart line being edited, markup.
     */
    private const UNRELATED = 'utm_source=news&gclid=abc&status=error&reason=validation&debug=1&lang=en'
        . '&next=https%3A%2F%2Fevil.test&pagina=2&line=abc123&x=%3Cscript%3E';

    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $orderIds = [];

    private ?int $customerId = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start([], 'tests/Support/dispatcher-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }

        if (count(SiteLanguages::activeCodes()) < 2) {
            $this->markTestSkipped('this test needs a second active language to switch to');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }
        $this->productIds = [];

        foreach ($this->orderIds as $id) {
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }

        SiteLanguages::clearCache();
        ShopLocalization::clearCache();
        ProductSeo::clearCache();
    }

    /**
     * The list is the contract; a route on it without a witness here would be
     * a route nobody proves.
     */
    public function testEveryListedRouteHasAWitness(): void
    {
        self::assertSame(
            array_keys(QueryIdentityRoutesTest::QUERY_IDENTITY),
            array_keys(self::WITNESSES),
            'every query-identity route needs a resource and a witness in this test'
        );
    }

    public function testEachRouteKeepsItsResourceInEveryLanguageAndCarriesNothingElse(): void
    {
        foreach ($this->resources() as $template => [$resource]) {
            $parameter = QueryIdentityRoutesTest::QUERY_IDENTITY[$template];

            foreach (SiteLanguages::activeCodes() as $from) {
                $url = $this->prefix($from) . '/' . $template . '?' . self::UNRELATED . '&' . $parameter . '=' . $resource . '&utm_campaign=spring';
                $page = $this->get($url);

                self::assertSame(200, $page['status'], $url);
                self::assertSame((string) $resource, $this->shown($template, $url, $page['body']), $url . ' shows the resource');

                foreach (SiteLanguages::activeCodes() as $to) {
                    $href = $this->switchHref($page['body'], $to);
                    self::assertSame(
                        $this->prefix($to) . '/' . $template . '?' . $parameter . '=' . $resource,
                        $href,
                        $url . ' → ' . $to . ': the route under that language\'s prefix, with the resource and nothing else'
                    );

                    $landed = $this->get((string) $href);
                    self::assertSame(200, $landed['status'], (string) $href);
                    self::assertStringContainsString('<html lang="' . $to . '"', $landed['body'], (string) $href);
                    self::assertSame((string) $resource, $this->shown($template, (string) $href, $landed['body']), $href . ' shows the same resource');
                }
            }
        }
    }

    /**
     * The switch reads the resource exactly as the page does. Every value
     * below is sent to every route; the witness says what the page shows, and
     * the switch has to agree in every language: the same resource, or, where
     * the page shows none, the bare route.
     */
    public function testTheSwitchReadsTheResourceExactlyAsThePageDoes(): void
    {
        $battery = [
            '{p}={a}',
            '{p}=%20{a}',                 // padding every reader trims
            '{p}={a}%20',
            '{p}=+{a}',                   // a literal plus is a space in a query string
            '{p}=%09{a}%0A',              // a tab and a line feed
            '{p}={a}%0C',                 // a form feed: JavaScript trims it, PHP's filter does not
            '{p}=%C2%A0{a}',              // a no-break space: the same
            '{p}=%2B{a}',                 // an encoded plus: PHP's filter takes it, shop.js does not
            '{p}=0{a}',                   // a leading zero
            '{p}=-{a}',
            '{p}={a}abc',
            '{p}={a}.0',
            '{p}=1e3',
            '{p}=0x1A',
            '{p}=',
            '{p}',
            '{p}[]={a}',
            '{p}[x]={a}',
            '{p}={a}&{p}={b}',            // repeated: PHP keeps the last, URLSearchParams the first
            '{p}={a}&{p}[]={b}',
            '{p}[]={b}&{p}={a}',
            '{p}=%2F%2Fevil.test',
            '{p}=https%3A%2F%2Fevil.test',
            '{p}=%2Fen%2Fshop.php',
            '{p}=99999999999999999999',
            '{p}=1%0D%0ALocation%3A%20https%3A%2F%2Fevil.test',
        ];

        foreach ($this->resources() as $template => [$a, $b]) {
            $parameter = QueryIdentityRoutesTest::QUERY_IDENTITY[$template];
            $showsSomething = 0;

            foreach ($battery as $pattern) {
                $query = strtr($pattern, ['{p}' => $parameter, '{a}' => (string) $a, '{b}' => (string) $b]);
                $url = '/' . $template . '?' . $query;
                $page = $this->get($url);
                $shown = $this->shown($template, $url, $page['body']);

                self::assertContains($shown, [null, (string) $a, (string) $b], $url);

                foreach (SiteLanguages::activeCodes() as $to) {
                    $href = $this->switchHref($page['body'], $to);

                    if ($shown === null) {
                        self::assertSame($this->prefix($to) . '/' . $template, $href, $url . ' shows nothing, so ' . $to . ' is the bare route');
                        continue;
                    }

                    self::assertSame($this->prefix($to) . '/' . $template . '?' . $parameter . '=' . $shown, $href, $url . ' → ' . $to);
                    self::assertSame($shown, $this->shown($template, (string) $href, $this->get((string) $href)['body']), $href . ' shows what ' . $url . ' showed');
                }

                $showsSomething += $shown === null ? 0 : 1;
            }

            self::assertGreaterThan(1, $showsSomething, $template . ': the battery must reach a shown resource, or it proves nothing');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Resources and witnesses                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Two real resources per route, in the order of the contract list.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function resources(): array
    {
        $orders = [$this->order(), $this->order()];

        return [
            'product.php' => [$this->product(), $this->product()],
            'bestelling-status.php' => $orders,
            'herroeping.php' => $orders,
        ];
    }

    /** What the page at $url shows, as that page reads it; null where it shows nothing. */
    private function shown(string $template, string $url, string $body): ?string
    {
        return match ($template) {
            'product.php' => $this->canonicalProduct($body),
            'bestelling-status.php' => $this->orderShopJsShows($url, $body),
            'herroeping.php' => $this->prefilledOrder($body),
        };
    }

    /** The product the server resolved: the id in its canonical URL. */
    private function canonicalProduct(string $body): ?string
    {
        $links = $this->xpath($body)->query('//link[@rel="canonical"]/@href');
        self::assertNotFalse($links);

        if ($links->length === 0) {
            return null;
        }

        parse_str((string) parse_url((string) $links->item(0)->nodeValue, PHP_URL_QUERY), $query);

        return is_string($query['id'] ?? null) ? $query['id'] : null;
    }

    /**
     * The order assets/js/shop/shop.js shows on the order-status page: the
     * FIRST `order` in the URL (URLSearchParams.get()), refused unless
     * String(parseInt(value)) equals the value trimmed as JavaScript trims it,
     * and is at least 1.
     */
    private function orderShopJsShows(string $url, string $body): ?string
    {
        self::assertStringContainsString('data-order-status', $body, 'this is the order-status page');

        $value = null;
        foreach (explode('&', (string) parse_url($url, PHP_URL_QUERY)) as $pair) {
            $pair = explode('=', $pair, 2);
            if (urldecode($pair[0]) === 'order') {
                $value = urldecode($pair[1] ?? '');
                break;
            }
        }

        if ($value === null) {
            return null;
        }

        // String.prototype.trim(): WhiteSpace and LineTerminator.
        $trimmed = preg_replace('/\A[\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+\z/u', '', $value);

        if (!is_string($trimmed) || preg_match('/\A[1-9][0-9]*\z/', $trimmed) !== 1) {
            return null;
        }

        // parseInt() is exact up to fifteen digits; past that, String() only
        // gives the same digits back when the double holds them exactly.
        return strlen($trimmed) <= 15 || sprintf('%.0f', (float) $trimmed) === $trimmed ? $trimmed : null;
    }

    /** The order the withdrawal form is filled in with. */
    private function prefilledOrder(string $body): ?string
    {
        $inputs = $this->xpath($body)->query('//input[@id="wr-order"]');
        self::assertNotFalse($inputs);
        self::assertSame(1, $inputs->length, 'this is the withdrawal form');

        $value = $inputs->item(0)->getAttribute('value');

        return $value === '' ? null : $value;
    }

    private function product(): int
    {
        $id = (new ProductRepository())->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(6)),
            'price' => 12.5,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);
        $this->productIds[] = $id;

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => 'Querytest ' . $id]);
        ShopLocalization::saveProduct($id, 'en', [ShopLocalization::NAME => 'Query test ' . $id]);
        ShopLocalization::clearCache();

        return $id;
    }

    /** A paid order without a Mollie payment, so nothing ever calls out. */
    private function order(): int
    {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Query Identity',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $id = (new OrderRepository())->create(
            $this->customerId,
            24.95,
            4.95,
            'verzenden',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Query', 'last_name' => 'Identity', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        $this->orderIds[] = $id;

        Database::connection()
            ->prepare("UPDATE orders SET status = 'paid' WHERE id = :id")
            ->execute(['id' => $id]);

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** A language's prefix, by the rule itself: none for the default language. */
    private function prefix(string $language): string
    {
        return $language === SiteLanguages::defaultCode() ? '' : '/' . $language;
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    /** Where the public language switch sends a visitor for one language, or null when it links nothing. */
    private function switchHref(string $html, string $language): ?string
    {
        $links = $this->xpath($html)->query('//div[contains(@class, "lang-switch")]/a[@hreflang="' . $language . '"]');
        self::assertNotFalse($links);

        return $links->length === 0 ? null : $links->item(0)->getAttribute('href');
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
