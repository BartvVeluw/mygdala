<?php

namespace App\Mail;

use App\Repository\OrderRepository;
use App\Service\Shipping\ShippingProfile;
use App\Service\SiteSettings;

/**
 * Builds the subject/HTML/plain-text content for the two paid-order emails
 * (customer confirmation + shop notification). Pure content generation only —
 * no sending, no database access, no idempotency logic (that's
 * App\Service\OrderConfirmationService). Only uses data actually passed in
 * (order row, customer row, order items, CMS email settings) — never invents
 * order/customer data.
 *
 * The *customer* email's subject/heading/intro/closing/signature come from
 * `$emailSettings` (App\Service\SiteSettings `order_email_*` keys — plain
 * text, {{placeholder}}-substituted via App\Mail\EmailPlaceholders, never
 * raw HTML) so they're CMS-editable — see MAIN.MD "CMS-editable email
 * template". Everything commercially load-bearing (items, quantities,
 * prices, shipping, totals, addresses, the invoice attachment) stays fixed/
 * application-controlled and is never something CMS text can remove — see
 * MAIN.MD "Fixed transactional sections". The shop/internal notification
 * email is intentionally unaffected by any of this (see MAIN.MD "Shop/
 * internal notification email") and keeps its original hardcoded copy.
 */
class OrderConfirmationBuilder
{
    /**
     * The customer e-mail's editable copy. Its standard text is the code
     * default in App\Service\SiteSettings and nowhere else: defaultCopy()
     * reads it from there, build() falls back on it, and "Herstel
     * standaardtekst" on admin/shop-settings.php fills the fields with it.
     */
    public const CUSTOMER_COPY_KEYS = [
        'order_email_subject',
        'order_email_heading',
        'order_email_intro',
        'order_email_before_items',
        'order_email_after_items',
        'order_email_closing',
        'order_email_signature',
    ];

    /**
     * @return array<string, string> every CUSTOMER_COPY_KEYS key => its standard text
     */
    public static function defaultCopy(): array
    {
        return array_intersect_key(SiteSettings::defaults(), array_flip(self::CUSTOMER_COPY_KEYS));
    }

