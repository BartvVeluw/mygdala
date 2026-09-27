<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\AppUrl;
use App\Service\MollieClientFactory;
use App\Service\MolliePaymentData;
use App\Service\SiteSettings;
use Mollie\Api\Exceptions\ForbiddenException;
use Mollie\Api\Exceptions\InvalidAuthenticationException;
use Mollie\Api\Exceptions\NetworkRequestException;
use Mollie\Api\Exceptions\NotFoundException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\Exceptions\RequestTimeoutException;
use Mollie\Api\Exceptions\TooManyRequestsException;
use Mollie\Api\Exceptions\UnauthorizedException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

/**
 * Mollie behind App\Service\Payment\PaymentProvider: the only class outside
 * the Betalingen screen that calls Mollie's SDK.
 *
 * WHAT IT DOES FOR THE CONTRACT
 *
 *   createPayment()     payments->create with the body MolliePaymentData
 *                       builds from the stored order; the customer comes
 *                       back to, and Mollie reports to, the configured base
 *                       URL (App\Service\AppUrl) and never the Host header of
 *                       the request that happened to start the checkout
 *   fetchPayment()      payments->get with the active key
 *   availableMethods()  methods->allEnabled: what Mollie has switched on for
 *                       the website profile of the active key; read-only
 *
 * Plus checkKey(), the Betalingen screen's connection test: the same
 * read-only call for a key of its choosing, so a key is proven without a
 * payment, an order or a webhook.
 *
 * EVERY FAILURE BECOMES A PaymentProviderException of one kind
 * (translate()): 401/403 invalid credentials, 404 not found, a network error,
 * a timeout, 429 or 5xx temporary, any other 4xx rejected, and a key of the
 * wrong shape not configured. Its message is written here and never contains
 * the key.
 *
 * THE WEBHOOK is sent with each payment, so nobody sets it up in Mollie's
 * dashboard. Not for a base URL Mollie cannot reach (localhost, *.test, a
 * private address): Mollie refuses such a payment outright, so there the
 * return page's own status check (api/order-status.php) is the only sync,
 * as it always was locally.
 */
final class MolliePaymentProvider implements PaymentProvider
{
    private MollieConfiguration $configuration;

    /**
     * @param string|null $baseUrl  a test's own; null is AppUrl::base()
     * @param string|null $siteName a test's own; null is the site_name setting
     */
    public function __construct(
        ?MollieConfiguration $configuration = null,
        private readonly ?string $baseUrl = null,
        private readonly ?string $siteName = null,
    ) {
        $this->configuration = $configuration ?? new MollieConfiguration();
    }

    public function isConfigured(): bool
    {
        try {
            $this->configuration->activeKey();
        } catch (PaymentProviderException) {
            return false;
        }

        return true;
    }

    public function createPayment(PaymentRequest $request): CreatedPayment
    {
        $client = $this->client($this->configuration->activeKey());
        $baseUrl = $this->baseUrl();

        try {
            $payment = $client->payments->create(MolliePaymentData::forOrder(
                $request->order,
                $this->siteName ?? SiteSettings::get('site_name'),
                $baseUrl,
                $request->method,
                self::acceptsWebhooks($baseUrl),
                $request->language
            ));
        } catch (\Throwable $e) {
            throw self::translate($e);
        }

        $checkoutUrl = (string) $payment->getCheckoutUrl();
        if ($checkoutUrl === '') {
            throw new PaymentProviderException(PaymentProviderException::REJECTED, 'Mollie created payment ' . $payment->id . ' without a checkout URL');
        }

        return new CreatedPayment((string) $payment->id, $checkoutUrl);
    }

    public function fetchPayment(string $paymentId): PaymentSnapshot
    {
        $key = $this->configuration->activeKey();

        try {
            return self::snapshot($this->client($key)->payments->get($paymentId));
        } catch (\Throwable $e) {
            throw self::translate($e);
        }
    }

    public function availableMethods(string $language): array
    {
        return $this->methodsFor($this->configuration->activeKey(), $language);
    }

    /**
     * The connection test: whether Mollie accepts $key, proven with the same
     * read-only call availableMethods() makes. Nothing is created, changed
     * or paid, and no webhook fires.
     *
     * @return list<PaymentMethodOption> what Mollie offers for that key
     *
     * @throws PaymentProviderException NOT_CONFIGURED for a key of the wrong shape, before any request
     */
    public function checkKey(string $key, string $language): array
    {
        if (!MollieConfiguration::isKeyFormat($key)) {
            throw new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, 'the key does not have the shape of a Mollie API key');
        }

