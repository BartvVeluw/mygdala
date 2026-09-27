<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\InventoryRepository;
use App\Repository\OrderRepository;
use App\Repository\StockNotificationRepository;
use App\Service\Inventory\Inventory;
use App\Service\Inventory\StockNotifications;
use App\Service\Inventory\StockUnit;
use App\Service\InvoiceService;
use App\Service\Mailer;
use App\Service\OrderConfirmationService;
use App\Service\OrderPaymentSync;
use App\Service\Payment\PaymentSnapshot;
use App\Service\ShopLocalizedSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\ShopStockFixture;

/**
 * "Mail me when this is available again" (Shop Product & Ordering 2.0,
 * MODULES.md "Terug op voorraad"), against the real database with a mailer
 * that records instead of sending:
 *
 *   - asking stores one active request per unit and address, for a product
 *     or exactly the chosen variant, and only while that unit is sold out;
 *   - an address that is not one is refused before anything is stored;
 *   - stock from 0 to more writes once; 1 to 2 writes nobody; a sent request
 *     is never written again, and the same address may ask again afterwards;
 *   - a mail that fails stays active and is sent by the next sender;
 *   - the mail is in the visitor's language, from the owner's own template
 *     when there is one, with the placeholders replaced;
 *   - units a canceled payment gives back reach the waiting visitors too.
 */
final class StockNotificationTest extends TestCase
{
    private ShopStockFixture $fixture;
    private RecordingStockMailer $mailer;

    protected function setUp(): void
    {
        $this->fixture = new ShopStockFixture();
        $this->mailer = new RecordingStockMailer();
        ShopLocalizedSettings::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach (array_keys(ShopLocalizedSettings::KEYS) as $key) {
            $db->prepare('DELETE FROM site_setting_translations WHERE setting_key = :key')->execute(['key' => $key]);
        }
        ShopLocalizedSettings::clearCache();
        $this->fixture->cleanUp();
    }

    public function testAnAddressIsNormalisedAndANonAddressRefused(): void
    {
        self::assertSame('klant@example.com', StockNotifications::normaliseEmail('  Klant@Example.COM '));
        foreach (['', 'geen-adres', 'a@', null, 42, str_repeat('a', 250) . '@x.nl'] as $invalid) {
            self::assertNull(StockNotifications::normaliseEmail($invalid), var_export($invalid, true));
        }
    }

    public function testASoldOutProductStoresOneActiveRequestPerAddress(): void
    {
        $product = $this->fixture->product('ZZ Melding Product', 0);
        $notifications = new StockNotifications($this->mailer);

        self::assertSame(StockNotifications::SUBSCRIBED, $notifications->subscribe($product, null, 'klant@example.com', 'nl'));
        self::assertSame(StockNotifications::SUBSCRIBED, $notifications->subscribe($product, null, 'klant@example.com', 'en'), 'the same answer: nothing reveals the address was known');

        $rows = $this->rows($product);
        self::assertCount(1, $rows);
        self::assertSame('p' . $product, $rows[0]['unit_key']);
        self::assertNull($rows[0]['variant_id']);
        self::assertSame('nl', $rows[0]['language_code'], 'the first request stands');
        self::assertSame('active', $rows[0]['status']);
        self::assertNull($rows[0]['notified_at']);
    }

    public function testAVariantRequestIsForExactlyThatVariant(): void
    {
        $made = $this->fixture->variantProduct('ZZ Melding Varianten', ['A' => 0, 'B' => 3]);
        $notifications = new StockNotifications($this->mailer);

        self::assertSame(StockNotifications::SUBSCRIBED, $notifications->subscribe($made['product'], $made['variants']['A'], 'a@example.com', 'nl'));
        self::assertSame(StockNotifications::AVAILABLE, $notifications->subscribe($made['product'], $made['variants']['B'], 'b@example.com', 'nl'), 'B can be ordered: nothing stored');
        self::assertSame(StockNotifications::UNAVAILABLE, $notifications->subscribe($made['product'], null, 'c@example.com', 'nl'), 'a variant product needs its variant');

        $rows = $this->rows($made['product']);
        self::assertCount(1, $rows);
        self::assertSame((string) $made['variants']['A'], (string) $rows[0]['variant_id']);
        self::assertSame('v' . $made['variants']['A'], $rows[0]['unit_key']);
    }

    public function testNothingIsStoredForAnUntrackedAnInStockOrAnUnknownProduct(): void
    {
        $untracked = $this->fixture->product('ZZ Melding Onbeperkt');
        $inStock = $this->fixture->product('ZZ Melding Voorraad', 4);
        $notifications = new StockNotifications($this->mailer);

        self::assertSame(StockNotifications::AVAILABLE, $notifications->subscribe($untracked, null, 'x@example.com', 'nl'));
        self::assertSame(StockNotifications::AVAILABLE, $notifications->subscribe($inStock, null, 'x@example.com', 'nl'));
        self::assertSame(StockNotifications::UNAVAILABLE, $notifications->subscribe(999999999, null, 'x@example.com', 'nl'));
        self::assertSame([], $this->rows($untracked));
        self::assertSame([], $this->rows($inStock));
    }

