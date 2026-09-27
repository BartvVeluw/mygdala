<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the back-in-stock requests (`stock_notifications`, Shop Product
 * & Ordering 2.0, MODULES.md "Terug op voorraad"). App\Service\Inventory\
 * StockNotifications decides who is written to and when; this only stores
 * and claims.
 *
 * ONE ACTIVE REQUEST PER UNIT AND ADDRESS, by the database itself: the unique
 * key over (unit_key, email, active_marker) refuses a second active row,
 * while a sent row (active_marker NULL) never collides, so the same address
 * may ask again after it was written to.
 *
 * NO MAIL TWICE. A sender claims a row in one conditional UPDATE before it
 * writes (claim()); a second sender at the same moment gets nothing. A claim
 * older than CLAIM_MINUTES counts as abandoned (a request that died halfway),
 * so the row can be retried.
 */
class StockNotificationRepository extends Repository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SENT = 'sent';

    private const CLAIM_MINUTES = 10;

    /**
     * Stores a request, or does nothing when the same address already has an
     * active one for this unit.
     *
     * @return bool whether a new request was stored
     */
    public function subscribe(int $productId, ?int $variantId, string $unitKey, string $email, string $languageCode): bool
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO stock_notifications
                (product_id, variant_id, unit_key, email, language_code, status, active_marker, attempts, created_at)
             VALUES (:product_id, :variant_id, :unit_key, :email, :language_code, 'active', 1, 0, NOW())"
        );
        $stmt->execute([
            'product_id' => $productId,
            'variant_id' => $variantId,
            'unit_key' => $unitKey,
            'email' => $email,
            'language_code' => $languageCode,
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * The active requests of one unit, oldest first.
     *
     * @return list<array{id: int, product_id: int, variant_id: ?int, email: string, language_code: string}>
     */
    public function activeForUnit(string $unitKey, int $limit): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, product_id, variant_id, email, language_code
             FROM stock_notifications
             WHERE unit_key = :unit_key AND status = 'active'
             ORDER BY id ASC
             LIMIT " . max(1, $limit)
        );
        $stmt->execute(['unit_key' => $unitKey]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'variant_id' => $row['variant_id'] !== null ? (int) $row['variant_id'] : null,
            'email' => (string) $row['email'],
            'language_code' => (string) $row['language_code'],
        ], $stmt->fetchAll());
    }

    /**
     * Every unit someone is waiting for, with its product and variant.
     *
     * @return list<array{unit_key: string, product_id: int, variant_id: ?int, waiting: int}>
     */
    public function waitingUnits(): array
    {
        $stmt = $this->db->query(
            "SELECT unit_key, product_id, variant_id, COUNT(*) AS waiting
             FROM stock_notifications
             WHERE status = 'active'
             GROUP BY unit_key, product_id, variant_id
             ORDER BY MIN(id) ASC"
        );

        return array_map(static fn (array $row): array => [
            'unit_key' => (string) $row['unit_key'],
            'product_id' => (int) $row['product_id'],
            'variant_id' => $row['variant_id'] !== null ? (int) $row['variant_id'] : null,
            'waiting' => (int) $row['waiting'],
        ], $stmt->fetchAll());
    }

    /** How many active requests there are for one product, all its variants included. */
    public function countActiveForProduct(int $productId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM stock_notifications WHERE product_id = :id AND status = 'active'");
        $stmt->execute(['id' => $productId]);

        return (int) $stmt->fetchColumn();
    }

    /** How many active requests had a mail fail at least once. */
    public function countFailed(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM stock_notifications WHERE status = 'active' AND attempts > 0")->fetchColumn();
    }

    /** Takes a request for writing, or false when another sender has it. */
    public function claim(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE stock_notifications SET claimed_at = NOW()
             WHERE id = :id AND status = 'active'
               AND (claimed_at IS NULL OR claimed_at < NOW() - INTERVAL " . self::CLAIM_MINUTES . ' MINUTE)'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** The mail went out: the request is done, and the address may ask again later. */
    public function markSent(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE stock_notifications
             SET status = 'sent', active_marker = NULL, notified_at = NOW(), claimed_at = NULL
             WHERE id = :id AND status = 'active'"
        );
        $stmt->execute(['id' => $id]);
    }

    /** The mail failed: the request stays active for a retry, and says so. */
    public function markFailed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE stock_notifications SET attempts = attempts + 1, last_error_at = NOW(), claimed_at = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /** @return array<string, mixed>|null one row, for tests and the admin */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM stock_notifications WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
