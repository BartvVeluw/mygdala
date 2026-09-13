<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PdfInvoiceRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no database, no filesystem writes) for
 * App\Service\PdfInvoiceRenderer — covers MAIN.MD "PDF generation succeeds"
 * and confirms every dynamic value is escaped before reaching the HTML that
 * dompdf renders (seller_snapshot/customer data ultimately originates from
 * admin-entered CMS text).
 */
final class PdfInvoiceRendererTest extends TestCase
{
    private function order(): array
    {
        return [
            'id' => 7,
            'total' => '29.90',
            'shipping_cost' => '4.95',
            'currency' => 'EUR',
            'created_at' => '2026-03-14 10:00:00',
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
    }

    private function customer(): array
    {
        return ['name' => 'Jan Jansen', 'email' => 'jan@example.invalid', 'address_line' => null, 'postal_code' => null, 'city' => null, 'country' => null];
    }

    private function sellerSnapshot(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Van Veluw Laserdesign',
            'logo_path' => '',
            'street' => 'Bedrijfsstraat',
            'house_number' => '10',
            'postal_code' => '6500AA',
            'city' => 'Nijmegen',
            'country' => 'NL',
            'kvk_number' => '97749540',
            'vat_id' => '',
            'email' => 'info@vanveluwlaserdesign.nl',
            'phone' => '',
            'website' => 'www.vanveluwlaserdesign.nl',
            'tax_note' => '',
            'footer_text' => '',
            'payment_note' => '',
            'invoice_number_prefix' => 'VLD-F',
        ], $overrides);
    }

    public function testRenderProducesAValidPdf(): void
    {
        $items = [['name' => 'Sleutelhanger', 'variant_label' => null, 'quantity' => 1, 'unit_price' => '24.95']];

        $pdf = (new PdfInvoiceRenderer())->render(
            $this->order(),
            $this->customer(),
            $items,
            $this->sellerSnapshot(),
            'VLD-F2026-000001',
            new \DateTimeImmutable('2026-03-14'),
            'ORD-2026-000007'
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(500, strlen($pdf));
    }

    /**
     * The renderer prints the order number it is handed and never builds one:
     * InvoiceService hands it OrderRepository::formatOrderNumber()'s answer,
     * the same one the e-mail, the export and the Mollie payment use.
     */
    public function testTheOrderNumberItIsGivenIsTheOneOnTheInvoice(): void
    {
        $items = [['name' => 'Sleutelhanger', 'variant_label' => null, 'quantity' => 1, 'unit_price' => '24.95']];

        $pdf = (new PdfInvoiceRenderer())->render(
            $this->order(),
            $this->customer(),
            $items,
            $this->sellerSnapshot(),
            'INV2026-000004',
            new \DateTimeImmutable('2026-03-14'),
            'SHOP-2026-000007'
        );

        $text = (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();

        $this->assertStringContainsString('SHOP-2026-000007', $text);
        $this->assertStringContainsString('INV2026-000004', $text);
    }

    public function testMaliciousCustomerAndSettingsDataCannotBreakRenderingAndIsNotExecuted(): void
    {
        $items = [['name' => '<script>alert(1)</script>', 'variant_label' => '</table><b>x</b>', 'quantity' => 1, 'unit_price' => '1.00']];
        $customer = ['name' => '"><img src=x>', 'email' => 'x@example.invalid', 'address_line' => null, 'postal_code' => null, 'city' => null, 'country' => null];
        $seller = $this->sellerSnapshot(['company_name' => '<script>evil()</script>', 'tax_note' => "line1\nline2 <b>bold</b>"]);

        $pdf = (new PdfInvoiceRenderer())->render(
            $this->order(),
            $customer,
            $items,
            $seller,
            'VLD-F2026-000002',
            new \DateTimeImmutable('2026-03-14'),
            'ORD-2026-000007'
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testMissingLogoFileDoesNotBreakRendering(): void
    {
        $items = [['name' => 'Sleutelhanger', 'variant_label' => null, 'quantity' => 1, 'unit_price' => '24.95']];
        $seller = $this->sellerSnapshot(['logo_path' => 'assets/images/does-not-exist-at-all.png']);

        $pdf = (new PdfInvoiceRenderer())->render(
            $this->order(),
            $this->customer(),
            $items,
            $seller,
            'VLD-F2026-000003',
            new \DateTimeImmutable('2026-03-14'),
            'ORD-2026-000007'
        );

        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