    /**
     * @param array<string, mixed> $order order row (must include id, total, shipping_cost, shipping_method, currency, and the order-level "shipping_" and "billing_"-prefixed columns — see OrderRepository::resolveShippingAddress()/resolveBillingAddress())
     * @param array<string, mixed> $customer customer row (name, email, phone, address_line, postal_code, city, country) — also used as the legacy address fallback for orders placed before order-level addresses existed
     * @param array<int, array<string, mixed>> $items rows from OrderRepository::findItems() (name, quantity, unit_price)
     * @param array<string, string> $emailSettings App\Service\SiteSettings::all() (or an equivalent array with the same order_email_* keys) — see class docblock
     * @return array{customer: array{subject:string, html:string, text:string}, shop: array{subject:string, html:string, text:string}}
     */
    public static function build(array $order, array $customer, array $items, array $emailSettings = []): array
    {
        // The legacy fallback in OrderRepository::resolveShippingAddress()
        // reads country/postal_code/city/address_line straight off $order —
        // true when it comes from a JOIN (admin/order.php), but this method
        // is only ever handed the bare `orders` row plus a separate
        // `$customer` row (see OrderConfirmationService), so merge them here.
        $orderWithCustomerFallback = $order + [
            'address_line' => $customer['address_line'] ?? null,
            'postal_code' => $customer['postal_code'] ?? null,
            'city' => $customer['city'] ?? null,
            'country' => $customer['country'] ?? null,
        ];
        $shippingAddress = OrderRepository::resolveShippingAddress($orderWithCustomerFallback);
        $billingAddress = OrderRepository::resolveBillingAddress($orderWithCustomerFallback);
        $billingDiffers = empty($order['billing_same_as_shipping']);

        $orderNumber = OrderRepository::orderNumber($order);
        $subtotal = self::itemsSubtotal($items);
        $shipping = (float) $order['shipping_cost'];
        $total = (float) $order['total'];
        $shippingMethodLabel = self::shippingMethodLabel((string) ($order['shipping_method'] ?? ''));

        $itemsHtml = self::renderItemsHtml($items);
        $itemsText = self::renderItemsText($items);
        $totalsHtml = self::renderTotalsHtml($subtotal, $shipping, $total, $shippingMethodLabel);
        $totalsText = self::renderTotalsText($subtotal, $shipping, $total, $shippingMethodLabel);
        $addressHtml = self::renderAddressHtml($shippingAddress, $customer);
        $addressText = self::renderAddressText($shippingAddress, $customer);
        $billingHtml = $billingDiffers
            ? '<h2 style="font-size:16px;margin:24px 0 8px;">Factuurgegevens</h2>' . self::renderAddressHtml($billingAddress, $customer, false)
            : '';
        $billingText = $billingDiffers
            ? "\nFactuurgegevens:\n" . self::renderAddressText($billingAddress, $customer, false)
            : '';

        // CMS-editable copy (App\Service\SiteSettings `order_email_*`), falling
        // back on the standard text — read from SiteSettings, never repeated
        // here — so this stays fully functional and testable when
        // $emailSettings is empty or missing keys.
        $copy = $emailSettings + self::defaultCopy();
        $placeholderValues = [
            'customer_name' => (string) $customer['name'],
            'order_number' => $orderNumber,
            'order_date' => (new \DateTimeImmutable((string) $order['created_at']))->format('d-m-Y'),
            'order_total' => self::euro($total),
            // So the shipped default subject can carry the site's own name
            // instead of one company's, and so an owner can use it in their
            // own wording without retyping it in five places.
            'site_name' => \App\Mail\EmailIdentity::name(),
        ];
        $render = static fn (string $key): string => EmailPlaceholders::render((string) $copy[$key], $placeholderValues, false);

        $subject = $render('order_email_subject');
        $heading = $render('order_email_heading');
        $intro = $render('order_email_intro');
        $beforeItems = $render('order_email_before_items');
        $afterItems = $render('order_email_after_items');
        $closing = $render('order_email_closing');
        $signature = $render('order_email_signature');

        $htmlParagraph = static fn (string $text): string => $text === '' ? '' : '<p>' . nl2br(self::esc($text)) . '</p>';

        return [
            'customer' => [
                'subject' => $subject,
                'html' => self::wrapHtml(
                    $heading,
                    $htmlParagraph($intro)
                    . $htmlParagraph($beforeItems)
                    . $itemsHtml . $totalsHtml
                    . $htmlParagraph($afterItems)
                    . '<h2 style="font-size:16px;margin:24px 0 8px;">Bezorggegevens</h2>'
                    . $addressHtml
                    . $billingHtml
                    . '<p style="margin-top:24px;">' . nl2br(self::esc($closing)) . '</p>'
                    . ($signature !== '' ? '<p style="margin-top:16px;">' . nl2br(self::esc($signature)) . '</p>' : '')
                ),
                'text' => ($intro !== '' ? $intro . "\n\n" : '')
                    . ($beforeItems !== '' ? $beforeItems . "\n\n" : '')
                    . $itemsText . "\n" . $totalsText
                    . ($afterItems !== '' ? "\n" . $afterItems . "\n" : '')
                    . "\nBezorggegevens:\n" . $addressText
                    . $billingText
                    . "\n" . $closing . "\n"
                    . ($signature !== '' ? "\n" . $signature . "\n" : ''),
            ],
            'shop' => [
                'subject' => 'Nieuwe betaalde bestelling ' . $orderNumber,
                'html' => self::wrapHtml(
                    'Nieuwe betaalde bestelling',
                    '<p>Er is een nieuwe, betaalde bestelling binnengekomen: <strong>' . self::esc($orderNumber) . '</strong>.</p>'
                    . $itemsHtml . $totalsHtml
                    . '<h2 style="font-size:16px;margin:24px 0 8px;">Klant- / bezorggegevens</h2>'
                    . $addressHtml
                    . $billingHtml
                ),
                'text' => "Er is een nieuwe, betaalde bestelling binnengekomen: {$orderNumber}.\n\n"
                    . $itemsText . "\n" . $totalsText
                    . "\nKlant- / bezorggegevens:\n" . $addressText
                    . $billingText,
            ],
        ];
    }

