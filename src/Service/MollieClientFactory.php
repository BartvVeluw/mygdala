<?php

declare(strict_types=1);

namespace App\Service;

use Mollie\Api\MollieApiClient;

/**
 * A Mollie API client for one API key. App\Service\Payment\MolliePaymentProvider
 * is the only caller: it decides WHICH key (App\Service\Payment\MollieConfiguration),
 * this only builds the client.
 *
 * A new client per key rather than one shared instance, because the key is
 * no longer one fixed value: the connection test tries a key before it is
 * saved, and a payment made in the other mode is looked up with the other
 * key. The API address is Mollie's own and not configurable anywhere, so
 * nothing an administrator types can point a request at another host.
 *
 * TEST SEAM. useFactoryForTests() hands out a client of a test's choosing
 * (the SDK's own Mollie\Api\Fake\MockMollieClient), so no test ever reaches
 * api.mollie.com. It is a static method and nothing else: no environment
 * variable or setting can switch it on, so a deployed site cannot be talked
 * into a fake Mollie. The HTTP tests call it from a file PHP prepends to
 * their own built-in server (tests/Support/fake-mollie.php).
 */
final class MollieClientFactory
{
    /** @var (\Closure(string): MollieApiClient)|null */
    private static ?\Closure $factoryForTests = null;

    /**
     * @throws \Mollie\Api\Exceptions\InvalidAuthenticationException when $apiKey
     *         does not have the shape of a key; its message does not repeat the key
     */
    public static function forKey(#[\SensitiveParameter] string $apiKey): MollieApiClient
    {
        if (self::$factoryForTests !== null) {
            return (self::$factoryForTests)($apiKey);
        }

        $client = new MollieApiClient();
        $client->setApiKey($apiKey);

        return $client;
    }

    /**
     * @param (\Closure(string): MollieApiClient)|null $factory null goes back to the real client
     */
    public static function useFactoryForTests(?\Closure $factory): void
    {
        self::$factoryForTests = $factory;
    }
}
