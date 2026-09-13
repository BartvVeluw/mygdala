<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Mail\OrderConfirmationBuilder;
use App\Repository\OrderRepository;
use App\Service\OrderCsvExport;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Covers OrderRepository::formatOrderNumber() — see MAIN.MD "Order
 * numbering". No database needed: it's a pure function of (id, created_at)
 * and the `order_number_prefix` setting, which every test here pins through
 * App\Service\SiteSettings::overrideForTests() rather than reading storage.
 *
 * The prefix used to be a literal "VLD-". A new installation now gets the
 * generic "ORD", and an installation that issued "VLD-" numbers was pinned to
 * "VLD" by migration 20260913100000 — which is only enough if that stored
 * prefix reproduces the old numbers character for character, so that is
 * proven here too.
 */
final class OrderNumberFormatTest extends TestCase
{
    protected function setUp(): void
    {
        SiteSettings::overrideForTests([]);
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
    }

    public function testANewInstallationNumbersItsOrdersWithTheGenericPrefix(): void
    {
        $number = OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'));

        $this->assertSame('ORD-2026-000127', $number);
    }

    public function testAConfiguredPrefixIsUsed(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);

        $this->assertSame('SHOP-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14')));
    }

    public function testThePinnedLegacyPrefixReproducesTheHistoricalNumbersExactly(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'VLD']);

        // The two numbers this test asserted while the prefix was hardcoded.
        $this->assertSame('VLD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14')));
        $this->assertSame('VLD-2027-1234567', OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01')));
    }

    public function testTheFormatterOwnsTheSeparators(): void
    {
        foreach ([
            'ORD-' => 'ORD-2026-000127',
            ' SHOP ' => 'SHOP-2026-000127',
            'A-B/C' => 'ABC-2026-000127',
        ] as $stored => $expected) {
            SiteSettings::overrideForTests(['order_number_prefix' => $stored]);

            $this->assertSame($expected, OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14')), $stored);
        }
    }

    public function testAPrefixWithNothingUsableLeftFallsBackToTheGenericDefault(): void
    {
        foreach (['', '---', '<>'] as $stored) {
            SiteSettings::overrideForTests(['order_number_prefix' => $stored]);

            $this->assertSame('ORD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14')), $stored);
        }
    }

    public function testAnExplicitPrefixWinsOverTheSetting(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);

        $this->assertSame('VLD-2026-000127', OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'), 'VLD'));
    }

    public function testFormatIsStableForTheSameIdAndYear(): void
    {
        $a = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-01-01 00:00:01'));
        $b = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-12-31 23:59:59'));

        $this->assertSame($a, $b);
    }

    public function testDifferentOrdersNeverProduceTheSameNumber(): void
    {
        $a = OrderRepository::formatOrderNumber(1, new \DateTimeImmutable('2026-01-01'));
        $b = OrderRepository::formatOrderNumber(2, new \DateTimeImmutable('2026-01-01'));

        $this->assertNotSame($a, $b);
    }

    public function testLargeIdIsNotTruncated(): void
    {
        $number = OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01'));

        $this->assertSame('ORD-2027-1234567', $number);
    }

    /**
     * One contract, several readers. The customer e-mail and the bookkeeping
     * export are pure and can be rendered here; the Mollie description and
     * the invoice are proven at their source by
     * Tests\Install\GenericDistributionTest and by
     * Tests\Service\PdfInvoiceRendererTest.
     */
    public function testTheMailAndTheExportShowTheSameNumberForTheSameOrder(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);

        $order = [
            'id' => 127,
            'created_at' => '2026-03-14 10:30:00',
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
        ];
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

        $expected = 'SHOP-2026-000127';

        $emails = OrderConfirmationBuilder::build($order, $customer, $items);
        $this->assertStringContainsString($expected, $emails['customer']['subject']);
        $this->assertStringContainsString($expected, $emails['customer']['text']);
        $this->assertStringContainsString($expected, $emails['shop']['subject']);

        $row = OrderCsvExport::row($order);
        $this->assertSame($expected, $row[array_search('Ordernummer', OrderCsvExport::header(), true)]);
    }
}
