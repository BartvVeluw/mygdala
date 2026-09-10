<?php

namespace App\Repository;

/**
 * All `personalization_preview_snapshots` SQL — the composed picture of one
 * personalization VIEW exactly as the customer saw it.
 *
 * The lifecycle deliberately mirrors `personalization_uploads`
 * (App\Repository\PersonalizationUploadRepository), because it is the same
 * problem: an anonymous visitor causes a file to be written, and most of
 * those files will never become an order.
 *
 *   UNCLAIMED  `order_item_id` IS NULL. A draft. Disposable, and swept by the
 *              same opportunistic cleanup that sweeps abandoned uploads.
 *   CLAIMED    `order_item_id` set inside the checkout transaction. Order
 *              evidence, never swept, removed only when the order itself is.
 *
 * A snapshot is SUPPLEMENTARY: the structured personalization and the
 * customer's own upload remain the source of truth, and an order line with no
 * snapshot is complete and renders its reconstruction as it always did.
 */
class PersonalizationPreviewSnapshotRepository extends Repository
{
    private const COLUMNS =
        'id, token, product_id, view_key, stored_filename, mime_type,
         image_width, image_height, byte_size, order_item_id, claimed_at, created_at';

    /**
     * @param array{token: string, product_id: ?int, view_key: string, stored_filename: string, image_width: ?int, image_height: ?int, byte_size: ?int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO personalization_preview_snapshots
                (token, product_id, view_key, stored_filename, mime_type,
                 image_width, image_height, byte_size, created_at)
             VALUES
                (:token, :product_id, :view_key, :stored_filename, \'image/png\',
                 :image_width, :image_height, :byte_size, NOW())'
        );
        $stmt->execute([
            'token' => $data['token'],
            'product_id' => $data['product_id'],
            'view_key' => $data['view_key'],
            'stored_filename' => $data['stored_filename'],
            'image_width' => $data['image_width'],
            'image_height' => $data['image_height'],
            'byte_size' => $data['byte_size'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The token's shape is checked by PersonalizationRules::isValidUploadToken()
     * before it ever reaches here, so this is a plain lookup on a unique index.
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM personalization_preview_snapshots WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Binds one snapshot to an order line and its view, inside the checkout
     * transaction. Only an UNCLAIMED row is bound: a token that already
     * belongs to an order can never be re-pointed at another one.
     */
    public function claim(int $snapshotId, int $orderItemId, string $viewKey): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE personalization_preview_snapshots
             SET order_item_id = :order_item_id, view_key = :view_key, claimed_at = NOW()
             WHERE id = :id AND order_item_id IS NULL'
        );
        $stmt->execute([
            'order_item_id' => $orderItemId,
            'view_key' => $viewKey,
            'id' => $snapshotId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Every snapshot on one order, grouped by order line and then by view —
     * exactly the shape the CMS order screen renders in.
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function findByOrderIdGrouped(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.token, s.view_key, s.stored_filename, s.mime_type,
                    s.image_width, s.image_height, s.byte_size, s.order_item_id, s.created_at
             FROM personalization_preview_snapshots s
             INNER JOIN order_items oi ON oi.id = s.order_item_id
             WHERE oi.order_id = :order_id
             ORDER BY s.order_item_id ASC, s.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['order_item_id']][(string) $row['view_key']] = $row;
        }

        return $grouped;
    }

    /**
     * One snapshot plus the order it belongs to — what the authenticated
     * admin download endpoint needs to answer "does this exist, and which
     * stored file may it serve". The filename comes from the row, never from
     * the request.
     */
    public function findClaimedForAdmin(int $snapshotId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.view_key, s.stored_filename, s.mime_type, s.image_width, s.image_height,
                    oi.order_id, oi.product_name
             FROM personalization_preview_snapshots s
             INNER JOIN order_items oi ON oi.id = s.order_item_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $snapshotId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Abandoned drafts: never claimed, and older than the same TTL an
     * abandoned customer upload gets. Bounded, because this runs
     * opportunistically inside a visitor's request.
     *
     * @return array<int, array{id:int, stored_filename:string}>
     */
    public function findExpiredUnclaimed(int $ttlHours, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, stored_filename
             FROM personalization_preview_snapshots
             WHERE order_item_id IS NULL
               AND created_at < (NOW() - INTERVAL :ttl HOUR)
             ORDER BY created_at ASC
             LIMIT ' . max(1, min(100, $limit))
        );
        $stmt->execute(['ttl' => $ttlHours]);

        return $stmt->fetchAll();
    }

    /**
     * Deletes one abandoned draft. The `order_item_id IS NULL` guard is
     * repeated here on purpose: between the query above and this call the row
     * may have been claimed by a checkout, and order evidence must never be
     * removed by housekeeping.
     */
    public function deleteUnclaimed(int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM personalization_preview_snapshots WHERE id = :id AND order_item_id IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
