<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Service\MollieClientFactory;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\DynamicGetRequest;
use Mollie\Api\Http\Requests\GetEnabledMethodsRequest;
use Mollie\Api\Http\Requests\GetPaginatedPaymentRefundsRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Mollie\Api\MollieApiClient;

/**
 * Test support, never part of the application: a Mollie that lives in a JSON
 * file, so the real App\Service\Payment\MolliePaymentProvider (and every
 * endpoint above it) runs against Mollie's own SDK — its request classes,
 * response parsing and exceptions — without a network or an account.
 *
 * It is switched on through the one test seam there is,
 * MollieClientFactory::useFactoryForTests(): directly in a PHPUnit process
 * (install()), or in a built-in server through tests/Support/fake-mollie.php,
 * which PHP prepends to every request. Nothing in the application can switch
 * it on.
 *
 * THE SCENARIO FILE, read afresh on every request so a test can change it
 * between two of them:
 *
 *   keys      { "<API key>": "ok" | "unauthorized" | "forbidden" | "down" | "error" }
 *             a key that is not listed is unauthorized, as at Mollie
 *   methods   { "test": [ {"id": "ideal", "description": "iDEAL"} ], "live": [...] }
 *             a description may be { "nl_NL": "…", "en_US": "…" }
 *   payments  { "tr_…": { "mode": "test", "status": "paid", "paidAt": "…",
 *                         "refunds": [ {"id": "re_…", "amount": "5.00", "status": "refunded"} ] } }
 *             a payment is only found with a key of its own mode, as at Mollie
 *   created   { "id": "tr_…", "checkoutUrl": "https://…" } for payments->create
 *
 * Every request is appended to "<scenario>.log" as one JSON line: the
 * request class, the key's MODE and last four characters (never the key),
 * the path, and the body of a create.
 */
final class FakeMollie
{
    public static function install(string $scenarioFile): void
    {
        MollieClientFactory::useFactoryForTests(static fn (string $key): MollieApiClient => self::client($key, $scenarioFile));
    }

    public static function uninstall(): void
    {
        MollieClientFactory::useFactoryForTests(null);
    }

