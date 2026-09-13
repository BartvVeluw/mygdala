<?php

namespace App\Service;

use App\Repository\OrderRepository;

/**
 * Formats OrderRepository::findForExport() rows into a sales-administration
 * CSV — the webshop's counterpart to Mollie's own payment/payout exports
 * (see MAIN.MD "Export for bookkeeping"). One row per order (not per order
 * line): `items_summary` already condenses the line items into one readable
 * column, which keeps this a clean order-level export rather than a second,
 * more complex line-item export Mollie-style tooling doesn't need.
 *
 * Pure formatting only — no database access, no HTTP output — so header()/
 * row() can be unit-tested directly; admin/orders-export.php only wires this
 * to the repository and to php://output.
 */
class OrderCsvExport
{
    /**
     * @return array<int, string>
     */
    public static function header(): array
    {
        return [
            'Ordernummer',
            'Datum',
            'Klant',
            'E-mail',
            'Land',
            'Producten',
            'Subtotaal',
            'Verzendkosten',
            'Totaal',
            'Valuta',
            'Betaalstatus',
            'Afhandeling',
            'Terugbetaald',
            'Mollie betalings-ID',
        ];
    }

    /**
     * @param array<string, mixed> $order a row from OrderRepository::findForExport()
     * @return array<int, string>
     */
    public static function row(array $order): array
    {
        $total = (float) $order['total'];
        $shipping = (float) $order['shipping_cost'];
        $subtotal = round($total - $shipping, 2);
        $createdAt = new \DateTimeImmutable((string) $order['created_at']);

        return [
            OrderRepository::orderNumber($order),
            $createdAt->format('Y-m-d H:i'),
            (string) $order['customer_name'],
            (string) $order['customer_email'],
            (string) ($order['country'] ?? ''),
            (string) ($order['items_summary'] ?? ''),
            number_format($subtotal, 2, '.', ''),
            number_format($shipping, 2, '.', ''),
            number_format($total, 2, '.', ''),
            (string) $order['currency'],
            (string) $order['status'],
            (string) $order['fulfilment_status'],
            number_format((float) ($order['refunded_amount'] ?? 0.0), 2, '.', ''),
            (string) ($order['mollie_payment_id'] ?? ''),
        ];
    }

    /**
     * Writes a UTF-8 CSV (with BOM, so accented/€ characters open correctly
     * in Excel) to the given stream: header row, then one row per order.
     *
     * @param resource $stream
     * @param array<int, array<string, mixed>> $orders
     */
    public static function stream($stream, array $orders): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::header());
        foreach ($orders as $order) {
            fputcsv($stream, self::row($order));
        }
    }
}