    public function testFromZeroToMoreWritesOnceAndOneToTwoWritesNobody(): void
    {
        $product = $this->fixture->product('ZZ Melding Terug', 0);
        $notifications = new StockNotifications($this->mailer);
        $notifications->subscribe($product, null, 'terug@example.com', 'nl');

        self::assertSame(['sent' => 0, 'failed' => 0], $notifications->dispatchForProduct($product), 'still sold out');
        self::assertSame([], $this->mailer->sent);

        (new InventoryRepository())->setProductStock($product, 1, null);
        self::assertSame(['sent' => 1, 'failed' => 0], $notifications->dispatchForProduct($product));
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('terug@example.com', $this->mailer->sent[0]['to']);
        self::assertSame('ZZ Melding Terug is weer op voorraad', $this->mailer->sent[0]['subject']);

        $row = $this->rows($product)[0];
        self::assertSame('sent', $row['status']);
        self::assertNull($row['active_marker']);
        self::assertNotNull($row['notified_at']);

        (new InventoryRepository())->setProductStock($product, 2, null);
        self::assertSame(['sent' => 0, 'failed' => 0], $notifications->dispatchForProduct($product), '1 to 2: nobody is waiting');
        self::assertSame(['sent' => 0, 'failed' => 0], $notifications->dispatchWaiting(), 'a sent request is never written again');
        self::assertCount(1, $this->mailer->sent);
    }

    public function testTheSameAddressMayAskAgainAfterItWasWrittenTo(): void
    {
        $product = $this->fixture->product('ZZ Melding Opnieuw', 0);
        $notifications = new StockNotifications($this->mailer);
        $notifications->subscribe($product, null, 'opnieuw@example.com', 'nl');
        (new InventoryRepository())->setProductStock($product, 1, null);
        $notifications->dispatchForProduct($product);
        (new InventoryRepository())->setProductStock($product, 0, null);

        self::assertSame(StockNotifications::SUBSCRIBED, $notifications->subscribe($product, null, 'opnieuw@example.com', 'nl'));
        self::assertCount(2, $this->rows($product));
    }

    public function testAFailedMailStaysActiveAndTheNextSenderSendsIt(): void
    {
        $product = $this->fixture->product('ZZ Melding Mislukt', 0);
        (new StockNotifications($this->mailer))->subscribe($product, null, 'faal@example.com', 'nl');
        (new InventoryRepository())->setProductStock($product, 3, null);

        $failing = new RecordingStockMailer(true);
        self::assertSame(['sent' => 0, 'failed' => 1], (new StockNotifications($failing))->dispatchForProduct($product));

        $row = $this->rows($product)[0];
        self::assertSame('active', $row['status'], 'never marked sent');
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['last_error_at']);
        self::assertNull($row['claimed_at'], 'released for the next sender');

