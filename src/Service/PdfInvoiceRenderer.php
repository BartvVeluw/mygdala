<?php

namespace App\Service;

use App\Repository\OrderRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders the invoice PDF via dompdf (pure-PHP HTML->PDF, no Node/curl/
 * external binaries — works on PHP 8.2 + Vimexx shared hosting, see MAIN.MD
 * "PDF invoice"). A clean, single-page A4 layout, not a full page-builder
 * template — deliberately not overly decorative.
 *
 * Every dynamic value (order/customer/seller data) is escaped via esc()
 * before being placed in the HTML — same discipline as
 * App\Mail\OrderConfirmationBuilder — since seller_snapshot/customer data
 * ultimately originates from admin-entered CMS text.
 *
 * Pure function of its inputs: given the same order/items/sellerSnapshot/
 * invoiceNumber/invoiceDate, always produces the same PDF bytes — this is
 * what makes an invoice PDF reproducible later purely from what's already
 * stored (order snapshot + invoices.seller_snapshot), independent of
 * whatever Site Settings/products look like by then.
 */
class PdfInvoiceRenderer
{
    /**
     * @param array<string, mixed> $order orders row (must include shipping_cost, total, currency, created_at, shipping_/billing_* columns)
     * @param array<string, mixed> $customer customers row (name, email — legacy address fallback)
     * @param array<int, array<string, mixed>> $items rows from OrderRepository::findItems()
     * @param array<string, string> $sellerSnapshot see InvoiceService::buildSellerSnapshot()
     */
    public function render(
        array $order,
        array $customer,
        array $items,
        array $sellerSnapshot,
        string $invoiceNumber,
        \DateTimeInterface $invoiceDate,
        string $orderNumber
    ): string {
        $html = $this->buildHtml($order, $customer, $items, $sellerSnapshot, $invoiceNumber, $invoiceDate, $orderNumber);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(
        array $order,
        array $customer,
        array $items,
        array $sellerSnapshot,
        string $invoiceNumber,
        \DateTimeInterface $invoiceDate,
        string $orderNumber
    ): string {
        $billingAddress = OrderRepository::resolveBillingAddress($order + [
            'address_line' => $customer['address_line'] ?? null,
            'postal_code' => $customer['postal_code'] ?? null,
            'city' => $customer['city'] ?? null,
            'country' => $customer['country'] ?? null,
        ]);

        $sellerLines = array_filter([
            self::esc($sellerSnapshot['company_name']),
            self::esc(trim($sellerSnapshot['street'] . ' ' . $sellerSnapshot['house_number'])),
            self::esc(trim($sellerSnapshot['postal_code'] . ' ' . $sellerSnapshot['city'])),
            self::esc($sellerSnapshot['country']),
        ], static fn (string $l): bool => $l !== '');

        $sellerMeta = [];
        if ($sellerSnapshot['kvk_number'] !== '') {
            $sellerMeta[] = 'KVK ' . self::esc($sellerSnapshot['kvk_number']);
        }
        if ($sellerSnapshot['vat_id'] !== '') {
            $sellerMeta[] = 'BTW-id ' . self::esc($sellerSnapshot['vat_id']);
        }
        if ($sellerSnapshot['email'] !== '') {
            $sellerMeta[] = self::esc($sellerSnapshot['email']);
        }
        if ($sellerSnapshot['phone'] !== '') {
            $sellerMeta[] = self::esc($sellerSnapshot['phone']);
        }
        if ($sellerSnapshot['website'] !== '') {
            $sellerMeta[] = self::esc($sellerSnapshot['website']);
        }

        $billingName = trim(($billingAddress['first_name'] ?? '') . ' ' . ($billingAddress['last_name'] ?? '')) ?: (string) $customer['name'];
        $billingLines = array_filter([
            self::esc($billingName),
            self::esc($billingAddress['company'] ?? ''),
            self::esc($this->formatStreetLine($billingAddress)),
            self::esc(trim(($billingAddress['postal_code'] ?? '') . ' ' . ($billingAddress['city'] ?? ''))),
            self::esc($billingAddress['country'] ?? ''),
        ], static fn (string $l): bool => $l !== '');

        $logoImg = $this->logoDataUri($sellerSnapshot['logo_path']);

        $rows = '';
        $subtotal = 0.0;
        foreach ($items as $item) {
            $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
            $subtotal += $lineTotal;
            $name = self::esc($item['name']);
            if (!empty($item['variant_label'])) {
                $name .= '<br><span class="muted">' . self::esc($item['variant_label']) . '</span>';
            }
            $rows .= '<tr>'
                . '<td>' . $name . '</td>'
                . '<td class="num">' . (int) $item['quantity'] . '</td>'
                . '<td class="num">' . self::euro((float) $item['unit_price']) . '</td>'
                . '<td class="num">' . self::euro($lineTotal) . '</td>'
                . '</tr>';
        }

        $shipping = (float) $order['shipping_cost'];
        $total = (float) $order['total'];

        $footerNote = trim((string) $sellerSnapshot['tax_note']);
        $footerText = trim((string) $sellerSnapshot['footer_text']);
        $paymentNote = trim((string) $sellerSnapshot['payment_note']);

        $notesHtml = '';
        foreach ([$footerNote, $paymentNote] as $note) {
            if ($note !== '') {
                $notesHtml .= '<p class="note">' . nl2br(self::esc($note)) . '</p>';
            }
        }

        $footerHtml = $footerText !== '' ? '<p class="footer-text">' . nl2br(self::esc($footerText)) . '</p>' : '';

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222;margin:0;}'
            . '.sheet{padding:32px 40px;}'
            . '.header{width:100%;overflow:hidden;margin-bottom:24px;}'
            . '.header .logo{float:left;max-height:60px;max-width:220px;}'
            . '.header .title{float:right;text-align:right;}'
            . '.title h1{font-size:22px;margin:0 0 4px;color:#333;}'
            . '.title p{margin:0;font-size:11px;color:#555;}'
            . '.clear{clear:both;}'
            . '.parties{width:100%;margin-bottom:24px;overflow:hidden;}'
            . '.parties .col{float:left;width:48%;}'
            . '.parties .col.to{float:right;text-align:left;}'
            . '.parties h2{font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#888;margin:0 0 6px;}'
            . '.parties p{margin:0;line-height:1.5;}'
            . '.meta{margin:0 0 24px;font-size:11px;color:#555;}'
            . 'table.items{width:100%;border-collapse:collapse;margin-bottom:16px;}'
            . 'table.items th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#888;border-bottom:2px solid #ddd;padding:6px 4px;}'
            . 'table.items td{padding:8px 4px;border-bottom:1px solid #eee;vertical-align:top;}'
            . 'table.items td.num, table.items th.num{text-align:right;}'
            . '.muted{color:#888;font-size:10px;}'
            . 'table.totals{width:260px;float:right;border-collapse:collapse;margin-bottom:24px;}'
            . 'table.totals td{padding:4px 0;}'
            . 'table.totals td.num{text-align:right;}'
            . 'table.totals tr.grand td{font-weight:bold;border-top:2px solid #ddd;padding-top:8px;}'
            . '.note{font-size:10px;color:#555;margin:4px 0;}'
            . '.footer-text{font-size:9px;color:#999;margin-top:32px;border-top:1px solid #eee;padding-top:8px;}'
            . '</style></head><body><div class="sheet">'
            . '<div class="header">'
            . ($logoImg !== null ? '<img class="logo" src="' . $logoImg . '">' : '<div class="logo"><strong>' . self::esc($sellerSnapshot['company_name']) . '</strong></div>')
            . '<div class="title"><h1>Factuur</h1>'
            . '<p>Factuurnummer: <strong>' . self::esc($invoiceNumber) . '</strong></p>'
            . '<p>Factuurdatum: ' . self::esc($invoiceDate->format('d-m-Y')) . '</p>'
            . '<p>Bestelnummer: ' . self::esc($orderNumber) . '</p>'
            . '</div><div class="clear"></div></div>'
            . '<div class="parties">'
            . '<div class="col from"><h2>Van</h2><p>' . implode('<br>', $sellerLines) . '</p>'
            . ($sellerMeta !== [] ? '<p class="muted">' . implode('<br>', $sellerMeta) . '</p>' : '')
            . '</div>'
            . '<div class="col to"><h2>Aan</h2><p>' . implode('<br>', $billingLines) . '</p></div>'
            . '<div class="clear"></div></div>'
            . '<table class="items"><thead><tr>'
            . '<th>Omschrijving</th><th class="num">Aantal</th><th class="num">Prijs</th><th class="num">Totaal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table class="totals">'
            . '<tr><td>Subtotaal</td><td class="num">' . self::euro($subtotal) . '</td></tr>'
            . '<tr><td>Verzendkosten</td><td class="num">' . self::euro($shipping) . '</td></tr>'
            . '<tr class="grand"><td>Totaal</td><td class="num">' . self::euro($total) . '</td></tr>'
            . '</table><div class="clear"></div>'
            . $notesHtml
            . $footerHtml
            . '</div></body></html>';
    }

    private function formatStreetLine(array $address): string
    {
        $street = trim((string) ($address['street'] ?? ''));
        $houseNumber = trim((string) ($address['house_number'] ?? ''));
        if ($houseNumber === '') {
            return $street;
        }
        $addition = trim((string) ($address['house_number_addition'] ?? ''));
        $separator = ($addition !== '' && ctype_digit($addition[0])) ? '-' : '';

        return trim($street . ' ' . $houseNumber . $separator . $addition);
    }

    /**
     * Embeds the logo as a base64 data URI so dompdf never needs filesystem/
     * remote access at render time (isRemoteEnabled is off on purpose).
     * Returns null (silently, logo just omitted) if the file is missing or
     * an unrecognised type — a missing logo must never break invoice
     * generation.
     */
    private function logoDataUri(string $logoPath): ?string
    {
        if ($logoPath === '') {
            return null;
        }

        $absolute = dirname(__DIR__, 2) . '/' . ltrim($logoPath, '/');
        if (!is_file($absolute)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
        if ($mime === null) {
            return null;
        }

        $contents = file_get_contents($absolute);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private static function euro(float $amount): string
    {
        return '€ ' . number_format($amount, 2, ',', '.');
    }

    private static function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
