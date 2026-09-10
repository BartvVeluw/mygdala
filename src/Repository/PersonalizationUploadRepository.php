<?php

namespace App\Repository;

use App\Service\Personalization\PersonalizationRules;

/**
 * All `personalization_uploads` SQL. One row per validated customer image,
 * identified to the outside world only by its random `token` — never by its
 * auto-increment id and never by a filename.
 *
 * ## Lifecycle
 *
 * A row is created UNCLAIMED the moment the customer picks a file on the
 * product page. It becomes CLAIMED when an order that uses it is created
 * (markClaimed(), inside the checkout transaction). Unclaimed rows older than
 * PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS are abandoned uploads —
 * the customer never ordered — and may be deleted together with their files.
 * A claimed row is order evidence and is never deleted by that sweep.
 */
class PersonalizationUploadRepository extends Repository
{
    /**
     * @param array{token: string, product_id: ?int, stored_filename: string, preview_filename: string, original_filename: string, mime_type: string, image_width: ?int, image_height: ?int, byte_size: ?int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO personalization_uploads
                (token, product_id, stored_filename, preview_filename, original_filename,
                 mime_type, image_width, image_height, byte_size, created_at)
             VALUES
                (:token, :product_id, :stored_filename, :preview_filename, :original_filename,
                 :mime_type, :image_width, :image_height, :byte_size, NOW())'
        );
        $stmt->execute([
            'token' => $data['token'],
            'product_id' => $data['product_id'],
            'stored_filename' => $data['stored_filename'],
            'preview_filename' => $data['preview_filename'],
            'original_filename' => $data['original_filename'],
            'mime_type' => $data['mime_type'],
            'image_width' => $data['image_width'],
            'image_height' => $data['image_height'],
            'byte_size' => $data['byte_size'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The token is validated for shape by
     * PersonalizationRules::isValidUploadToken() before it ever reaches here,
     * so this is a plain equality lookup on a unique index.
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, token, product_id, stored_filename, preview_filename, original_filename,
                    mime_type, image_width, image_height, byte_size, claimed_at, created_at
             FROM personalization_uploads
             WHERE token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, token, product_id, stored_filename, preview_filename, original_filename,
                    mime_type, image_width, image_height, byte_size, claimed_at, created_at
             FROM personalization_uploads
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Stamps an upload as belonging to a real order. Idempotent, and never
     * moves the timestamp once set: re-claiming an already-claimed upload
     * (a customer who ordered the same personalized item twice) must not
     * rewrite when it first became order data.
     */
    public function markClaimed(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE personalization_uploads SET claimed_at = NOW() WHERE id = :id AND claimed_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Abandoned uploads: never claimed by an order and older than the TTL.
     * Returned in batches so the opportunistic sweep stays a small, bounded
     * amount of work on an ordinary request.
     *
     * @return array<int, array{id: int, stored_filename: string, preview_filename: string}>
     */
    public function findExpiredUnclaimed(int $ttlHours, int $limit = 25): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, stored_filename, preview_filename
             FROM personalization_uploads
             WHERE claimed_at IS NULL
               AND created_at < (NOW() - INTERVAL :ttl_hours HOUR)
             ORDER BY created_at ASC
             LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->bindValue('ttl_hours', max(1, $ttlHours), \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'stored_filename' => (string) $row['stored_filename'],
                'preview_filename' => (string) $row['preview_filename'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Only ever called for a row findExpiredUnclaimed() returned, so a row
     * that an order points at can never be removed here. The guard is
     * repeated in SQL because "never" has to survive a future caller too.
     */
    public function deleteUnclaimed(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM personalization_uploads WHERE id = :id AND claimed_at IS NULL');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function ttlHours(): int
    {
        return PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS;
    }
}
