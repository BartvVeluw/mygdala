<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\OrderCsvExport;
use PHPUnit\Framework\TestCase;

/**
 * Covers App\Service\OrderCsvExport — see MAIN.MD "Export for bookkeeping".
 * Pure formatting, no database/HTTP involved.
 */
final class OrderCsvExportTest extends TestCase
{
    private function sampleOrder(array $overrides = []): array
    {
        return array_merge([
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
            'customer_email' => 'jan@example.com',
            'items_summary' => '2x Sleutelhanger - Acryl',
        ], $overrides);
    }

    public function testRowComputesSubtotalFromTotalMinusShipping(): void
    {
        $row = OrderCsvExport::row($this->sampleOrder());

        $header = OrderCsvExport::header();
        $bySubtotal = array_search('Subtotaal', $header, true);
        $byShipping = array_search('Verzendkosten', $header, true);
        $byTotal = array_search('Totaal', $header, true);

        $this->assertSame('47.45', $row[$bySubtotal]);
        $this->assertSame('4.95', $row[$byShipping]);
        $this->assertSame('52.40', $row[$byTotal]);
    }

    public function testRowIncludesOrderNumberDateAndMollieId(): void
    {
        $row = OrderCsvExport::row($this->sampleOrder());
        $header = OrderCsvExport::header();

        $this->assertSame('VLD-2026-000127', $row[array_search('Ordernummer', $header, true)]);
        $this->assertSame('2026-03-14 10:30', $row[array_search('Datum', $header, true)]);
        $this->assertSame('tr_abc123', $row[array_search('Mollie betalings-ID', $header, true)]);
    }

    public function testRefundedAmountIsPreservedSeparatelyFromTotal(): void
    {
        $row = OrderCsvExport::row($this->sampleOrder(['refunded_amount' => '10.00']));
        $header = OrderCsvExport::header();

        $this->assertSame('10.00', $row[array_search('Terugbetaald', $header, true)]);
        // The original total must stay intact alongside the refund amount.
        $this->assertSame('52.40', $row[array_search('Totaal', $header, true)]);
    }

    public function testMissingMollieIdAndItemsSummaryDoNotCrash(): void
    {
        $row = OrderCsvExport::row($this->sampleOrder(['mollie_payment_id' => null, 'items_summary' => null]));
        $header = OrderCsvExport::header();

        $this->assertSame('', $row[array_search('Mollie betalings-ID', $header, true)]);
        $this->assertSame('', $row[array_search('Producten', $header, true)]);
    }

    public function testHeaderAndRowHaveTheSameColumnCount(): void
    {
        $row = OrderCsvExport::row($this->sampleOrder());

        $this->assertCount(count(OrderCsvExport::header()), $row);
    }
}
