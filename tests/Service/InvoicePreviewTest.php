<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\InvoiceRepository;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;
use App\Service\Mailer;
use App\Service\OrderConfirmationService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvoiceOrderFixture;

/**
 * "Factuur bekijken" on the CMS order screen shows the invoice the customer
 * received — not a second template and not an approximation
 * (App\Service\InvoiceService::issuedPdfForOrder(), streamed by
 * api/admin/invoice-download.php):
 *
 *  - with the stored file present, the preview IS that file: the same bytes
 *    the confirmation mail attached;
 *  - with the file gone, it is the same invoice rendered again in memory from
 *    what was frozen at issue — its own number, date and seller_snapshot and
 *    the order's own snapshot rows — by the one renderer, and nothing is
 *    written;
 *  - looking never issues an invoice or spends a number;
 *  - there is exactly one invoice template in the code base.
 *
 * The HTTP side (permissions, headers, the button, nothing changed in the
 * database) is Tests\Service\InvoicePreviewHttpTest.
 */
final class InvoicePreviewTest extends TestCase
{
    private InvoiceOrderFixture $fixture;

    private ?string $previousNotificationEmail = null;

    protected function setUp(): void
    {
        $this->fixture = new InvoiceOrderFixture();
        $this->previousNotificationEmail = isset($_ENV['SHOP_NOTIFICATION_EMAIL']) ? (string) $_ENV['SHOP_NOTIFICATION_EMAIL'] : null;
        $_ENV['SHOP_NOTIFICATION_EMAIL'] = 'invoice-preview-shop@__test__.invalid';
    }

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);
        $this->fixture->cleanUp();

        if ($this->previousNotificationEmail === null) {
            unset($_ENV['SHOP_NOTIFICATION_EMAIL']);
        } else {
            $_ENV['SHOP_NOTIFICATION_EMAIL'] = $this->previousNotificationEmail;
        }
    }

    public function testThePreviewIsTheVeryFileTheConfirmationMailAttached(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();

        $mailer = new InvoicePreviewCapturingMailer();
        $this->assertTrue((new OrderConfirmationService(null, null, $mailer))->resend($orderId));
        $attachment = $mailer->calls[0]['attachments'][0];

        $preview = (new InvoiceService())->issuedPdfForOrder($orderId);

        $this->assertNotNull($preview);
        $this->assertSame((int) $invoice['id'], (int) $preview['invoice']['id']);
        $this->assertSame(
            hash_file('sha256', $attachment['path']),
            hash('sha256', $preview['pdf']),
            'the preview is byte for byte the PDF the customer got'
        );
        $this->assertSame(InvoiceService::pdfFilename((string) $invoice['invoice_number']), $attachment['name']);
    }

    public function testThePreviewShowsTheInvoicesOwnData(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();
        $orderNumber = (string) Database::connection()->query('SELECT order_number FROM orders WHERE id = ' . $orderId)->fetchColumn();

        $text = $this->text((new InvoiceService())->issuedPdfForOrder($orderId)['pdf']);

        foreach ([
            (string) $invoice['invoice_number'],
            (new \DateTimeImmutable((string) $invoice['invoice_date']))->format('d-m-Y'),
            $orderNumber,
            (string) json_decode((string) $invoice['seller_snapshot'], true)['company_name'],
            InvoiceOrderFixture::BILLING_NAME,
            InvoiceOrderFixture::BILLING_STREET . ' 42B',
            '6511AA ' . InvoiceOrderFixture::BILLING_CITY,
            InvoiceOrderFixture::LINE_ONE,
            InvoiceOrderFixture::LINE_ONE_VARIANT,
            InvoiceOrderFixture::LINE_TWO,
            '€ 12,50', '€ 25,00', '€ 7,25',
            '€ 32,25', '€ 6,95', '€ 39,20',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        // The customer's invoice is Dutch; so is the preview. (The column
        // headings are set in capitals by the invoice's CSS.)
        foreach (['Factuur', 'Factuurnummer', 'Omschrijving', 'Aantal', 'Prijs', 'Subtotaal', 'Verzendkosten', 'Totaal'] as $dutch) {
            $this->assertStringContainsStringIgnoringCase($dutch, $text);
        }

        $this->assertStringNotContainsString('Bezorgstraat', $text, 'an invoice is addressed to the billing address');
    }

    /**
     * A stored invoice is a snapshot: renaming the product, editing the
     * company details or the customer's record afterwards changes nothing
     * about what the preview shows — whether the stored file is there or has
     * to be rendered again from the frozen data. And rendering it again for a
     * look writes no file: putting a missing file back is the mail path's job
     * (InvoiceService::regeneratePdfIfMissing()).
     */
    public function testAMissingFileIsShownFromTheFrozenDataAndNotWritten(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();
        $storage = new InvoiceStorage();
        $path = (string) $invoice['pdf_path'];
        $issuedBytes = $storage->read($path);
        $issuedCompany = (string) json_decode((string) $invoice['seller_snapshot'], true)['company_name'];

        $db = Database::connection();
        $db->prepare('UPDATE product_translations SET name = :name WHERE product_id = :id')->execute(['name' => 'Hernoemd Product', 'id' => $this->fixture->productId()]);
        $db->prepare('UPDATE customers SET name = :name WHERE id = :id')->execute(['name' => 'Andere Klant', 'id' => $this->fixture->customerId()]);
        SiteSettings::overrideForTests(array_merge(SiteSettings::all(), ['company_name' => 'Nieuwe Bedrijfsnaam BV', 'invoice_number_prefix' => 'ZZZ']));

        $stored = (new InvoiceService())->issuedPdfForOrder($orderId);
        $this->assertSame($issuedBytes, $stored['pdf'], 'the stored file is served as it is');

        unlink($storage->path($path));
        $invoicesBefore = $this->rows('SELECT * FROM invoices WHERE order_id = ' . $orderId);
        $countersBefore = $this->rows('SELECT * FROM invoice_number_counters ORDER BY year');

        $rendered = (new InvoiceService())->issuedPdfForOrder($orderId);

        $this->assertNotNull($rendered);
        $this->assertStringStartsWith('%PDF-', $rendered['pdf']);
        $this->assertFalse($storage->exists($path), 'looking writes no file');
        $this->assertSame($invoicesBefore, $this->rows('SELECT * FROM invoices WHERE order_id = ' . $orderId));
        $this->assertSame($countersBefore, $this->rows('SELECT * FROM invoice_number_counters ORDER BY year'));

        // dompdf stamps each file with the moment it was rendered and a
        // random document id; with those out of the way the new render is
        // the issued file, byte for byte.
        $this->assertSame($this->withoutStamps($issuedBytes), $this->withoutStamps($rendered['pdf']));

        $text = $this->text($rendered['pdf']);
        $this->assertStringContainsString($issuedCompany, $text);
        $this->assertStringContainsString((string) $invoice['invoice_number'], $text);
        $this->assertStringContainsString(InvoiceOrderFixture::LINE_ONE, $text);
        $this->assertStringNotContainsString('Nieuwe Bedrijfsnaam BV', $text);
        $this->assertStringNotContainsString('Hernoemd Product', $text);
        $this->assertStringNotContainsString('ZZZ', $text);
    }

    public function testLookingNeverIssuesAnInvoice(): void
    {
        $paid = $this->fixture->paidOrder();
        $pending = $this->fixture->order('pending');
        $counters = $this->rows('SELECT * FROM invoice_number_counters ORDER BY year');

        $service = new InvoiceService();
        $this->assertNull($service->issuedPdfForOrder($paid), 'a paid order without an invoice has nothing to show yet');
        $this->assertNull($service->issuedPdfForOrder($pending));
        $this->assertNull($service->issuedPdfForOrder(PHP_INT_MAX));

        $this->assertNull((new InvoiceRepository())->findByOrderId($paid), 'looking issued no invoice');
        $this->assertSame($counters, $this->rows('SELECT * FROM invoice_number_counters ORDER BY year'), 'and spent no number');
    }

    public function testTheFileNameIsSafeInAHeader(): void
    {
        $this->assertSame('factuur-INV2026-000001.pdf', InvoiceService::pdfFilename('INV2026-000001'));
        $this->assertSame('factuur-VLD-F2026-000042.pdf', InvoiceService::pdfFilename('VLD-F2026-000042'));

        // The prefix is free text in Shop-instellingen.
        $name = InvoiceService::pdfFilename("A\"B; x=\r\n/../ä2026-000001");
        $this->assertMatchesRegularExpression('/^factuur-[A-Za-z0-9._-]+\.pdf$/', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('"', $name);
    }

    /**
     * One invoice template: only PdfInvoiceRenderer builds a PDF, every
     * invoice render goes through InvoiceService, and the preview endpoint
     * renders nothing of its own and writes nothing.
     */
    public function testThereIsOneInvoiceTemplateAndThePreviewOnlyReads(): void
    {
        $root = dirname(__DIR__, 2);
        $builders = [];
        foreach (['src', 'admin', 'api', 'partials'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'new Dompdf(')) {
                    $builders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        $this->assertSame(['src/Service/PdfInvoiceRenderer.php'], $builders);

        $renderers = [];
        foreach (['src', 'admin', 'api'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && preg_match('/new PdfInvoiceRenderer\(|PdfInvoiceRenderer \$/', (string) file_get_contents($file->getPathname())) === 1) {
                    $renderers[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($renderers);
        $this->assertSame(['src/Service/InvoiceService.php'], $renderers, 'only InvoiceService holds the renderer');

        $endpoint = (string) file_get_contents($root . '/api/admin/invoice-download.php');
        $this->assertStringContainsString('->issuedPdfForOrder(', $endpoint);
        foreach (['Dompdf', '<html', 'regeneratePdfIfMissing', 'issueForOrderIfNeeded', '->write(', 'Mailer', 'OrderConfirmationService', 'UPDATE ', 'INSERT ', 'DELETE ', '$_POST'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $endpoint, 'the preview endpoint must not use ' . $forbidden);
        }

        $service = (string) file_get_contents($root . '/src/Service/InvoiceService.php');
        preg_match('/function issuedPdfForOrder\(.*?\n    }\n/s', $service, $method);
        $this->assertNotEmpty($method);
        foreach (['->write(', 'allocateNextNumber', '->create(', 'UPDATE ', 'INSERT ', 'FOR UPDATE', 'beginTransaction'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $method[0], 'issuedPdfForOrder() must not use ' . $forbidden);
        }

        $mail = (string) file_get_contents($root . '/src/Service/OrderConfirmationService.php');
        $this->assertStringContainsString('InvoiceService::pdfFilename(', $mail, 'the mail attachment and the preview share one file name');
    }

    private function text(string $pdf): string
    {
        return (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
    }

    private function withoutStamps(string $pdf): string
    {
        $pdf = (string) preg_replace("/\/(CreationDate|ModDate) \(D:[0-9+']+\)/", '/$1 (D:0)', $pdf, -1, $dates);
        $pdf = (string) preg_replace('/\/ID\[<[0-9a-f]{32}><[0-9a-f]{32}>\]/', '/ID[<0><0>]', $pdf, -1, $ids);
        $this->assertSame([2, 1], [$dates, $ids], 'the stamps this helper expects are where dompdf puts them');

        return $pdf;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return Database::connection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}

final class InvoicePreviewCapturingMailer extends Mailer
{
    /** @var list<array{to: string, attachments: array}> */
    public array $calls = [];

    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        string $text,
        ?string $replyToEmail = null,
        ?string $replyToName = null,
        array $attachments = []
    ): void {
        $this->calls[] = ['to' => $toEmail, 'attachments' => $attachments];
    }
}
