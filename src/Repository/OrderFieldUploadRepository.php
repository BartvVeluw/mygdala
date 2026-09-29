<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `order_field_uploads` SQL: the private pictures of "Afbeelding
 * uploaden" order questions (Shop Admin UX & Order Fields 2.0, MODULES.md
 * "Bestelvelden"). The outside world names a TEMPORARY upload only by its
 * token, looked up here by the token's SHA-256; a CLAIMED one only by its id,
 * and only on an authenticated admin route.
 *
 * Every state change is a conditional statement, never read-then-write:
 * a claim only takes a row that is unclaimed and not expired, a delete by
 * token or by the sweep only removes an unclaimed row. A claimed row is order
 * data and nothing here removes it.
 */
class OrderFieldUploadRepository extends Repository
{
    private const COLUMNS = 'id, product_id, field_id, storage_name, extension, original_filename, mime_type,
                             byte_size, image_width, image_height, created_at, expires_at, claimed_at, order_item_field_id';

    /**
     * @param array{token_hash: string, product_id: int, field_id: int, storage_name: string, extension: string, original_filename: string, mime_type: string, byte_size: int, image_width: int, image_height: int} $data
     */
    public function create(array $data, int $ttlHours): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_field_uploads
                (token_hash, product_id, field_id, storage_name, extension, original_filename, mime_type,
                 byte_size, image_width, image_height, created_at, expires_at)
             VALUES
                (:token_hash, :product_id, :field_id, :storage_name, :extension, :original_filename, :mime_type,
                 :byte_size, :image_width, :image_height, NOW(), NOW() + INTERVAL :ttl HOUR)'
        );
        $stmt->bindValue('token_hash', $data['token_hash']);
        $stmt->bindValue('product_id', $data['product_id'], \PDO::PARAM_INT);
        $stmt->bindValue('field_id', $data['field_id'], \PDO::PARAM_INT);
        $stmt->bindValue('storage_name', $data['storage_name']);
        $stmt->bindValue('extension', $data['extension']);
        $stmt->bindValue('original_filename', $data['original_filename']);
        $stmt->bindValue('mime_type', $data['mime_type']);
        $stmt->bindValue('byte_size', $data['byte_size'], \PDO::PARAM_INT);
        $stmt->bindValue('image_width', $data['image_width'], \PDO::PARAM_INT);
        $stmt->bindValue('image_height', $data['image_height'], \PDO::PARAM_INT);
        $stmt->bindValue('ttl', max(1, $ttlHours), \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * An upload by its token's hash, with whether it is still usable
     * (`expired` 1 once expires_at has passed, by the database's clock).
     *
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ', (expires_at <= NOW()) AS expired
             FROM order_field_uploads WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM order_field_uploads WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * A CLAIMED upload for the order screen and its file route: only a
     * picture that belongs to an order line is ever served to the CMS.
     *
     * @return array<string, mixed>|null with order_id
     */
    public function findClaimedForAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.storage_name, u.extension, u.original_filename, u.mime_type, u.byte_size,
                    u.image_width, u.image_height, oi.order_id
             FROM order_field_uploads u
             INNER JOIN order_item_fields f ON f.id = u.order_item_field_id
             INNER JOIN order_items oi ON oi.id = f.order_item_id
             WHERE u.id = :id AND u.claimed_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Makes a temporary upload order data, once: bound to the answer's
     * snapshot row, inside the checkout's transaction. False when another
     * order was first, or the upload expired or is gone — the caller then
     * rolls the whole order back.
     */
    public function claim(int $id, int $orderItemFieldId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE order_field_uploads
             SET claimed_at = NOW(), order_item_field_id = :answer
             WHERE id = :id AND claimed_at IS NULL AND order_item_field_id IS NULL AND expires_at > NOW()'
        );
        $stmt->execute(['answer' => $orderItemFieldId, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** The bytes all temporary (unclaimed) pictures take together. */
    public function temporaryBytes(): int
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(byte_size), 0) FROM order_field_uploads WHERE claimed_at IS NULL')->fetchColumn();
    }

    /**
     * Gives the pictures of an order whose payment ended without money
     * (failed to start, failed, canceled, expired) back to the customer's
     * cart: temporary again, with a fresh lifetime, so the same cart can be
     * checked out again — the cart stays in the browser until an order is
     * paid. The order's answers keep their filenames; only the file moves
     * back. A paid order is never touched here.
     *
     * @return int how many pictures came back
     */
    public function releaseForOrder(int $orderId, int $ttlHours): int
    {
        $stmt = $this->db->prepare(
            "UPDATE order_field_uploads u
             INNER JOIN order_item_fields f ON f.id = u.order_item_field_id
             INNER JOIN order_items oi ON oi.id = f.order_item_id
             INNER JOIN orders o ON o.id = oi.order_id
             SET u.claimed_at = NULL, u.order_item_field_id = NULL, u.expires_at = NOW() + INTERVAL :ttl HOUR
             WHERE oi.order_id = :order_id AND o.status IN ('failed', 'canceled', 'expired')"
        );
        $stmt->bindValue('ttl', max(1, $ttlHours), \PDO::PARAM_INT);
        $stmt->bindValue('order_id', $orderId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Of these storage names, the ones no row names any more: files the sweep
     * may remove (a crash between deleting a row and its files).
     *
     * @param list<string> $storageNames
     * @return list<string>
     */
    public function unknownStorageNames(array $storageNames): array
    {
        if ($storageNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($storageNames), '?'));
        $stmt = $this->db->prepare("SELECT storage_name FROM order_field_uploads WHERE storage_name IN ({$placeholders})");
        $stmt->execute(array_values($storageNames));
        $known = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return array_values(array_diff($storageNames, $known));
    }

    /**
     * Deletes a TEMPORARY upload by its token's hash (the customer replaced
     * or removed the picture) and says which files to remove, or null when
     * there was nothing unclaimed to delete.
     *
     * @return array{storage_name: string, extension: string}|null
     */
    public function deleteUnclaimedByTokenHash(string $tokenHash): ?array
    {
        $row = $this->findByTokenHash($tokenHash);
        if ($row === null || $row['claimed_at'] !== null) {
            return null;
        }

        return $this->deleteUnclaimed((int) $row['id'])
            ? ['storage_name' => (string) $row['storage_name'], 'extension' => (string) $row['extension']]
            : null;
    }

    /**
     * Expired temporary uploads, oldest first, in a bounded batch.
     *
     * @return list<array{id: int, storage_name: string, extension: string}>
     */
    public function findExpiredUnclaimed(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, storage_name, extension FROM order_field_uploads
             WHERE claimed_at IS NULL AND expires_at <= NOW()
             ORDER BY expires_at ASC, id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $stmt->execute();

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'storage_name' => (string) $row['storage_name'], 'extension' => (string) $row['extension']],
            $stmt->fetchAll()
        );
    }

    /** Deletes an upload only while it is unclaimed: "never" has to survive a future caller. */
    public function deleteUnclaimed(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM order_field_uploads WHERE id = :id AND claimed_at IS NULL');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }
}
