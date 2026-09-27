<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\SiteSettingRepository;
use App\Service\DocumentNumberPrefix;
use App\Service\InvoiceService;
use App\Service\InvoiceStorage;
use App\Service\ShopSettings;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\InvoiceOrderFixture;

/**
 * The invoice-number prefix (App\Service\DocumentNumberPrefix, Shop-instellingen):
 *
 *  - accepted: letters, digits, "-" and "_", starting with a letter or
 *    digit, at most 20 — INV, FACTUUR, INV-, F_2026;
 *  - refused: "/", "\", ":", "..", ".", quotes, whitespace, a newline or any
 *    control character, a leading "-" or "_", 21 characters;
 *  - the order-number prefix keeps its own, stricter rule, from the same class;
 *  - ShopSettings refuses a changed invalid prefix, and does not let an old
 *    one make an unrelated save fail;
 *  - with an old invalid prefix stored, a NEW invoice is numbered with its
 *    allowed characters and stored as a plain file, while an invoice issued
 *    before keeps its number, its row and its file untouched.
 *
 * The invoices are issued into a storage directory of this test's own and
 * removed with their rows; the prefix setting is put back.
 */
final class InvoicePrefixTest extends TestCase
{
    private ?string $prefixBefore = null;
    private ?string $storageBefore = null;
    private string $storage;
    private ?InvoiceOrderFixture $fixture = null;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/mygdala-invoice-prefix-' . bin2hex(random_bytes(6));
        $this->storageBefore = $_ENV['INVOICE_STORAGE_PATH'] ?? null;
        $_ENV['INVOICE_STORAGE_PATH'] = $this->storage;

