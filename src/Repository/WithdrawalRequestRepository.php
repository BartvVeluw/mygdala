<?php

namespace App\Repository;

/**
 * All withdrawal_requests SQL — see
 * db/migrations/20260906150000_create_withdrawal_requests_table.php for why
 * this is a manual-review model (status only, no automatic accept/reject).
 */
class WithdrawalRequestRepository extends Repository
{
    public const STATUSES = ['nieuw', 'in_behandeling', 'afgehandeld'];

    public function create(int $orderId, string $customerEmail, ?string $reason): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO withdrawal_requests (order_id, customer_email, reason, status, created_at, updated_at)
             VALUES (:order_id, :customer_email, :reason, 'nieuw', NOW(), NOW())"
        );
        $stmt->execute([
            'order_id' => $orderId,
            'customer_email' => $customerEmail,
            'reason' => $reason !== '' ? $reason : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * True when this order already has a pending (not yet afgehandeld)
     * request — used to avoid creating duplicate requests for the same
     * order when a customer submits the form more than once.
     */
    public function hasOpenRequestForOrder(int $orderId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM withdrawal_requests WHERE order_id = :order_id AND status != 'afgehandeld' LIMIT 1"
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Admin overview: newest first, optionally restricted to one status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(?string $status = null): array
    {
        $sql = 'SELECT wr.*, o.total, o.status AS order_status
                FROM withdrawal_requests wr
                JOIN orders o ON o.id = wr.order_id';
        $params = [];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE wr.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY wr.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findByIdForAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT wr.*, o.total, o.status AS order_status, o.created_at AS order_created_at
             FROM withdrawal_requests wr
             JOIN orders o ON o.id = wr.order_id
             WHERE wr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateStatus(int $id, string $status, ?string $adminNote): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE withdrawal_requests SET status = :status, admin_note = :admin_note, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'admin_note' => $adminNote !== null && $adminNote !== '' ? $adminNote : null,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