    /**
     * @param array<string, mixed> $scenario
     */
    public static function write(string $scenarioFile, array $scenario): void
    {
        file_put_contents($scenarioFile, json_encode($scenario, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function requests(string $scenarioFile): array
    {
        $log = $scenarioFile . '.log';
        if (!is_file($log)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($log)));

        return array_values(array_map(static fn (string $line): array => (array) json_decode($line, true), $lines));
    }

    public static function client(string $key, string $scenarioFile): MollieApiClient
    {
        // Every request class answers as often as it is asked (a retry after
        // a network error included), each time from the scenario as it is
        // then. The SDK's own mock forgets a class after one answer.
        $answer = static fn (PendingRequest $request): MockResponse => self::answer($request, $key, $scenarioFile);
        $expected = [];
        foreach ([GetEnabledMethodsRequest::class, GetPaymentRequest::class, CreatePaymentRequest::class, GetPaginatedPaymentRefundsRequest::class, DynamicGetRequest::class] as $class) {
            $expected[$class] = new SequenceMockResponse(...array_fill(0, 10, $answer));
        }
        $client = new MockMollieClient($expected);

        // A fake answers at once; retrying it only makes a test slow.
        $client->setRetryStrategy(new LinearRetryStrategy(0, 0));
        // The real client refuses a key of the wrong shape here, before any
        // request; so does the fake.
        $client->setApiKey($key);

        return $client;
    }

    private static function answer(PendingRequest $request, string $key, string $scenarioFile): MockResponse
    {
        $scenario = is_file($scenarioFile) ? (array) json_decode((string) file_get_contents($scenarioFile), true) : [];
        $class = (new \ReflectionClass($request->getRequest()))->getShortName();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url((string) $request->createPsrRequest()->getUri(), PHP_URL_QUERY), $query);
        $mode = str_starts_with($key, 'live_') ? 'live' : 'test';

        $entry = ['request' => $class, 'mode' => $mode, 'key_end' => substr($key, -4), 'path' => $path];
        if ($class === 'CreatePaymentRequest') {
            $entry['body'] = json_decode((string) $request->createPsrRequest()->getBody(), true);
        }
        file_put_contents($scenarioFile . '.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        $state = (string) (($scenario['keys'] ?? [])[$key] ?? 'unauthorized');
        switch ($state) {
            case 'ok':
                break;
            case 'down':
                throw new NetworkRequestException($request, null, 'Could not resolve host: api.mollie.com');
            case 'error':
                return MockResponse::error(503, 'Service Unavailable', 'The service is temporarily unavailable.');
            case 'forbidden':
                return MockResponse::error(403, 'Forbidden', 'This key may not do that.');
            default:
                return MockResponse::error(401, 'Unauthorized Request', 'Missing authentication, or failed to authenticate');
        }

        return match ($class) {
            'GetEnabledMethodsRequest' => self::methods($scenario, $mode, (string) ($query['locale'] ?? 'en_US')),
            'GetPaymentRequest' => self::payment($scenario, $mode, basename($path)),
            // Payment::refunds() follows the payment's own refunds link.
            'GetPaginatedPaymentRefundsRequest', 'DynamicGetRequest' => self::refunds($scenario, basename(dirname($path))),
            'CreatePaymentRequest' => self::created($scenario, $mode),
        };
    }

    /** @param array<string, mixed> $scenario */
    private static function methods(array $scenario, string $mode, string $locale): MockResponse
    {
        $methods = [];
        foreach ((array) (($scenario['methods'] ?? [])[$mode] ?? []) as $method) {
            $description = $method['description'] ?? $method['id'];
            if (is_array($description)) {
                $description = $description[$locale] ?? reset($description);
            }
            $methods[] = ['resource' => 'method', 'id' => $method['id'], 'description' => $description, 'status' => 'activated'];
        }

        return MockResponse::ok(['count' => count($methods), '_embedded' => ['methods' => $methods], '_links' => ['self' => ['href' => 'https://api.mollie.com/v2/methods', 'type' => 'application/hal+json']]]);
    }

    /** @param array<string, mixed> $scenario */
    private static function payment(array $scenario, string $mode, string $id): MockResponse
    {
        $payment = ($scenario['payments'] ?? [])[$id] ?? null;
        if (!is_array($payment) || ($payment['mode'] ?? 'test') !== $mode) {
            return MockResponse::notFound('No payment exists with token ' . $id . '.');
        }

        $refunds = (array) ($payment['refunds'] ?? []);
        $refunded = array_sum(array_map(static fn (array $refund): float => (float) $refund['amount'], $refunds));
        $links = ['self' => ['href' => 'https://api.mollie.com/v2/payments/' . $id, 'type' => 'application/hal+json']];
        if ($refunds !== []) {
            $links['refunds'] = ['href' => 'https://api.mollie.com/v2/payments/' . $id . '/refunds', 'type' => 'application/hal+json'];
        }

        return MockResponse::ok([
            'resource' => 'payment',
            'id' => $id,
            'mode' => $mode,
            'status' => $payment['status'] ?? 'open',
            'paidAt' => $payment['paidAt'] ?? null,
            'amount' => ['value' => '10.00', 'currency' => 'EUR'],
            'amountRefunded' => ['value' => number_format($refunded, 2, '.', ''), 'currency' => 'EUR'],
            '_links' => $links,
        ]);
    }

    /** @param array<string, mixed> $scenario */
    private static function refunds(array $scenario, string $paymentId): MockResponse
    {
        $refunds = [];
        foreach ((array) ((($scenario['payments'] ?? [])[$paymentId] ?? [])['refunds'] ?? []) as $refund) {
            $refunds[] = [
                'resource' => 'refund',
                'id' => $refund['id'],
                'amount' => ['value' => $refund['amount'], 'currency' => 'EUR'],
                'status' => $refund['status'] ?? 'refunded',
                'description' => $refund['description'] ?? 'Refund',
                'createdAt' => $refund['createdAt'] ?? '2026-09-27T10:00:00+00:00',
                'paymentId' => $paymentId,
            ];
        }

        return MockResponse::ok(['count' => count($refunds), '_embedded' => ['refunds' => $refunds], '_links' => ['next' => null]]);
    }

    /** @param array<string, mixed> $scenario */
    private static function created(array $scenario, string $mode): MockResponse
    {
        $created = (array) ($scenario['created'] ?? []);
        $id = (string) ($created['id'] ?? 'tr_fake' . bin2hex(random_bytes(4)));

        return MockResponse::created([
            'resource' => 'payment',
            'id' => $id,
            'mode' => $mode,
            'status' => 'open',
            'amount' => ['value' => '10.00', 'currency' => 'EUR'],
            '_links' => [
                'checkout' => ['href' => (string) ($created['checkoutUrl'] ?? 'https://www.mollie.com/checkout/select-method/' . $id), 'type' => 'text/html'],
            ],
        ]);
    }
}
