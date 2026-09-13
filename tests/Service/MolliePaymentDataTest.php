<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\MolliePaymentData;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Service\MolliePaymentData: the payment api/checkout.php creates.
 * No database and no Mollie.
 *
 * The prefix setting says "SHOP" in every test, and the fixture order is
 * id 5, created in 2027, with "VLD-2026-000127" stored on it. No id, year or
 * prefix here could produce that number, so a description or metadata that
 * carries it can only have read it from the order.
 */
final class MolliePaymentDataTest extends TestCase
{
    private const STORED_NUMBER = 'VLD-2026-000127';

    private ?string $previousErrorLog = null;

    protected function setUp(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        if ($this->previousErrorLog !== null) {
            ini_set('error_log', $this->previousErrorLog);
            $this->previousErrorLog = null;
        }
    }

    /** @return array<string, mixed> */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'order_number' => self::STORED_NUMBER,
            'created_at' => '2027-01-02 10:30:00',
            'total' => '54.90',
            'currency' => 'EUR',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function payment(array $order, bool $withWebhook = true): array
    {
        return MolliePaymentData::forOrder($order, 'Testwinkel', 'https://shop.example', 'ideal', $withWebhook);
    }

    public function testTheDescriptionCarriesTheStoredOrderNumber(): void
    {
        $payment = $this->payment($this->order());

        $this->assertSame('Testwinkel — bestelling ' . self::STORED_NUMBER, $payment['description']);
        $this->assertStringNotContainsString('SHOP', $payment['description']);
    }

    public function testTheMetadataCarriesTheStoredOrderNumberAndTheOrderId(): void
    {
        $payment = $this->payment($this->order());

        $this->assertSame(['order_id' => 5, 'order_number' => self::STORED_NUMBER], $payment['metadata']);
    }

    public function testTheAmountAndReturnAddressComeFromTheOrder(): void
    {
        $payment = $this->payment($this->order());

        $this->assertSame(['currency' => 'EUR', 'value' => '54.90'], $payment['amount']);
        $this->assertSame('https://shop.example/bestelling-status.php?order=5', $payment['redirectUrl']);
        $this->assertSame('ideal', $payment['method']);
    }

    public function testTheWebhookIsOnlySentWhereMollieCanReachIt(): void
    {
        $this->assertSame('https://shop.example/api/mollie-webhook.php', $this->payment($this->order())['webhookUrl']);
        $this->assertArrayNotHasKey('webhookUrl', $this->payment($this->order(), false));
    }

    /**
     * A row without a stored number should not exist. If one does, the
     * payment carries the technical "#<id>", never a number rebuilt from the
     * current prefix that would look real and be wrong.
     */
    public function testAnOrderWithoutAStoredNumberIsNeverGivenOneFromTheCurrentPrefix(): void
    {
        $this->previousErrorLog = (string) ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'mollie-payment-data'));

        $payment = $this->payment($this->order(['order_number' => null]));

        $this->assertSame('Testwinkel — bestelling #5', $payment['description']);
        $this->assertSame(['order_id' => 5, 'order_number' => '#5'], $payment['metadata']);
    }
}