    private static function itemsSubtotal(array $items): float
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (int) $item['quantity'];
        }
        return $subtotal;
    }

    private static function renderItemsHtml(array $items): string
    {
        $rows = '';
        foreach ($items as $item) {
            $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
            $nameCell = self::esc($item['name']);
            if (!empty($item['variant_label'])) {
                $nameCell .= '<br><span style="color:#777;font-size:12px;">' . self::esc($item['variant_label']) . '</span>';
            }
            $rows .= '<tr>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;">' . $nameCell . '</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:center;">' . (int) $item['quantity'] . '×</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . self::euro((float) $item['unit_price']) . '</td>'
                . '<td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;">' . self::euro($lineTotal) . '</td>'
                . '</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin-top:16px;font-size:14px;">'
            . '<thead><tr>'
            . '<th style="text-align:left;padding:8px 0;border-bottom:2px solid #ddd;">Product</th>'
            . '<th style="text-align:center;padding:8px 0;border-bottom:2px solid #ddd;">Aantal</th>'
            . '<th style="text-align:right;padding:8px 0;border-bottom:2px solid #ddd;">Prijs</th>'
            . '<th style="text-align:right;padding:8px 0;border-bottom:2px solid #ddd;">Totaal</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    private static function renderItemsText(array $items): string
    {
        $lines = "Producten:\n";
        foreach ($items as $item) {
            $lineTotal = (float) $item['unit_price'] * (int) $item['quantity'];
            $name = $item['name'] . (!empty($item['variant_label']) ? ' (' . $item['variant_label'] . ')' : '');
            $lines .= '- ' . $name . ' — ' . (int) $item['quantity'] . 'x ' . self::euro((float) $item['unit_price'])
                . ' = ' . self::euro($lineTotal) . "\n";
        }
        return $lines;
    }

    private static function renderTotalsHtml(float $subtotal, float $shipping, float $total, string $shippingMethodLabel): string
    {
        $shippingRowLabel = $shippingMethodLabel === 'Afhalen' ? 'Afhalen' : 'Verzendkosten (' . $shippingMethodLabel . ')';

        return '<table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:14px;">'
            . '<tr><td style="padding:4px 0;">Subtotaal</td><td style="padding:4px 0;text-align:right;">' . self::euro($subtotal) . '</td></tr>'
            . '<tr><td style="padding:4px 0;">' . self::esc($shippingRowLabel) . '</td><td style="padding:4px 0;text-align:right;">' . self::euro($shipping) . '</td></tr>'
            . '<tr><td style="padding:8px 0;border-top:2px solid #ddd;font-weight:bold;">Totaal</td>'
            . '<td style="padding:8px 0;border-top:2px solid #ddd;text-align:right;font-weight:bold;">' . self::euro($total) . '</td></tr>'
            . '</table>';
    }

    private static function renderTotalsText(float $subtotal, float $shipping, float $total, string $shippingMethodLabel): string
    {
        $shippingRowLabel = $shippingMethodLabel === 'Afhalen' ? 'Afhalen' : 'Verzendkosten (' . $shippingMethodLabel . ')';

        return "Subtotaal: " . self::euro($subtotal) . "\n"
            . $shippingRowLabel . ": " . self::euro($shipping) . "\n"
            . "Totaal: " . self::euro($total) . "\n";
    }

    private static function shippingMethodLabel(string $shippingMethod): string
    {
        if ($shippingMethod === 'afhalen') {
            return 'Afhalen';
        }
        if (ShippingProfile::isValid($shippingMethod)) {
            // Dutch, like every other word of this mail (a confirmation in
            // the customer's language is on the backlog).
            return ShippingProfile::label($shippingMethod, 'nl');
        }

        return 'Verzenden';
    }

    /**
     * @param array{first_name:?string,last_name:?string,company:?string,country:?string,postal_code:?string,house_number:?string,house_number_addition:?string,street:?string,city:?string} $address
     * @param array<string, mixed> $customer used for the name fallback (legacy orders with no order-level first/last name) and, for the shipping block, the email/phone contact line
     */
    private static function renderAddressHtml(array $address, array $customer, bool $includeContact = true): string
    {
        $name = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')) ?: (string) $customer['name'];
        $lines = [self::esc($name)];
        if (!empty($address['company'])) {
            $lines[] = self::esc($address['company']);
        }
        $lines[] = self::esc(self::formatStreetLine($address));
        $lines[] = self::esc(trim(($address['postal_code'] ?? '') . ' ' . ($address['city'] ?? '')));
        $lines[] = self::esc($address['country'] ?? '');

        $html = '<p style="margin:0;">' . implode('<br>', array_filter($lines, static fn ($l) => $l !== '')) . '</p>';
        if ($includeContact) {
            $html .= '<p style="margin:8px 0 0;color:#555;">' . self::esc($customer['email']);
            if (!empty($customer['phone'])) {
                $html .= ' &middot; ' . self::esc($customer['phone']);
            }
            $html .= '</p>';
        }
        return $html;
    }

    /**
     * @param array{first_name:?string,last_name:?string,company:?string,country:?string,postal_code:?string,house_number:?string,house_number_addition:?string,street:?string,city:?string} $address
     * @param array<string, mixed> $customer
     */
    private static function renderAddressText(array $address, array $customer, bool $includeContact = true): string
    {
        $name = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')) ?: (string) $customer['name'];
        $lines = [$name];
        if (!empty($address['company'])) {
            $lines[] = $address['company'];
        }
        $lines[] = self::formatStreetLine($address);
        $lines[] = trim(($address['postal_code'] ?? '') . ' ' . ($address['city'] ?? ''));
        $lines[] = $address['country'] ?? '';
        if ($includeContact) {
            $lines[] = $customer['email'];
            if (!empty($customer['phone'])) {
                $lines[] = $customer['phone'];
            }
        }
        return implode("\n", array_filter($lines, static fn ($l) => $l !== null && $l !== '')) . "\n";
    }

    /**
     * Combines street + house number + addition into one line, following
     * BAG's own display convention (see App\Service\Address\
     * DutchAddressLookupService): a letter-led addition attaches directly
     * ("Kerkstraat 12A"), a digit-led one gets a dash ("Kerkstraat 12-3").
     * Falls back to just the street for a legacy order whose address only
     * ever had a single free-text "address_line" field (already includes the
     * number) — see OrderRepository::resolveShippingAddress().
     *
     * @param array{street:?string, house_number:?string, house_number_addition:?string} $address
     */
    private static function formatStreetLine(array $address): string
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

    private static function wrapHtml(string $heading, string $bodyHtml): string
    {
        return '<!doctype html><html lang="nl"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f5f3f0;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<table role="presentation" style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;">'
            . '<tr><td>'
            . '<h1 style="font-size:20px;margin:0 0 16px;">' . self::esc($heading) . '</h1>'
            . $bodyHtml
            . '<p style="margin-top:32px;font-size:12px;color:#999;">' . EmailIdentity::footerLine() . '</p>'
            . '</td></tr></table>'
            . '</body></html>';
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
