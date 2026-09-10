<?php

namespace App\Repository;

use PDO;

/**
 * All invoices/invoice_number_counters SQL — see
 * db/migrations/20260907190000_create_invoices_and_counters.php.
 */
class InvoiceRepository extends Repository
{
    public function findByOrderId(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE order_id = :order_id LIMIT 1');
        $stmt->execute(['order_id' => $orderId]);

        $invoice = $stmt->fetch();

        return $invoice === false ? null : $invoice;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $invoice = $stmt->fetch();

        return $invoice === false ? null : $invoice;
    }

    public function create(
        int $orderId,
        string $invoiceNumber,
        \DateTimeInterface $invoiceDate,
        string $currency,
        array $sellerSnapshot,
        string $pdfPath
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO invoices (order_id, invoice_number, invoice_date, currency, seller_snapshot, pdf_path, created_at, updated_at)
             VALUES (:order_id, :invoice_number, :invoice_date, :currency, :seller_snapshot, :pdf_path, NOW(), NOW())'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate->format('Y-m-d'),
            'currency' => $currency,
            'seller_snapshot' => json_encode($sellerSnapshot, JSON_THROW_ON_ERROR),
            'pdf_path' => $pdfPath,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Allocates the next sequential invoice number for $year, gapless and
     * concurrency-safe: locks (or creates+locks) the single counter row for
     * that year with SELECT ... FOR UPDATE inside the caller's transaction,
     * so two orders being invoiced at the same moment (different orders —
     * same-order races are already serialized by the order row lock in
     * InvoiceService) can never receive the same number. Deliberately not
     * `SELECT MAX(invoice_number)+1`, which has no such guarantee.
     *
     * Must be called inside an already-open transaction on $db (the same
     * connection InvoiceService locked the order row on).
     */
    public function allocateNextNumber(PDO $db, int $year): int
    {
        $db->prepare('INSERT IGNORE INTO invoice_number_counters (year, last_number, updated_at) VALUES (:year, 0, NOW())')
            ->execute(['year' => $year]);

        $stmt = $db->prepare('SELECT last_number FROM invoice_number_counters WHERE year = :year FOR UPDATE');
        $stmt->execute(['year' => $year]);
        $current = (int) $stmt->fetchColumn();

        $next = $current + 1;

        $db->prepare('UPDATE invoice_number_counters SET last_number = :next, updated_at = NOW() WHERE year = :year')
            ->execute(['next' => $next, 'year' => $year]);

        return $next;
    }
}
