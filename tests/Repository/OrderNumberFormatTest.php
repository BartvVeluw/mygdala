<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Mail\OrderConfirmationBuilder;
use App\Repository\OrderRepository;
use App\Service\MolliePaymentData;
use App\Service\OrderCsvExport;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The two halves of an order's public number, without a database:
 *
 *   OrderRepository::formatOrderNumber()  the shape a NEW order's number is
 *                                         made in, from the prefix it is given
 *   OrderRepository::orderNumber()        what an existing order shows: the
 *                                         number stored on it, never one
 *                                         rebuilt from today's settings
 *
 * The prefix used to be a literal "VLD-". A new installation now gets the
 * generic "ORD", and an installation that issued "VLD-" numbers was pinned to
 * "VLD" by 20260913100000 before 20260913120000 stored every existing number,
 * which is only right if that prefix reproduces the old numbers character for
 * character, so that is proven here too.
 *
 * Every test sets the prefix setting through SiteSettings::overrideForTests()
 * to something neither half may use, to prove that neither reads it.
 */
final class OrderNumberFormatTest extends TestCase
{
    /** Stored on an order with id 5, created in 2027: no id, year or prefix here produces it. */
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

    /* ------------------------------------------------------------------ */
    /* Making a new number                                                 */
    /* ------------------------------------------------------------------ */

    public function testTheGenericDefaultPrefixMakesTheGenericNumber(): void
    {
        $number = OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), SiteSettings::defaults()['order_number_prefix']);

        $this->assertSame('ORD-2026-000127', $number);
    }

    public function testThePrefixItIsGivenIsUsedAndTheSettingIsNot(): void
    {
        $this->assertSame('ABC-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), 'ABC'));
    }

    public function testThePinnedLegacyPrefixReproducesTheHistoricalNumbersExactly(): void
    {
        // The two numbers this test asserted while the prefix was hardcoded.
        $this->assertSame('VLD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), 'VLD'));
        $this->assertSame('VLD-2027-1234567', OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01'), 'VLD'));
    }

    public function testTheFormatterOwnsTheSeparators(): void
    {
        foreach ([
            'ORD-' => 'ORD-2026-000127',
            ' SHOP ' => 'SHOP-2026-000127',
            'A-B/C' => 'ABC-2026-000127',
        ] as $given => $expected) {
            $this->assertSame($expected, OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), $given), $given);
        }
    }

    public function testAPrefixWithNothingUsableLeftFallsBackToTheGenericDefault(): void
    {
        foreach (['', '---', '<>'] as $given) {
            $this->assertSame('ORD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), $given), $given);
        }
    }

    public function testFormatIsStableForTheSameIdAndYear(): void
    {
        $a = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-01-01 00:00:01'), 'ORD');
        $b = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-12-31 23:59:59'), 'ORD');

        $this->assertSame($a, $b);
    }

    public function testDifferentOrdersNeverProduceTheSameNumber(): void
    {
        $a = OrderRepository::formatOrderNumber(1, new \DateTimeImmutable('2026-01-01'), 'ORD');
        $b = OrderRepository::formatOrderNumber(2, new \DateTimeImmutable('2026-01-01'), 'ORD');

        $this->assertNotSame($a, $b);
    }

    public function testLargeIdIsNotTruncated(): void
    {
        $this->assertSame('ORD-2027-1234567', OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01'), 'ORD'));
    }

    /* ------------------------------------------------------------------ */
    /* Showing an existing number                                          */
    /* ------------------------------------------------------------------ */

    public function testAnOrderShowsTheNumberStoredOnIt(): void
    {
        $this->assertSame(self::STORED_NUMBER, OrderRepository::orderNumber($this->order()));
    }

    /**
     * A row without a stored number should not exist. If one turns up, it
     * shows its technical id and the log says so; it is never handed a
     * number rebuilt from the current prefix, which would look real.
     */
    public function testAnOrderWithoutAStoredNumberShowsItsIdAndLogsIt(): void
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'order-number');
        $this->previousErrorLog = (string) ini_set('error_log', $log);

        $this->assertSame('#5', OrderRepository::orderNumber($this->order(['order_number' => null])));
        $this->assertSame('#5', OrderRepository::orderNumber($this->order(['order_number' => ''])));

        $order = $this->order();
        unset($order['order_number']);
        $this->assertSame('#5', OrderRepository::orderNumber($order));

        $this->assertStringContainsString('Order 5 has no stored order_number', (string) file_get_contents($log));
    }

    /**
     * One stored number, several readers. The customer and shop e-mails, the
     * bookkeeping export and the Mollie payment are pure and rendered here;
     * the invoice PDF is proven in Tests\Service\InvoiceServiceTest and the
     * read models in Tests\Repository\OrderNumberSnapshotIntegrationTest.
     */
    public function testTheMailTheExportAndTheMolliePaymentShowTheSameStoredNumber(): void
    {
        $order = $this->order();
        $customer = [
            'name' => 'Jan Jansen',
            'email' => 'jan@example.invalid',
            'phone' => null,
            'address_line' => null,
            'postal_code' => null,
            'city' => null,
            'country' => null,
        ];
        $items = [['name' => 'Testproduct', 'name_en' => null, 'variant_label' => null, 'quantity' => 1, 'unit_price' => '47.45']];

        $emails = OrderConfirmationBuilder::build($order, $customer, $items, SiteSettings::all());
        $this->assertStringContainsString(self::STORED_NUMBER, $emails['customer']['subject']);
        $this->assertStringContainsString(self::STORED_NUMBER, $emails['customer']['text']);
        $this->assertStringContainsString(self::STORED_NUMBER, $emails['shop']['subject']);

        $row = OrderCsvExport::row($order);
        $this->assertSame(self::STORED_NUMBER, $row[array_search('Ordernummer', OrderCsvExport::header(), true)]);

        $payment = MolliePaymentData::forOrder($order, 'Testwinkel', 'https://shop.example', 'ideal', true);
        $this->assertStringEndsWith(self::STORED_NUMBER, $payment['description']);
        $this->assertSame(self::STORED_NUMBER, $payment['metadata']['order_number']);
    }

    /** @return array<string, mixed> */
    private function order(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'order_number' => self::STORED_NUMBER,
            'created_at' => '2027-01-02 10:30:00',
            'total' => '52.40',
            'shipping_cost' => '4.95',
            'refunded_amount' => '0.00',
            'status' => 'paid',
            'fulfilment_status' => 'Open',
            'mollie_payment_id' => 'tr_abc123',
            'currency' => 'EUR',
            'country' => 'NL',
            'customer_name' => 'Jan Jansen',
            'customer_email' => 'jan@example.invalid',
            'items_summary' => '1x Testproduct',
            'shipping_method' => 'verzenden',
            'billing_same_as_shipping' => 1,
            'shipping_first_name' => 'Jan',
            'shipping_last_name' => 'Jansen',
            'shipping_company' => null,
            'shipping_country' => 'NL',
            'shipping_postal_code' => '1234AB',
            'shipping_house_number' => '1',
            'shipping_house_number_addition' => null,
            'shipping_street' => 'Teststraat',
            'shipping_city' => 'Teststad',
        ], $overrides);
    }
}
