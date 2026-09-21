<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\OrderRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\AppUrl;
use App\Service\Language\SiteLanguages;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * THE LANGUAGE SWITCH ON THE WITHDRAWAL FORM keeps the order
 * (docs/multilingual/ROUTING.md, §9).
 *
 * The order-status page links a customer to /herroeping.php?order=7, and a
 * refused request comes back there with its order too. That id fills in the
 * form's order field, and the switch's assumed paths carry the path only, so
 * it used to offer /en/herroeping.php: the same form with the order gone.
 * herroeping.php now declares its versions from the very value it fills the
 * field with, so the switch reads the id exactly as the page does.
 *
 * Over real HTTP with the dispatcher router in front, because what counts is
 * what a customer clicks and what the form then says.
 */
final class WithdrawalLanguageSwitchTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'withdrawal-switch@__test__.invalid';

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

    public function testTheDutchFormLinksTheSameOrderInEnglish(): void
    {
        $id = $this->order();

        $dutch = $this->get('/herroeping.php?order=' . $id);
        self::assertSame(200, $dutch['status']);
        self::assertSame((string) $id, $this->prefilled($dutch['body']));
        self::assertSame('/en/herroeping.php?order=' . $id, $this->switchHref($dutch['body'], 'en'));

        $english = $this->follow($dutch['body'], 'en');
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        self::assertSame((string) $id, $this->prefilled($english['body']), 'the English form is filled in with the same order');
    }

    public function testTheEnglishFormLinksTheSameOrderBackInDutch(): void
    {
        $id = $this->order();

        $english = $this->get('/en/herroeping.php?order=' . $id);
        self::assertSame(200, $english['status']);
        self::assertStringContainsString('<html lang="en"', $english['body']);
        self::assertSame((string) $id, $this->prefilled($english['body']));
        self::assertSame('/herroeping.php?order=' . $id, $this->switchHref($english['body'], 'nl'), 'the default language keeps the unprefixed URL');

        $dutch = $this->follow($english['body'], 'nl');
        self::assertSame(200, $dutch['status']);
        self::assertStringContainsString('<html lang="nl"', $dutch['body']);
        self::assertSame((string) $id, $this->prefilled($dutch['body']));
    }

    /**
     * A language is a row in site_languages and nothing else. German gets the
     * same order like every other language, and the way back is just as exact.
     */
    public function testAThirdLanguageLinksTheSameOrderToo(): void
    {
        $this->addGerman();
        $id = $this->order();

        $dutch = $this->get('/herroeping.php?order=' . $id);
        self::assertSame('/de/herroeping.php?order=' . $id, $this->switchHref($dutch['body'], 'de'));

        $german = $this->follow($dutch['body'], 'de');
        self::assertSame(200, $german['status']);
        self::assertStringContainsString('<html lang="de"', $german['body']);
        self::assertSame((string) $id, $this->prefilled($german['body']));

        self::assertSame('/en/herroeping.php?order=' . $id, $this->switchHref($german['body'], 'en'));
        self::assertSame('/herroeping.php?order=' . $id, $this->switchHref($german['body'], 'nl'));
    }

    /* ------------------------------------------------------------------ */
    /* Only the order travels                                              */
    /* ------------------------------------------------------------------ */

    /**
     * api/withdrawal-request.php sends a refused request back with its status,
     * its reason and its order. The order is the identity; the status and the
     * reason belong to that one attempt, like tracking or a stray `lang`, and
     * must not follow the customer into the other language.
     */
    public function testARefusedRequestCarriesItsOrderButNotItsStatus(): void
    {
        $id = $this->order();

        foreach (
            [
                '/herroeping.php?status=error&reason=validation&order=' . $id,
                '/herroeping.php?utm_source=mail&order=' . $id . '&status=error&reason=duplicate&lang=en&debug=1',
                '/en/herroeping.php?order=' . $id . '&gclid=abc&next=https%3A%2F%2Fevil.test&x=%3Cscript%3E',
            ] as $url
        ) {
            $page = $this->get($url);
            self::assertSame(200, $page['status'], $url);
            self::assertSame((string) $id, $this->prefilled($page['body']), $url);
            self::assertSame('/herroeping.php?order=' . $id, $this->switchHref($page['body'], 'nl'), $url);
            self::assertSame('/en/herroeping.php?order=' . $id, $this->switchHref($page['body'], 'en'), $url);
        }

        $refused = $this->get('/herroeping.php?status=error&reason=validation&order=' . $id);
        self::assertStringContainsString('form-status--error', $refused['body']);

        $english = $this->follow($refused['body'], 'en');
        self::assertSame((string) $id, $this->prefilled($english['body']));
        self::assertStringNotContainsString('form-status--error', $english['body'], 'the refusal stays with the attempt it was about');
    }

    /**
     * What the form is filled in with is what travels, read by the same
     * FILTER_VALIDATE_INT the page uses: a padded id or a signed one is still
     * that order here, so it is that order in every language; of a repeated
     * parameter PHP reads the last, and so does the switch.
     */
    public function testWhatTheFormFillsInIsWhatTravels(): void
    {
        $id = $this->order();
        $other = $this->order();

        foreach (
            [
                '?order=%20' . $id => $id,
                '?order=' . $id . '%20' => $id,
                '?order=+' . $id => $id,
                '?order=%2B' . $id => $id,
                '?order=' . $other . '&order=' . $id => $id,
                '?order[]=' . $other . '&order=' . $id => $id,
            ] as $query => $expected
        ) {
            $page = $this->get('/herroeping.php' . $query);
            self::assertSame((string) $expected, $this->prefilled($page['body']), $query);
            self::assertSame('/en/herroeping.php?order=' . $expected, $this->switchHref($page['body'], 'en'), $query);
            self::assertSame((string) $expected, $this->prefilled($this->follow($page['body'], 'en')['body']), $query);
        }
    }

    /**
     * Whatever fills in no order here declares nothing: the switch offers the
     * bare form in each language, which is what this URL is, and never an
     * address built from what was typed.
     */
    public function testAMalformedOrMissingOrderCarriesNothing(): void
    {
        $id = $this->order();

        $queries = ['', '?utm_source=news', '?order', '?order=', '?order[]=' . $id, '?order[x]=' . $id, '?order=' . $id . '&order[]=' . $id];
        foreach (
            [
                'abc', '0', '-' . $id, '0' . $id, $id . 'abc', $id . '.0', '1e3', '0x1A', "\u{A0}" . $id, $id . "\f",
                '99999999999999999999', '//evil.test', 'https://evil.test', '/en/shop.php', "1\r\nLocation: https://evil.test",
            ] as $value
        ) {
            $queries[] = '?order=' . rawurlencode($value);
        }

        foreach ($queries as $query) {
            foreach (['', '/en'] as $prefix) {
                $page = $this->get($prefix . '/herroeping.php' . $query);

                self::assertSame(200, $page['status'], $prefix . $query);
                self::assertNull($this->prefilled($page['body']), 'the form fills in nothing for ' . json_encode($prefix . $query));
                self::assertSame('/en/herroeping.php', $this->switchHref($page['body'], 'en'), json_encode($prefix . $query));
                self::assertSame('/herroeping.php', $this->switchHref($page['body'], 'nl'), json_encode($prefix . $query));
                self::assertStringNotContainsString('hreflang="x-default"', $page['body'], 'nothing declared, nothing advertised');
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* What the head says                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * This page has a canonical, so a declared set is also its hreflang: it
     * names exactly what the switch links, the page itself included, and the
     * page stays out of the index. The bare form declares nothing and so still
     * advertises nothing.
     */
    public function testTheFilledInFormAdvertisesOnlyWhatTheSwitchLinks(): void
    {
        $id = $this->order();

        foreach (['/herroeping.php?order=' . $id, '/en/herroeping.php?order=' . $id] as $url) {
            $page = $this->get($url);

            self::assertMatchesRegularExpression('~<meta name="robots" content="noindex~', $page['body'], $url);
            self::assertSame(
                [
                    'nl' => AppUrl::canonical('herroeping.php?order=' . $id),
                    'en' => AppUrl::canonical('en/herroeping.php?order=' . $id),
                    'x-default' => AppUrl::canonical('herroeping.php?order=' . $id),
                ],
                $this->hreflang($page['body']),
                $url
            );
            self::assertSame(AppUrl::canonical(ltrim((string) $this->switchHref($page['body'], 'en'), '/')), $this->hreflang($page['body'])['en']);
        }

        self::assertSame([], $this->hreflang($this->get('/herroeping.php')['body']), 'the bare form advertises nothing, as before');
    }

    /* ------------------------------------------------------------------ */
    /* The prefix belongs to the default language, not to Dutch             */
    /* ------------------------------------------------------------------ */

    public function testFlippingTheDefaultLanguageMovesThePrefixOnly(): void
    {
        $id = $this->order();

        SiteLanguages::setDefault('en');

        try {
            $english = $this->get('/herroeping.php?order=' . $id);
            self::assertStringContainsString('<html lang="en"', $english['body']);
            self::assertSame('/nl/herroeping.php?order=' . $id, $this->switchHref($english['body'], 'nl'));

            $dutch = $this->follow($english['body'], 'nl');
            self::assertSame(200, $dutch['status']);
            self::assertStringContainsString('<html lang="nl"', $dutch['body']);
            self::assertSame((string) $id, $this->prefilled($dutch['body']));
            self::assertSame('/herroeping.php?order=' . $id, $this->switchHref($dutch['body'], 'en'));
        } finally {
            SiteLanguages::setDefault('nl');
            SiteLanguages::clearCache();
        }

        self::assertSame('/en/herroeping.php?order=' . $id, $this->switchHref($this->get('/herroeping.php?order=' . $id)['body'], 'en'));
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** A real order, so "the same order" means one. The form never looks it up. */
    private function order(): int
    {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Withdrawal Switch',
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
                'first_name' => 'Withdrawal', 'last_name' => 'Switch', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        $this->orderIds[] = $id;

        return $id;
    }

    /** The order the withdrawal form is filled in with, or null when its field is empty. */
    private function prefilled(string $html): ?string
    {
        $inputs = $this->xpath($html)->query('//input[@id="wr-order"]');
        self::assertNotFalse($inputs);
        self::assertSame(1, $inputs->length, 'this is the withdrawal form');

        $value = $inputs->item(0)->getAttribute('value');

        return $value === '' ? null : $value;
    }

    /** @return array<string, string> hreflang => href */
    private function hreflang(string $html): array
    {
        $links = $this->xpath($html)->query('//link[@rel="alternate"][@hreflang]');
        self::assertNotFalse($links);

        $alternates = [];
        foreach ($links as $link) {
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        return $alternates;
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

        return $this->get($href);
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