        $statement = Database::connection()->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'invoice_number_prefix'");
        $statement->execute();
        $value = $statement->fetchColumn();
        $this->prefixBefore = $value === false ? null : (string) $value;
    }

    protected function tearDown(): void
    {
        $this->fixture?->cleanUp();

        Database::connection()->prepare("DELETE FROM site_settings WHERE setting_key = 'invoice_number_prefix'")->execute();
        if ($this->prefixBefore !== null) {
            (new SiteSettingRepository())->upsertMany(['invoice_number_prefix' => $this->prefixBefore]);
        }
        SiteSettings::clearCache();
        SiteSettings::overrideForTests(null);

        if ($this->storageBefore === null) {
            unset($_ENV['INVOICE_STORAGE_PATH']);
        } else {
            $_ENV['INVOICE_STORAGE_PATH'] = $this->storageBefore;
        }
        $this->remove($this->storage);
    }

    private function setPrefix(string $prefix): void
    {
        (new SiteSettingRepository())->upsertMany(['invoice_number_prefix' => $prefix]);
        SiteSettings::clearCache();
    }

    public function testWhatAnInvoicePrefixMayBe(): void
    {
        foreach (['INV', 'FACTUUR', 'INV-', 'F_2026', 'A', 'inv2', 'ABCDEFGHIJKLMNOPQRST'] as $accepted) {
            $this->assertTrue(DocumentNumberPrefix::isValidInvoicePrefix($accepted), $accepted);
        }

        foreach (['', '/', 'A/B', '\\', 'A\\B', 'C:', '..', '.', 'INV.', "IN'V", 'IN"V', "IN\nV", "INV\n", 'IN V', ' INV', "IN\tV", "IN\0V", '-INV', '_INV', 'ABCDEFGHIJKLMNOPQRSTU', 'FACTÜR'] as $refused) {
            $this->assertFalse(DocumentNumberPrefix::isValidInvoicePrefix($refused), var_export($refused, true));
        }
    }

    public function testTheOrderPrefixKeepsItsOwnStricterRule(): void
    {
        $this->assertTrue(DocumentNumberPrefix::isValidOrderPrefix('SHOP26'));
        foreach (['ORD-', 'ORD_', 'ABCDEFGHIJK', 'A/B', "ORD\n"] as $refused) {
            $this->assertFalse(DocumentNumberPrefix::isValidOrderPrefix($refused), var_export($refused, true));
        }
    }

    public function testTheSettingsScreenRefusesAChangedInvalidPrefixOnly(): void
    {
        $defaults = SiteSettings::defaults();

        $this->assertSame([], ShopSettings::validate(['invoice_number_prefix' => 'FACTUUR-'], $defaults)['errors']);
        $this->assertSame('FACTUUR-', ShopSettings::validate(['invoice_number_prefix' => ' FACTUUR- '], $defaults)['values']['invoice_number_prefix']);
        $this->assertSame([], ShopSettings::validate(['invoice_number_prefix' => ''], $defaults)['errors'], 'empty means the default');

        foreach (['A/B', 'C:', '..', 'IN V', 'INV.'] as $invalid) {
            $this->assertNotSame([], ShopSettings::validate(['invoice_number_prefix' => $invalid], $defaults)['errors'], $invalid);
        }

        $legacy = ['invoice_number_prefix' => 'OUD/F'] + $defaults;
        $this->assertSame(
            [],
            ShopSettings::validate(['invoice_number_prefix' => 'OUD/F', 'invoice_tax_note' => 'Btw verlegd'], $legacy)['errors'],
            'an old value, unchanged, does not make saving the other invoice texts fail'
        );
        $this->assertNotSame([], ShopSettings::validate(['invoice_number_prefix' => 'NIEUW/F'], $legacy)['errors']);
    }

    public function testANewInvoiceUsesOnlyTheAllowedCharactersOfAnOldPrefix(): void
    {
        $this->assertSame('INV-', DocumentNumberPrefix::invoicePrefixForNewInvoice('INV-'));
        $this->assertSame('AB', DocumentNumberPrefix::invoicePrefixForNewInvoice('A/B'));
        $this->assertSame('CF', DocumentNumberPrefix::invoicePrefixForNewInvoice('C:../F'));
        $this->assertSame('INV', DocumentNumberPrefix::invoicePrefixForNewInvoice('/:.'));
        $this->assertSame('FACT', DocumentNumberPrefix::invoicePrefixForNewInvoice('-FACT '));
        $this->assertSame(20, strlen(DocumentNumberPrefix::invoicePrefixForNewInvoice(str_repeat('AB/', 12))));
    }

    public function testAnIssuedInvoiceKeepsItsFileAndANewOneNeverMakesAFolder(): void
    {
        $this->fixture = new InvoiceOrderFixture();

        $this->setPrefix('OUD-');
        [, $before] = $this->fixture->invoicedOrder();
        $this->assertStringStartsWith('OUD-', (string) $before['invoice_number']);
        $beforeBytes = (new InvoiceStorage())->read((string) $before['pdf_path']);

        // A prefix from before the rule: stored as it was, never rewritten.
        $this->setPrefix('A/B:');
        [, $after] = $this->fixture->invoicedOrder();

        $this->assertStringStartsWith('AB' . date('Y') . '-', (string) $after['invoice_number']);
        $this->assertSame(date('Y') . '/' . $after['invoice_number'] . '.pdf', $after['pdf_path']);
        $this->assertStringNotContainsString(':', (string) $after['pdf_path']);
        $this->assertSame(1, substr_count((string) $after['pdf_path'], '/'), 'one folder: the year');
        $this->assertTrue((new InvoiceStorage())->exists((string) $after['pdf_path']));
        $this->assertSame('AB', json_decode((string) $after['seller_snapshot'], true)['invoice_number_prefix']);

        $row = Database::connection()->prepare('SELECT invoice_number, pdf_path, seller_snapshot FROM invoices WHERE id = :id');
        $row->execute(['id' => $before['id']]);
        $this->assertSame(
            ['invoice_number' => $before['invoice_number'], 'pdf_path' => $before['pdf_path'], 'seller_snapshot' => $before['seller_snapshot']],
            $row->fetch(\PDO::FETCH_ASSOC),
            'the invoice issued before is untouched'
        );
        $this->assertSame($beforeBytes, (new InvoiceStorage())->read((string) $before['pdf_path']), 'and so is its file');
        $this->assertSame('A/B:', SiteSettings::get('invoice_number_prefix'), 'the setting itself is not rewritten');
    }

    public function testTheStoragePathOfANewNumberIsAFileNameUnderItsYear(): void
    {
        $this->assertSame('2026/INV2026-000001.pdf', InvoiceService::storagePath(2026, 'INV2026-000001'));
        $this->assertSame('2026/A-B2026-000001.pdf', InvoiceService::storagePath(2026, 'A/B2026-000001'));
        $this->assertSame('2026/C-----x.pdf', InvoiceService::storagePath(2026, 'C:/../x'));
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
