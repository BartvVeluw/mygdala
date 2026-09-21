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
 * THE WITHDRAWAL FORM'S ANSWER COMES BACK IN THE LANGUAGE IT WAS FILLED IN
 * (docs/multilingual/ROUTING.md, §14).
 *
 * api/withdrawal-request.php has no language in its path, and it used to send
 * every answer to /herroeping.php: a customer who filled in /en/herroeping.php
 * read "we could not find that order" in Dutch. The form now sends its page's
 * language as a hidden field, the endpoint believes it only when it is an
 * active website language, and App\Service\Routing\LocalizedUrl puts the
 * prefix on the endpoint's own path. So the one thing a submitted value can
 * choose is which language's form the customer returns to.
 *
 * Over real HTTP, the form fetched first and posted with its own hidden
 * fields, because that is what a customer's browser does. Mail goes to a port
 * nobody listens on: a failed e-mail never turns into an error for the
 * customer (the endpoint's own rule), and nothing leaves this machine.
 */
final class WithdrawalRequestLanguageTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'withdrawal-language@example.invalid';

    private const LANGUAGES = ['nl' => '', 'en' => '/en', 'de' => '/de'];

    private static ?BuiltInServer $server = null;

    /** @var list<int> */
    private array $orderIds = [];

    private ?int $customerId = null;

    /** @var list<string> */
    private array $addedLanguages = [];

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start(['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '9'], 'tests/Support/dispatcher-router.php');
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

        $this->addLanguage('de', true);
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->orderIds as $id) {
            $db->prepare('DELETE FROM withdrawal_requests WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }

        foreach ($this->addedLanguages as $code) {
            $db->prepare('DELETE FROM site_languages WHERE code = :code')->execute(['code' => $code]);
        }
        $this->addedLanguages = [];

        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* Refused, accepted, or a bot: back in the same language               */
    /* ------------------------------------------------------------------ */

    /**
     * An order and an address that do not belong together: refused, with the
     * order, on the form in the language it was filled in. The form there is
     * filled in again, says why, and would post in that language once more.
     */
    public function testARefusedRequestReturnsToTheFormInItsOwnLanguage(): void
    {
        $id = $this->order('paid');

        foreach (self::LANGUAGES as $language => $prefix) {
            $answer = $this->submit($prefix, ['order_id' => (string) $id, 'email' => 'someone-else@example.invalid']);

            self::assertSame(303, $answer['status'], $language);
            self::assertSame($prefix . '/herroeping.php?status=error&reason=validation&order=' . $id, $answer['location'], $language);

            $form = $this->get($answer['location']);
            self::assertSame(200, $form['status'], $language);
            self::assertStringContainsString('<html lang="' . $language . '"', $form['body'], 'the refusal is read in ' . $language);
            self::assertStringContainsString('form-status--error', $form['body'], $language);
            self::assertSame((string) $id, $this->field($form['body'], 'order_id'), 'the same order is filled in again');
            self::assertSame($language, $this->field($form['body'], 'language'), 'and the next attempt posts in ' . $language . ' too');
        }
    }

    /** Nothing usable at all: refused without an order, still in its own language. */
    public function testAnIncompleteRequestReturnsInItsOwnLanguageToo(): void
    {
        foreach (self::LANGUAGES as $language => $prefix) {
            $answer = $this->submit($prefix, ['order_id' => '', 'email' => '']);

            self::assertSame(303, $answer['status'], $language);
            self::assertSame($prefix . '/herroeping.php?status=error&reason=validation', $answer['location'], $language);
        }
    }

    /** A second request for an order that already has an open one. */
    public function testADuplicateRequestReturnsInItsOwnLanguage(): void
    {
        $id = $this->order('paid');

        self::assertSame('/en/herroeping.php?status=success', $this->submit('/en', ['order_id' => (string) $id, 'email' => self::CUSTOMER_EMAIL])['location']);

        $answer = $this->submit('/en', ['order_id' => (string) $id, 'email' => self::CUSTOMER_EMAIL]);
        self::assertSame(303, $answer['status']);
        self::assertSame('/en/herroeping.php?status=error&reason=duplicate&order=' . $id, $answer['location']);
    }

    /**
     * The real thing: the order and its own address. Stored once, and the
     * customer lands on the thank-you in the language they wrote in.
     */
    public function testAnAcceptedRequestReturnsToTheFormInItsOwnLanguage(): void
    {
        foreach (self::LANGUAGES as $language => $prefix) {
            $id = $this->order('paid');

            $answer = $this->submit($prefix, ['order_id' => (string) $id, 'email' => self::CUSTOMER_EMAIL, 'reason' => 'Test ' . $language]);

            self::assertSame(303, $answer['status'], $language);
            self::assertSame($prefix . '/herroeping.php?status=success', $answer['location'], $language);
            self::assertSame(1, $this->withdrawalRequestsFor($id), 'the request was stored once, in ' . $language);

            $thanks = $this->get($answer['location']);
            self::assertSame(200, $thanks['status'], $language);
            self::assertStringContainsString('<html lang="' . $language . '"', $thanks['body'], $language);
            self::assertStringContainsString('form-status--ok', $thanks['body'], $language);
        }
    }

    /**
     * A bot that fills in the honeypot, or posts faster than a person can, is
     * told "thank you" and nothing is stored. That answer is in its page's
     * language too, so the fake success cannot be told apart from a real one.
     */
    public function testABotsFakeSuccessReturnsInItsOwnLanguage(): void
    {
        $id = $this->order('paid');

        foreach (['en' => '/en', 'de' => '/de'] as $language => $prefix) {
            $honeypot = $this->submit($prefix, ['order_id' => (string) $id, 'email' => self::CUSTOMER_EMAIL, 'hp-note' => 'bot']);
            self::assertSame($prefix . '/herroeping.php?status=success', $honeypot['location'], $language);

            $tooFast = $this->submit($prefix, ['order_id' => (string) $id, 'email' => self::CUSTOMER_EMAIL, 'form_ts' => (string) time()]);
            self::assertSame($prefix . '/herroeping.php?status=success', $tooFast['location'], $language);
        }

        self::assertSame(0, $this->withdrawalRequestsFor($id), 'a bot stores nothing');
    }

    /* ------------------------------------------------------------------ */
    /* Only the order travels, and only the order the endpoint validated     */
    /* ------------------------------------------------------------------ */

    /**
     * The order in the answer is the id the endpoint validated and looked up,
     * never the raw text that was posted, and nothing else from the request
     * comes back: only status, reason and order.
     */
    public function testTheOrderThatComesBackIsTheOrderThatWasChecked(): void
    {
        $id = $this->order('pending');

        foreach ([' ' . $id, $id . ' ', '+' . $id] as $posted) {
            $answer = $this->submit('/en', [
                'order_id' => $posted,
                'email' => self::CUSTOMER_EMAIL,
                'utm_source' => 'mail',
                'status' => 'success',
                'order' => '999',
            ]);

            self::assertSame('/en/herroeping.php?status=error&reason=validation&order=' . $id, $answer['location'], json_encode($posted));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Nothing a request sends can choose another destination               */
    /* ------------------------------------------------------------------ */

    /**
     * The language field is believed only as an ACTIVE website language. Every
     * other value — unknown, inactive, a path, a URL, a header, an array — is
     * the default language, and no other field (a return path, a `next`, a
     * form source, a Referer) is read at all. The answer is always this site's
     * own withdrawal form.
     */
    public function testNoSubmittedValueCanChooseWhereTheAnswerGoes(): void
    {
        $this->addLanguage('fr', false);
        $bare = '/herroeping.php?status=error&reason=validation';

        foreach (
            [
                '', 'xx', 'fr', 'nl-BE', '//evil.test', 'https://evil.test', '/en', 'en/', 'en/../../admin',
                "en\r\nLocation: https://evil.test", '%2F%2Fevil.test', 'en?x=1', 'e', 'eng',
            ] as $value
        ) {
            $answer = $this->submit('', ['order_id' => '', 'email' => '', 'language' => $value] + self::HIJACKERS, ['Referer: https://evil.test/en/']);

            self::assertSame(303, $answer['status'], json_encode($value));
            self::assertSame($bare, $answer['location'], 'language ' . json_encode($value) . ' is the default language');
        }

        $array = self::$server->request('POST', '/api/withdrawal-request.php', null, [
            'language[]' => 'en', 'order_id' => '', 'email' => '', 'form_ts' => (string) (time() - 60),
        ]);
        self::assertSame($bare, $array['location'], 'an array is no language');

        // What a hand-written form might send is forgiven, like everywhere a
        // language code is read: case and surrounding whitespace.
        foreach (['EN', ' en ', "en\n"] as $value) {
            self::assertSame(
                '/en' . $bare,
                $this->submit('', ['order_id' => '', 'email' => '', 'language' => $value])['location'],
                json_encode($value)
            );
        }

        // And the hijack fields change nothing for a real language either.
        self::assertSame('/en' . $bare, $this->submit('/en', ['order_id' => '', 'email' => ''] + self::HIJACKERS)['location']);
    }

    /* ------------------------------------------------------------------ */
    /* The prefix belongs to the default language, not to Dutch             */
    /* ------------------------------------------------------------------ */

    public function testFlippingTheDefaultLanguageMovesThePrefixOnly(): void
    {
        SiteLanguages::setDefault('en');

        try {
            self::assertSame('/herroeping.php?status=error&reason=validation', $this->submit('', ['order_id' => '', 'email' => ''])['location']);
            self::assertSame('/nl/herroeping.php?status=error&reason=validation', $this->submit('/nl', ['order_id' => '', 'email' => ''])['location']);
            self::assertSame('/herroeping.php?status=error&reason=validation', $this->submit('', ['order_id' => '', 'email' => '', 'language' => 'xx'])['location'], 'the default is English now');
        } finally {
            SiteLanguages::setDefault('nl');
            SiteLanguages::clearCache();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** Fields a hijack would try; the endpoint reads none of them. */
    private const HIJACKERS = [
        'return' => 'https://evil.test/',
        'next' => '//evil.test',
        'redirect' => '/admin/',
        'form-source' => '//evil.test/en/herroeping.php',
        'lang' => 'en',
    ];

    /**
     * Fetch the withdrawal form in one language and post it, the way a browser
     * does: its own hidden fields (the language among them) and a submit time
     * older than the endpoint's minimum, then $fields on top.
     *
     * @param array<string, string> $fields
     * @param list<string> $headers
     * @return array{status: int, location: string, body: string, headers: string}
     */
    private function submit(string $prefix, array $fields, array $headers = []): array
    {
        $form = $this->get($prefix . '/herroeping.php');
        self::assertSame(200, $form['status'], $prefix . '/herroeping.php');

        $hidden = [];
        $inputs = $this->xpath($form['body'])->query('//form[@action="/api/withdrawal-request.php"]//input[@type="hidden"]');
        self::assertNotFalse($inputs);
        foreach ($inputs as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        self::assertArrayHasKey('language', $hidden, 'the form sends its language');

        $hidden['form_ts'] = (string) (time() - 60);
        $hidden['hp-note'] = '';

        return self::$server->request('POST', '/api/withdrawal-request.php', null, $fields + $hidden, [], $headers);
    }

    /** An order in $status, so the endpoint has something real to accept or refuse. */
    private function order(string $status): int
    {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Withdrawal Language',
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
                'first_name' => 'Withdrawal', 'last_name' => 'Language', 'company' => null,
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

    private function withdrawalRequestsFor(int $orderId): int
    {
        $statement = Database::connection()->prepare('SELECT COUNT(*) FROM withdrawal_requests WHERE order_id = :id');
        $statement->execute(['id' => $orderId]);

        return (int) $statement->fetchColumn();
    }

    private function addLanguage(string $code, bool $active): void
    {
        if (SiteLanguages::exists($code)) {
            $this->markTestSkipped('the test database already registers ' . $code);
        }

        (new SiteLanguageRepository())->create($code, strtoupper($code), strtoupper($code), $active);
        $this->addedLanguages[] = $code;
        SiteLanguages::clearCache();
    }

    /** The value of one field of the withdrawal form. */
    private function field(string $html, string $name): string
    {
        $inputs = $this->xpath($html)->query('//form[@action="/api/withdrawal-request.php"]//input[@name="' . $name . '"]');
        self::assertNotFalse($inputs);
        self::assertSame(1, $inputs->length, 'the form has one ' . $name . ' field');

        return $inputs->item(0)->getAttribute('value');
    }

    /** @return array{status: int, location: string, body: string, headers: string} */
    private function get(string $path): array
    {
        return self::$server->request('GET', $path);
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
