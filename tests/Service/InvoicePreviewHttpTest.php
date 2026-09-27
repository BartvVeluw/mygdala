<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ShopModule;
use App\Repository\AdminUserRepository;
use App\Service\AdminPermissions;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\InvoiceOrderFixture;

/**
 * "Factuur bekijken" over HTTP, for real signed-in accounts: the button on
 * admin/order.php and api/admin/invoice-download.php behind it.
 *
 *  - signed out: the login page, never the PDF; without orders.view: the
 *    403 page; orders.view alone is enough to look, because looking changes
 *    nothing; with the Shop off nobody holds orders.view;
 *  - 400 for an id that is not one, 404 for an order that has no invoice;
 *  - the PDF inline in a new tab (or as a download), under a safe name, with
 *    no-store caching;
 *  - the invoice the customer received, in its own language (Dutch) whatever
 *    language the CMS speaks;
 *  - before and after a look, every row the invoice touches is identical and
 *    no file appears or changes — also when the stored file is missing.
 *
 * The data are InvoiceOrderFixture's own and are removed in tearDown().
 * Without a server the test skips itself.
 */
final class InvoicePreviewHttpTest extends TestCase
{
    private static ?BuiltInServer $shop = null;
    private static ?BuiltInServer $noShop = null;

    private AdminTestSession $accounts;
    private InvoiceOrderFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$shop = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true', 'MODULE_PERSONALIZATION_ENABLED' => 'true']);
        self::$noShop = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'false']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$shop?->stop();
        self::$noShop?->stop();
        self::$shop = null;
        self::$noShop = null;
    }

    protected function setUp(): void
    {
        $this->accounts = new AdminTestSession();
        $this->fixture = new InvoiceOrderFixture();

        if (self::$shop === null || !self::$shop->answers() || self::$noShop === null || !self::$noShop->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
    }

    protected function tearDown(): void
    {
        $this->accounts->forget();
        $this->fixture->cleanUp();
    }

    public function testSignedOutGetsTheLoginPageAndNeverThePdf(): void
    {
        [$orderId] = $this->fixture->invoicedOrder();

        $response = self::$shop->request('GET', $this->url($orderId));

        $this->assertSame(302, $response['status']);
        $this->assertSame('/admin/login.php', $response['location']);
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    public function testWithoutOrdersViewTheInvoiceIsRefused(): void
    {
        [$orderId] = $this->fixture->invoicedOrder();
        [$pagesOnly] = $this->accounts->signIn([AdminPermissions::PAGES_MANAGE]);

        $response = self::$shop->request('GET', $this->url($orderId), $pagesOnly);

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString('%PDF', $response['body']);
        $this->assertStringNotContainsString('application/pdf', BuiltInServer::header($response, 'Content-Type'));
    }

    public function testWithTheShopOffNobodyMayLook(): void
    {
        [$orderId] = $this->fixture->invoicedOrder();
        [$superAdmin] = $this->accounts->signIn([], true);

        $response = self::$noShop->request('GET', $this->url($orderId), $superAdmin);

        $this->assertSame(403, $response['status']);
        $this->assertStringNotContainsString('%PDF', $response['body']);
    }

    /**
     * Read-only access to orders is enough to see an invoice, like the order
     * screen itself; the answer is the stored PDF, inline, under the same
     * name the mail attachment carries, and kept out of every cache.
     */
    public function testOrdersViewShowsTheStoredPdfInline(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();
        [$viewer] = $this->accounts->signIn([ShopModule::ORDERS_VIEW]);
        $stored = (new InvoiceStorage())->read((string) $invoice['pdf_path']);
        $filename = InvoiceService::pdfFilename((string) $invoice['invoice_number']);

        $inline = self::$shop->request('GET', $this->url($orderId), $viewer);

        $this->assertSame(200, $inline['status']);
        $this->assertSame('application/pdf', BuiltInServer::header($inline, 'Content-Type'));
        $this->assertSame('inline; filename="' . $filename . '"', BuiltInServer::header($inline, 'Content-Disposition'));
        $this->assertSame('private, no-store', BuiltInServer::header($inline, 'Cache-Control'));
        $this->assertSame('nosniff', BuiltInServer::header($inline, 'X-Content-Type-Options'));
        $this->assertSame((string) strlen($stored), BuiltInServer::header($inline, 'Content-Length'));
        $this->assertSame(hash('sha256', $stored), hash('sha256', $inline['body']), 'the very file the customer got');

        $download = self::$shop->request('GET', $this->url($orderId) . '&mode=download', $viewer);
        $this->assertSame(200, $download['status']);
        $this->assertSame('attachment; filename="' . $filename . '"', BuiltInServer::header($download, 'Content-Disposition'));
        $this->assertSame($inline['body'], $download['body']);
    }

    public function testAnIdThatIsNoOrderOrAnOrderWithoutAnInvoiceShowsNothing(): void
    {
        [$superAdmin] = $this->accounts->signIn([], true);
        $paid = $this->fixture->paidOrder();
        $pending = $this->fixture->order('pending');
        $counters = $this->rows('SELECT * FROM invoice_number_counters ORDER BY year');

        foreach (['/api/admin/invoice-download.php', '/api/admin/invoice-download.php?order_id=0', '/api/admin/invoice-download.php?order_id=abc', '/api/admin/invoice-download.php?order_id=-3'] as $url) {
            $this->assertSame(400, self::$shop->request('GET', $url, $superAdmin)['status'], $url);
        }

        $unknown = (int) Database::connection()->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM orders')->fetchColumn();
        foreach ([$unknown, $pending, $paid] as $orderId) {
            $response = self::$shop->request('GET', $this->url($orderId), $superAdmin);
            $this->assertSame(404, $response['status'], 'order ' . $orderId);
            $this->assertStringNotContainsString('%PDF', $response['body']);
        }

        $this->assertSame([], $this->rows('SELECT id FROM invoices WHERE order_id IN (' . $paid . ', ' . $pending . ')'), 'looking issued no invoice');
        $this->assertSame($counters, $this->rows('SELECT * FROM invoice_number_counters ORDER BY year'), 'and spent no number');
    }

    /**
     * Before and after a look — inline, as a download, twice — the order, its
     * lines, its customer, its invoice, the invoice counters, the analytics
     * and the invoice files are exactly what they were. The same with the
     * stored file gone: the preview renders it in memory and leaves the gap
     * for the mail path to fill.
     */
    public function testLookingChangesNothing(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();
        [$manager] = $this->accounts->signIn([ShopModule::ORDERS_VIEW, ShopModule::ORDERS_MANAGE]);
        $storage = new InvoiceStorage();
        $path = (string) $invoice['pdf_path'];

        $before = $this->state($orderId);
        foreach (['', '&mode=download', ''] as $mode) {
            $this->assertSame(200, self::$shop->request('GET', $this->url($orderId) . $mode, $manager)['status']);
        }
        $this->assertSame($before, $this->state($orderId));

        unlink($storage->path($path));
        $before = $this->state($orderId);

        $response = self::$shop->request('GET', $this->url($orderId), $manager);

        $this->assertSame(200, $response['status']);
        $this->assertStringStartsWith('%PDF-', $response['body']);
        $this->assertStringContainsString((string) $invoice['invoice_number'], $this->text($response['body']));
        $this->assertFalse($storage->exists($path), 'looking writes no file');
        $this->assertSame($before, $this->state($orderId));
    }

    public function testTheOrderScreenOffersFactuurBekijkenInANewTab(): void
    {
        [$orderId] = $this->fixture->invoicedOrder();
        [$viewer] = $this->accounts->signIn([ShopModule::ORDERS_VIEW]);

        $page = self::$shop->request('GET', '/admin/order.php?id=' . $orderId, $viewer);
        $this->assertSame(200, $page['status']);
        $card = $this->invoiceCard($page['body']);

        $links = $this->invoiceLinks($card);
        $this->assertCount(2, $links, 'one view and one download link, each its own element');

        $view = $links[0];
        $this->assertSame('/api/admin/invoice-download.php?order_id=' . $orderId, $view->getAttribute('href'));
        $this->assertSame('_blank', $view->getAttribute('target'));
        $this->assertContains('noopener', explode(' ', $view->getAttribute('rel')));
        $this->assertSame('Factuur bekijken', trim($view->textContent));

        $download = $links[1];
        $this->assertSame('/api/admin/invoice-download.php?order_id=' . $orderId . '&mode=download', $download->getAttribute('href'));
        $this->assertSame('Download PDF', trim($download->textContent));
        $this->assertFalse($download->hasAttribute('target'));
    }

    public function testWithoutAnInvoiceThereIsNothingToView(): void
    {
        [$superAdmin] = $this->accounts->signIn([], true);

        foreach ([$this->fixture->paidOrder(), $this->fixture->order('pending')] as $orderId) {
            $page = self::$shop->request('GET', '/admin/order.php?id=' . $orderId, $superAdmin);
            $this->assertSame(200, $page['status']);
            $this->assertSame([], $this->invoiceLinks($this->invoiceCard($page['body'])), 'order ' . $orderId);
        }
    }

    /**
     * The CMS in English still shows the customer's invoice as the customer
     * got it: in Dutch, the only language invoices are issued in. Only the
     * button speaks the editor's language.
     */
    public function testAnEnglishCmsShowsTheDutchInvoiceTheCustomerGot(): void
    {
        [$orderId, $invoice] = $this->fixture->invoicedOrder();
        [$viewer] = $this->accounts->signIn([ShopModule::ORDERS_VIEW]);
        (new AdminUserRepository())->updateInterfaceLanguage((int) $this->accounts->read($viewer, 'admin_user_id'), 'en');

        $page = self::$shop->request('GET', '/admin/order.php?id=' . $orderId, $viewer);
        $links = $this->invoiceLinks($this->invoiceCard($page['body']));
        $this->assertSame('View invoice', trim($links[0]->textContent));

        $pdf = self::$shop->request('GET', $this->url($orderId), $viewer)['body'];
        $this->assertSame(hash('sha256', (new InvoiceStorage())->read((string) $invoice['pdf_path'])), hash('sha256', $pdf));

        $text = $this->text($pdf);
        foreach (['Factuur', 'Factuurnummer', 'Omschrijving', 'Aantal', 'Totaal'] as $dutch) {
            $this->assertStringContainsStringIgnoringCase($dutch, $text);
        }
        $this->assertStringNotContainsStringIgnoringCase('Invoice number', $text);
        $this->assertStringNotContainsStringIgnoringCase('Description', $text);
    }

    private function url(int $orderId): string
    {
        return '/api/admin/invoice-download.php?order_id=' . $orderId;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(int $orderId): array
    {
        $storage = new InvoiceStorage();
        $files = [];
        $root = $storage->path('');
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                clearstatcache(true, $file->getPathname());
                $files[$file->getPathname()] = [hash_file('sha256', $file->getPathname()), filemtime($file->getPathname())];
            }
        }
        ksort($files);

        return [
            'order' => $this->rows('SELECT * FROM orders WHERE id = ' . $orderId),
            'items' => $this->rows('SELECT * FROM order_items WHERE order_id = ' . $orderId . ' ORDER BY id'),
            'customer' => $this->rows('SELECT * FROM customers WHERE id = ' . (int) $this->fixture->customerId()),
            'invoices' => $this->rows('SELECT * FROM invoices ORDER BY id'),
            'counters' => $this->rows('SELECT * FROM invoice_number_counters ORDER BY year'),
            'page_views' => $this->rows('SELECT COUNT(*) AS n, COALESCE(MAX(id), 0) AS last FROM page_views'),
            'files' => $files,
        ];
    }

    private function invoiceCard(string $html): \DOMElement
    {
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $cards = (new \DOMXPath($document))->query('//section[contains(@class, "admin-card")][h2[normalize-space() = "Factuur" or normalize-space() = "Invoice"]]');
        $this->assertSame(1, $cards->length, 'the order screen has one invoice card');

        return $cards->item(0);
    }

    /**
     * @return list<\DOMElement>
     */
    private function invoiceLinks(\DOMElement $card): array
    {
        $links = [];
        foreach ((new \DOMXPath($card->ownerDocument))->query('.//a[contains(@href, "invoice-download.php")]', $card) as $link) {
            $links[] = $link;
        }

        return $links;
    }

    private function text(string $pdf): string
    {
        return (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return Database::connection()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }
}
