<?php

namespace App\Repository;

/**
 * All contact_requests SQL. Deliberately small — see MAIN.MD, this is not a
 * CRM: no stages, only "nieuw"/"gelezen".
 */
class ContactRequestRepository extends Repository
{
    public const AUDIENCES = ['particulier', 'zakelijk'];
    public const STATUSES = ['nieuw', 'gelezen'];

    public function create(string $name, string $email, ?string $phone, string $audience, string $message): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO contact_requests (name, email, phone, audience, message, status, created_at, updated_at)
             VALUES (:name, :email, :phone, :audience, :message, 'nieuw', NOW(), NOW())"
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'audience' => in_array($audience, self::AUDIENCES, true) ? $audience : 'particulier',
            'message' => $message,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function setNotificationSentAt(int $id): void
    {
        $this->db->prepare('UPDATE contact_requests SET notification_sent_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Admin overview: newest first, optionally restricted to one status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(?string $status = null): array
    {
        $sql = 'SELECT id, name, email, audience, status, created_at FROM contact_requests';
        $params = [];

        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function findByIdForAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM contact_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        $stmt = $this->db->prepare('UPDATE contact_requests SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM contact_requests WHERE id = :id')->execute(['id' => $id]);
    }
}