        self::assertSame(['waiting' => 1, 'due' => 1, 'failed' => 1], (new StockNotifications($this->mailer))->summary());
        self::assertSame(['sent' => 1, 'failed' => 0], (new StockNotifications($this->mailer))->dispatchWaiting());
        self::assertSame('sent', $this->rows($product)[0]['status']);
    }

    public function testAClaimedRequestIsNotWrittenBySecondSender(): void
    {
        $product = $this->fixture->product('ZZ Melding Geclaimd', 0);
        (new StockNotifications($this->mailer))->subscribe($product, null, 'claim@example.com', 'nl');
        (new InventoryRepository())->setProductStock($product, 1, null);

        $id = (int) $this->rows($product)[0]['id'];
        self::assertTrue((new StockNotificationRepository())->claim($id), 'another sender is writing it');

        self::assertSame(['sent' => 0, 'failed' => 0], (new StockNotifications($this->mailer))->dispatchForProduct($product));
        self::assertSame([], $this->mailer->sent);
    }

    public function testTheMailIsInTheVisitorsLanguageWithTheOwnersTemplate(): void
    {
        $made = $this->fixture->variantProduct('ZZ Melding Taal', ['Rood' => 0]);
        $variant = $made['variants']['Rood'];
        $notifications = new StockNotifications($this->mailer);
        $notifications->subscribe($made['product'], $variant, 'en@example.com', 'en');
        $notifications->subscribe($made['product'], $variant, 'nl@example.com', 'nl');

        ShopLocalizedSettings::save('nl', [
            ShopLocalizedSettings::STOCK_SUBJECT => 'Hoera: {{product_name}} ({{variant}})',
            ShopLocalizedSettings::STOCK_BODY => "Kijk op {{product_url}}\n\nGroet van {{site_name}}. {{customer_name}} blijft staan.",
        ]);
        ShopLocalizedSettings::clearCache();

        (new InventoryRepository())->setVariantStock($variant, $made['product'], 2, null);
        $notifications->dispatchForProduct($made['product']);

        $byAddress = array_column($this->mailer->sent, null, 'to');
        self::assertSame('Hoera: ZZ Melding Taal (Kleur: Rood)', $byAddress['nl@example.com']['subject']);
        self::assertStringContainsString('/product.php?id=' . $made['product'], $byAddress['nl@example.com']['text']);
        self::assertStringNotContainsString('/en/product.php', $byAddress['nl@example.com']['text']);
        self::assertStringContainsString('{{customer_name}} blijft staan.', $byAddress['nl@example.com']['text'], 'only the stock mail\'s own placeholders');
        self::assertStringContainsString('lang="nl"', $byAddress['nl@example.com']['html']);

        // English has no template of its own: the store falls back to the
        // default language's text, as every translated word on the site does.
        self::assertSame('Hoera: ZZ Melding Taal (Kleur: Rood)', $byAddress['en@example.com']['subject']);
        self::assertStringContainsString('/en/product.php?id=' . $made['product'], $byAddress['en@example.com']['text']);
        self::assertStringContainsString('View the product', $byAddress['en@example.com']['html']);
        self::assertStringContainsString('lang="en"', $byAddress['en@example.com']['html']);
    }

    public function testWithoutATemplateEveryLanguageGetsItsOwnStandardText(): void
    {
        $product = $this->fixture->product('ZZ Melding Standaard', 0);
        $notifications = new StockNotifications($this->mailer);
        $notifications->subscribe($product, null, 'en@example.com', 'en');
        $notifications->subscribe($product, null, 'nl@example.com', 'nl');
        (new InventoryRepository())->setProductStock($product, 1, null);
        $notifications->dispatchForProduct($product);

        $byAddress = array_column($this->mailer->sent, null, 'to');
        self::assertSame('ZZ Melding Standaard is back in stock', $byAddress['en@example.com']['subject']);
        self::assertStringContainsString('Good news: ZZ Melding Standaard can be ordered again.', $byAddress['en@example.com']['text'], 'no double space and no space before the full stop');
        self::assertSame('ZZ Melding Standaard is weer op voorraad', $byAddress['nl@example.com']['subject']);
        self::assertStringContainsString('Goed nieuws: ZZ Melding Standaard is weer te bestellen.', $byAddress['nl@example.com']['text']);
        self::assertStringNotContainsString('<script', $byAddress['nl@example.com']['html']);
    }

    public function testUnitsACanceledPaymentGivesBackReachTheWaitingVisitors(): void
    {
        $product = $this->fixture->product('ZZ Melding Webhook', 0);
        $notifications = new StockNotifications($this->mailer);
        $notifications->subscribe($product, null, 'webhook@example.com', 'nl');

        $paymentId = 'tr_zznotify' . bin2hex(random_bytes(3));
        $this->fixture->order([
            ['product_id' => $product, 'quantity' => 1, 'stock_reserved' => 1, 'stock_source' => StockUnit::SOURCE_PRODUCT],
        ], $paymentId);

        $sync = new OrderPaymentSync(new OrderRepository(), new SilentConfirmations(), new SilentInvoices(), new Inventory(), $notifications);
        $snapshot = new PaymentSnapshot($paymentId, PaymentSnapshot::CANCELED, 'canceled', false, 0.0, static fn (): array => []);
        $sync->sync($snapshot);
        $sync->sync($snapshot);

        self::assertSame(1, $this->fixture->productStock($product));
        self::assertCount(1, $this->mailer->sent, 'one release, one mail');
        self::assertSame('webhook@example.com', $this->mailer->sent[0]['to']);
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $productId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM stock_notifications WHERE product_id = :id ORDER BY id');
        $stmt->execute(['id' => $productId]);

        return $stmt->fetchAll();
    }
}

/** Records every mail instead of sending it, or fails every one. */
final class RecordingStockMailer extends Mailer
{
    /** @var list<array{to: string, subject: string, html: string, text: string}> */
    public array $sent = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

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
        if ($this->fail) {
            throw new \PHPMailer\PHPMailer\Exception('SMTP connect() failed.');
        }

        $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'html' => $html, 'text' => $text];
    }
}

final class SilentConfirmations extends OrderConfirmationService
{
    public function __construct()
    {
    }

    public function sendForOrderIfNeeded(int $orderId): void
    {
    }
}

final class SilentInvoices extends InvoiceService
{
    public function __construct()
    {
    }

    public function issueForOrderIfNeeded(int $orderId): ?array
    {
        return null;
    }
}
