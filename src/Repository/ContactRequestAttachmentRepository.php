<?php

namespace App\Repository;

/**
 * All contact_request_attachments SQL. The form only ever accepts one
 * attachment today, but this stays a child table (see the migration) —
 * findByContactRequestId() returns just the one row that currently exists.
 */
class ContactRequestAttachmentRepository extends Repository
{
    public function create(
        int $contactRequestId,
        string $storedFilename,
        string $originalFilename,
        string $mimeType,
        int $fileSize
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO contact_request_attachments
                (contact_request_id, stored_filename, original_filename, mime_type, file_size, created_at)
             VALUES (:contact_request_id, :stored_filename, :original_filename, :mime_type, :file_size, NOW())'
        );
        $stmt->execute([
            'contact_request_id' => $contactRequestId,
            'stored_filename' => $storedFilename,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findByContactRequestId(int $contactRequestId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM contact_request_attachments WHERE contact_request_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $contactRequestId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
