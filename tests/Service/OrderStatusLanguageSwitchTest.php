<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\SiteLanguages;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * THE LANGUAGE SWITCH ON THE ORDER-STATUS PAGE keeps the order
 * (docs/multilingual/ROUTING.md, §9).
 *
 * The page a customer returns to after paying is identified by the order id
 * in its query string — /bestelling-status.php?order=7,
 * /en/bestelling-status.php?order=7 — and the switch's assumed paths carry
 * the path only. So it used to offer /en/bestelling-status.php: the bare
 * route, which tells the customer their order cannot be found.
 * bestelling-status.php now declares its versions, built from the id it
 * validated, the way product.php declares a product's.
 *
 * Over real HTTP with the dispatcher router in front, because what matters is
 * what a customer clicks and what that shows. The order itself is drawn by
 * assets/js/shop/shop.js from api/order-status.php, so "what the page shows"
 * below is that same request, for the id that script reads from the URL.
 */
final class OrderStatusLanguageSwitchTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'order-status-switch@__test__.invalid';

    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $orderIds = [];

    private ?int $customerId = null;

    private bool $addedGerman = false;

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

        if (!in_array('en', SiteLanguages::activeCodes(), true) || SiteLanguages::defaultCode() !== 'nl') {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->orderIds as $id) {
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }

        if ($this->addedGerman) {
            $db->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            $this->addedGerman = false;
        }

        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The same order, in every language                                   */
    /* ------------------------------------------------------------------ */

    public function testTheDutchPageLinksTheSameOrderInEnglish(): void
    {
        $id = $this->order('paid');

        $dutch = $this->get('/bestelling-status.php?order=' . $id);
        self::assertSame(200, $dutch['status']);
        self::assertSame('/en/bestelling-status.php?order=' . $id, $this->switchHref($dutch['body'], 'en'));

        $english = $this->follow($dutch['body'], 'en');
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        $this->assertShowsOrder($id, 'paid', $this->switchHref($dutch['body'], 'en'), $english['body']);
    }

    public function testTheEnglishPageLinksTheSameOrderBackInDutch(): void
    {
        $id = $this->order('failed');

        $english = $this->get('/en/bestelling-status.php?order=' . $id);
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        self::assertSame('/bestelling-status.php?order=' . $id, $this->switchHref($english['body'], 'nl'), 'the default language keeps the unprefixed URL');

        $dutch = $this->follow($english['body'], 'nl');
        self::assertSame(200, $dutch['status']);
        self::assertStringContainsString('<html lang="nl"', $dutch['body']);
        $this->assertShowsOrder($id, 'failed', $this->switchHref($english['body'], 'nl'), $dutch['body']);
    }

    /**
     * A language is a row in site_languages and nothing else. German gets the
     * same order like every other language, and the way back is just as exact.
     */
    public function testAThirdLanguageLinksTheSameOrderToo(): void
    {
        $this->addGerman();
        $id = $this->order('paid');

        $dutch = $this->get('/bestelling-status.php?order=' . $id);
        self::assertSame('/de/bestelling-status.php?order=' . $id, $this->switchHref($dutch['body'], 'de'));

        $german = $this->follow($dutch['body'], 'de');
        self::assertSame(200, $german['status']);
        self::assertStringContainsString('<html lang="de"', $german['body']);
        $this->assertShowsOrder($id, 'paid', $this->switchHref($dutch['body'], 'de'), $german['body']);

        self::assertSame('/en/bestelling-status.php?order=' . $id, $this->switchHref($german['body'], 'en'));
        self::assertSame('/bestelling-status.php?order=' . $id, $this->switchHref($german['body'], 'nl'));
    }

    /**
     * Declaring feeds the switch and nothing else. This page is about one
     * customer's order and has no canonical, so it advertises no alternates
     * and stays out of the index in every language.
     */
    public function testTheOrderPageStillAdvertisesNothing(): void
    {
        $id = $this->order('paid');

        foreach (['/bestelling-status.php?order=' . $id, '/en/bestelling-status.php?order=' . $id] as $url) {
            $page = $this->get($url);
            self::assertNotNull($this->switchHref($page['body'], 'en'), $url);
            self::assertStringNotContainsString('rel="canonical"', $page['body'], $url);
            self::assertStringNotContainsString('hreflang="x-default"', $page['body'], $url);
            self::assertSame(0, $this->xpath($page['body'])->query('//link[@rel="alternate"][@hreflang]')->length, $url);
            self::assertMatchesRegularExpression('~<meta name="robots" content="noindex~', $page['body'], $url);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Only the identity travels                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The order id is the identity; everything else a customer arrived with —
     * tracking, a status, a debug flag, a stray `lang` — belongs to that one
     * visit and must not be handed to the next page.
     */
    public function testUnrelatedQueryParametersAreNotCarriedAlong(): void
    {
        $id = $this->order('paid');

        foreach (
            [
                '/bestelling-status.php?order=' . $id . '&utm_source=news&status=success&debug=1&lang=en&x=%3Cscript%3E',
                '/bestelling-status.php?utm_campaign=spring&order=' . $id . '&next=https%3A%2F%2Fevil.test',
                '/en/bestelling-status.php?order=' . $id . '&gclid=abc&form-status=ok&redirect=%2F%2Fevil.test',
            ] as $url
        ) {
            $page = $this->get($url);
            self::assertSame(200, $page['status'], $url);
            self::assertSame('/bestelling-status.php?order=' . $id, $this->switchHref($page['body'], 'nl'), $url);
            self::assertSame('/en/bestelling-status.php?order=' . $id, $this->switchHref($page['body'], 'en'), $url);

            $links = $this->xpath($page['body'])->query('//div[contains(@class, "lang-switch")]/a/@href');
            self::assertNotFalse($links);
            self::assertGreaterThan(1, $links->length, $url);
            foreach ($links as $attribute) {
                self::assertMatchesRegularExpression(
                    '~\A(/[a-z]{2})?/bestelling-status\.php\?order=' . $id . '\z~',
                    $attribute->nodeValue,
                    $url . ' carried something besides the order'
                );
            }
        }
    }

    /**
     * Only the plain form of a positive integer is an order: the digits the
     * Mollie return URL carries, nothing around them. Anything else — no id,
     * an empty one, a sign, a leading zero, trailing words, an array, a URL —
     * declares nothing, so the switch offers the bare route in each language,
     * which is what this URL is. In particular a value shop.js refuses to
     * show ('+7', '07') must not come out the other side as a valid ?order=7.
     */
    public function testAMalformedOrMissingOrderCarriesNothing(): void
    {
        $id = $this->order('paid');

        $queries = ['', '?utm_source=news', '?order=', '?order[]=' . $id, '?order[x]=' . $id];
        foreach (
            [
                'abc', '0', '-' . $id, '+' . $id, '0' . $id, $id . 'abc', $id . '.0', '1e3', ' ' . $id, $id . ' ',
                '0x1A', '99999999999999999999', '//evil.test', 'https://evil.test', "1\r\nLocation: https://evil.test",
            ] as $value
        ) {
            $queries[] = '?order=' . rawurlencode($value);
        }

        foreach ($queries as $query) {
            $page = $this->get('/bestelling-status.php' . $query);

            self::assertSame(200, $page['status'], 'the route itself still resolves for ' . json_encode($query));
            self::assertSame('/en/bestelling-status.php', $this->switchHref($page['body'], 'en'), json_encode($query));
            self::assertSame('/bestelling-status.php', $this->switchHref($page['body'], 'nl'), json_encode($query));
            self::assertStringNotContainsString('evil.test', (string) $this->switchHref($page['body'], 'en'));
            self::assertNull($this->orderShownAt((string) $this->switchHref($page['body'], 'en')), 'the bare route shows no order');
        }

        $english = $this->get('/en/bestelling-status.php?order=%2B' . $id);
        self::assertSame('/bestelling-status.php', $this->switchHref($english['body'], 'nl'), 'nor the other way round');
    }

    /**
     * An order that is not there is not there in any language. The switch
     * still names the same id — reading that answer in English is a fair
     * request — so the other language gives the same "cannot be found".
     */
    public function testAnOrderThatDoesNotExistKeepsItsIdAndItsAnswer(): void
    {
        $id = $this->order('paid');
        Database::connection()->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);

        $dutch = $this->get('/bestelling-status.php?order=' . $id);
        $href = $this->switchHref($dutch['body'], 'en');
        self::assertSame('/en/bestelling-status.php?order=' . $id, $href);
        self::assertNull($this->orderShownAt('/bestelling-status.php?order=' . $id));

        self::assertSame(200, $this->follow($dutch['body'], 'en')['status']);
        self::assertNull($this->orderShownAt((string) $href), 'the same answer in English');
    }

    /* ------------------------------------------------------------------ */
    /* The prefix belongs to the default language, not to Dutch             */
    /* ------------------------------------------------------------------ */

    public function testFlippingTheDefaultLanguageMovesThePrefixOnly(): void
    {
        $id = $this->order('paid');

        SiteLanguages::setDefault('en');

        try {
            $english = $this->get('/bestelling-status.php?order=' . $id);
            self::assertStringContainsString('<html lang="en"', $english['body']);
            self::assertSame('/nl/bestelling-status.php?order=' . $id, $this->switchHref($english['body'], 'nl'));

            $dutch = $this->follow($english['body'], 'nl');
            self::assertSame(200, $dutch['status']);
            self::assertStringContainsString('<html lang="nl"', $dutch['body']);
            self::assertSame('/bestelling-status.php?order=' . $id, $this->switchHref($dutch['body'], 'en'));
        } finally {
            SiteLanguages::setDefault('nl');
            SiteLanguages::clearCache();
        }

        self::assertSame(
            '/en/bestelling-status.php?order=' . $id,
            $this->switchHref($this->get('/bestelling-status.php?order=' . $id)['body'], 'en')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * An order in $status, without a Mollie payment, so api/order-status.php
     * answers from the row and never calls out.
     */
    private function order(string $status): int
    {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Switch Test',
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
                'first_name' => 'Switch', 'last_name' => 'Test', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        $this->orderIds[] = $id;

        Database::connection()
            ->prepare('UPDATE orders SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $id]);

        return $id;
    }

    /**
     * The page reached through $href shows order $id in $status: the id
     * shop.js reads from that URL is answered with this order, and the page's
     * own server-rendered link to the withdrawal form names the same order.
     */
    private function assertShowsOrder(int $id, string $status, ?string $href, string $body): void
    {
        $order = (new OrderRepository())->findById($id);
        self::assertNotNull($order);

        $shown = $this->orderShownAt((string) $href);
        self::assertNotNull($shown, 'the followed link shows an order');
        self::assertSame($id, $shown['order_id'], 'the same order');
        self::assertSame(OrderRepository::orderNumber($order), $shown['order_number']);
        self::assertSame($status, $shown['status'], 'with the same status');

        self::assertStringContainsString('data-order-status', $body);
        self::assertStringContainsString("href='/herroeping.php?order=" . $id . "'", $body);
    }

    /**
     * What the order-status page at $href shows, as assets/js/shop/shop.js
     * decides it: the `order` parameter of the page's own URL, refused unless
     * String(parseInt(value)) equals the trimmed value and is at least 1, then
     * GET /api/order-status.php for that id. Null where the page shows "this
     * order can't be found".
     *
     * @return array<string, mixed>|null
     */
    private function orderShownAt(string $href): ?array
    {
        parse_str((string) parse_url(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $query);
        $value = $query['order'] ?? null;

        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', trim($value)) !== 1) {
            return null;
        }

        $answer = $this->get('/api/order-status.php?order=' . trim($value));
        if ($answer['status'] !== 200) {
            return null;
        }

        $payload = json_decode($answer['body'], true);
        self::assertIsArray($payload);

        return $payload['data'] ?? null;
    }

    private function addGerman(): void
    {
        if (SiteLanguages::exists('de')) {
            $this->markTestSkipped('the test database already registers de');
        }

        (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
        $this->addedGerman = true;
        SiteLanguages::clearCache();
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
    }

    /** Click the switch: request exactly the href it prints for one language. */
    private function follow(string $html, string $language): array
    {
        $href = $this->switchHref($html, $language);
        self::assertNotNull($href, 'the switch links ' . $language);
        self::assertStringStartsWith('/', $href);
        self::assertStringStartsNotWith('//', $href, 'a switch link never leaves this site');

        return $this->get(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