        return $this->methodsFor($key, $language);
    }

    /**
     * Where Mollie reports a payment's changes, or null when the base URL is
     * one it cannot reach (acceptsWebhooks()). What the Betalingen screen
     * shows is exactly what every payment carries.
     */
    public function webhookUrl(): ?string
    {
        $baseUrl = $this->baseUrl();

        return self::acceptsWebhooks($baseUrl) ? MolliePaymentData::webhookUrl($baseUrl) : null;
    }

    /**
     * Whether Mollie can call back to $baseUrl: false for localhost and
     * *.localhost, the reserved test names (.test, .local, .internal,
     * .invalid), a name without a dot, and a private or reserved IP address.
     * Mollie refuses a payment whose webhook points at one of those.
     */
    public static function acceptsWebhooks(string $baseUrl): bool
    {
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if ($host === 'localhost' || !str_contains($host, '.')) {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.internal', '.invalid'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mollie's payment status in the Shop's five words. `paid` only when
     * Mollie says the money arrived; open, pending and authorized all stay
     * pending, so nothing but Mollie itself can make an order paid.
     */
    public static function mapStatus(Payment $payment): string
    {
        if ($payment->isPaid()) {
            return PaymentSnapshot::PAID;
        }
        if ($payment->isCanceled()) {
            return PaymentSnapshot::CANCELED;
        }
        if ($payment->isExpired()) {
            return PaymentSnapshot::EXPIRED;
        }
        if ($payment->isFailed()) {
            return PaymentSnapshot::FAILED;
        }

        // open, pending, authorized, ...
        return PaymentSnapshot::PENDING;
    }

    /**
     * A Mollie payment as the provider-neutral snapshot OrderPaymentSync
     * applies. The refunds stay a request for later (PaymentSnapshot).
     */
    public static function snapshot(Payment $payment): PaymentSnapshot
    {
        return new PaymentSnapshot(
            (string) $payment->id,
            self::mapStatus($payment),
            (string) $payment->status,
            $payment->hasRefunds(),
            $payment->getAmountRefunded(),
            static function () use ($payment): array {
                try {
                    $refunds = [];
                    foreach ($payment->refunds() as $refund) {
                        $refunds[] = new PaymentRefund(
                            (string) $refund->id,
                            (float) $refund->amount->value,
                            (string) $refund->status,
                            $refund->description !== null ? (string) $refund->description : null,
                            new \DateTimeImmutable((string) $refund->createdAt)
                        );
                    }

                    return $refunds;
                } catch (\Throwable $e) {
                    throw self::translate($e);
                }
            }
        );
    }

    /**
     * Any failure around a Mollie call as a PaymentProviderException of one
     * kind (see the class docblock). The detail names the SDK class, the HTTP
     * status and Mollie's own short explanation, redacted of anything shaped
     * like a key.
     */
    public static function translate(\Throwable $e): PaymentProviderException
    {
        if ($e instanceof PaymentProviderException) {
            return $e;
        }

        $detail = (new \ReflectionClass($e))->getShortName();

        if ($e instanceof InvalidAuthenticationException) {
            // Its default message would repeat the key; this one never does.
            return new PaymentProviderException(PaymentProviderException::NOT_CONFIGURED, $detail . ': the key does not have the shape of a Mollie API key');
        }

        if ($e instanceof RequestException) {
            $status = $e->getStatusCode();
            $detail .= ' ' . $status;
            if (method_exists($e, 'getPlainMessage')) {
                $detail .= ': ' . $e->getPlainMessage();
            }

            return match (true) {
                $e instanceof UnauthorizedException, $e instanceof ForbiddenException => new PaymentProviderException(PaymentProviderException::INVALID_CREDENTIALS, $detail),
                $e instanceof NotFoundException => new PaymentProviderException(PaymentProviderException::NOT_FOUND, $detail),
                $e instanceof RequestTimeoutException, $e instanceof TooManyRequestsException, $status >= 500 => new PaymentProviderException(PaymentProviderException::TEMPORARY, $detail),
                default => new PaymentProviderException(PaymentProviderException::REJECTED, $detail),
            };
        }

        if ($e instanceof NetworkRequestException) {
            return new PaymentProviderException(PaymentProviderException::TEMPORARY, $detail . ': ' . $e->getPlainMessage());
        }

        // Anything unforeseen — a response that was not JSON, a bug — is
        // treated as passing: the webhook asks Mollie to deliver again rather
        // than dropping a payment for good.
        return new PaymentProviderException(PaymentProviderException::TEMPORARY, $detail . ': ' . $e->getMessage());
    }

    /**
     * @return list<PaymentMethodOption>
     */
    private function methodsFor(string $key, string $language): array
    {
        try {
            $methods = $this->client($key)->methods->allEnabled(['locale' => self::locale($language)]);

            $options = [];
            foreach ($methods as $method) {
                $id = (string) $method->id;
                $name = trim((string) ($method->description ?? ''));
                $options[] = new PaymentMethodOption($id, $name !== '' ? $name : $id);
            }

            return $options;
        } catch (\Throwable $e) {
            throw self::translate($e);
        }
    }

    private function client(string $key): MollieApiClient
    {
        try {
            return MollieClientFactory::forKey($key);
        } catch (\Throwable $e) {
            throw self::translate($e);
        }
    }

    private function baseUrl(): string
    {
        return $this->baseUrl ?? AppUrl::base();
    }

    /**
     * The Mollie locale a method's name is asked in, for a website language
     * code. Mollie names its methods in a fixed set of locales; a language
     * outside it gets the English names.
     */
    private static function locale(string $language): string
    {
        return match (strtolower($language)) {
            'nl' => 'nl_NL',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'da' => 'da_DK',
            'sv' => 'sv_SE',
            'nb', 'no' => 'nb_NO',
            'fi' => 'fi_FI',
            'pl' => 'pl_PL',
            default => 'en_US',
        };
    }
}
