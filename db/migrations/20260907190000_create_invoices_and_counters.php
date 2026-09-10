<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Invoice administration, deliberately separate from order numbering (see
 * MAIN.MD "Transactional e-mail + factuur"): `orders`/`order_items` already
 * snapshot everything commercial (product name, price, variant, addresses —
 * see 20260907170000/20260907150000), so an invoice only needs its own
 * identity (number/date) plus a frozen copy of the *seller* side, since
 * company/tax settings in `site_settings` can legitimately change after an
 * invoice was issued and must never retroactively alter it.
 *
 * `invoices`: at most one canonical invoice per order (UNIQUE on order_id) —
 * no credit-note/multi-invoice support, per scope. `seller_snapshot` is a
 * JSON blob (company name/address/KVK/VAT/tax note/email/phone/website/
 * footer — see App\Service\InvoiceService::buildSellerSnapshot()) frozen at
 * issuance time; storing it as one JSON column avoids a schema migration
 * every time a new invoice-relevant setting is added, mirroring why
 * `site_settings` itself is key/value. `pdf_path` is a relative path under
 * the InvoiceStorage root (e.g. "2026/VLD-F2026-000001.pdf"), never a
 * public URL.
 *
 * `invoice_number_counters`: one row per year, incremented under
 * `SELECT ... FOR UPDATE` (see InvoiceRepository::allocateNextNumber()) —
 * the concurrency-safe alternative to `SELECT MAX(...)+1` explicitly called
 * for by the brief. Deliberately its own tiny table rather than reusing
 * `orders.id`, since not every order becomes an invoice and invoice numbers
 * must stay gapless-per-year regardless of that.
 */
final class CreateInvoicesAndCounters extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('invoices', ['id' => true]);
        $table
            ->addColumn('order_id', 'integer', ['signed' => false])
            ->addColumn('invoice_number', 'string', ['limit' => 32])
            ->addColumn('invoice_date', 'date')
            ->addColumn('currency', 'string', ['limit' => 3, 'default' => 'EUR'])
            ->addColumn('seller_snapshot', 'text')
            ->addColumn('pdf_path', 'string', ['limit' => 255])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('order_id', 'orders', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->addIndex(['order_id'], ['unique' => true])
            ->addIndex(['invoice_number'], ['unique' => true])
            ->create();

        $counters = $this->table('invoice_number_counters', ['id' => false, 'primary_key' => 'year']);
        $counters
            ->addColumn('year', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('last_number', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('updated_at', 'datetime')
            ->create();
    }

    public function down(): void
    {
        $this->table('invoice_number_counters')->drop()->save();
        $this->table('invoices')->drop()->save();
    }
}
