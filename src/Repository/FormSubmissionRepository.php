<?php

namespace App\Repository;

/**
 * All `form_submissions`, `form_submission_values` and
 * `form_submission_attachments` SQL — the retained half of Core Forms, and
 * the only place in the CMS that reads or writes personal data a visitor
 * typed into a form.
 *
 * SEPARATE FROM App\Repository\FormRepository on purpose. Defining a form is
 * a content job; reading what people sent is not, and the two are behind
 * different permissions (FORMS.md, "Rechten"). Keeping them apart means a
 * screen that only builds forms never has a submission query within reach.
 *
 * A submission SNAPSHOTS its own labels. Nothing here joins back to
 * `form_fields` to render an old submission, so renaming or deleting a field
 * next month cannot change what last month's enquiry says.
 */
class FormSubmissionRepository extends Repository
{
    /**
     * Writes the submission and all of its values in ONE transaction: a
     * half-written enquiry is worse than none, because the owner would reply
     * to it missing whatever did not land.
     *
     * The files that came with it are written in that same transaction, one
     * row each, tied to the field they were sent for (`field_key`). A row
     * holds where the file sits in the storage directory by NAME only, never
     * a machine path.
     *
     * @param list<array{field_key: string, field_label: string, field_type: string, value: string}> $values
     * @param list<array{field_key: string, stored_filename: string, original_filename: string, mime: string, size: int, sha256: string}> $attachments
     * @return int the new submission's id
     */
    public function create(int $formId, string $formName, ?string $sourcePath, array $values, array $attachments = []): int
    {
        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO form_submissions (form_id, form_name, source_path, is_read, created_at, updated_at)
                 VALUES (:form_id, :form_name, :source_path, 0, NOW(), NOW())'
            );
            $stmt->execute([
                'form_id' => $formId,
                'form_name' => mb_substr($formName, 0, 150),
                'source_path' => $sourcePath,
            ]);

            $submissionId = (int) $this->db->lastInsertId();

            $valueStmt = $this->db->prepare(
                'INSERT INTO form_submission_values
                    (submission_id, field_key, field_label, field_type, value, sort_order, created_at)
                 VALUES (:submission_id, :field_key, :field_label, :field_type, :value, :sort_order, NOW())'
            );

            foreach ($values as $position => $value) {
                $valueStmt->execute([
                    'submission_id' => $submissionId,
                    'field_key' => mb_substr($value['field_key'], 0, 64),
                    'field_label' => mb_substr($value['field_label'], 0, 200),
                    'field_type' => mb_substr($value['field_type'], 0, 32),
                    'value' => $value['value'],
                    'sort_order' => $position,
                ]);
            }

            $fileStmt = $this->db->prepare(
                'INSERT INTO form_submission_attachments
                    (submission_id, field_key, stored_filename, original_filename, mime_type, file_size, sha256, created_at)
                 VALUES (:submission_id, :field_key, :stored_filename, :original_filename, :mime_type, :file_size, :sha256, NOW())'
            );

            foreach ($attachments as $file) {
                $fileStmt->execute([
                    'submission_id' => $submissionId,
                    'field_key' => mb_substr($file['field_key'], 0, 64),
                    'stored_filename' => basename($file['stored_filename']),
                    'original_filename' => mb_substr($file['original_filename'], 0, 255),
                    'mime_type' => mb_substr($file['mime'], 0, 100),
                    'file_size' => $file['size'],
                    'sha256' => $file['sha256'] !== '' ? $file['sha256'] : null,
                ]);
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            return $submissionId;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }

    public function setNotificationSentAt(int $submissionId): void
    {
        $this->db->prepare('UPDATE form_submissions SET notification_sent_at = NOW(), updated_at = NOW() WHERE id = :id')
            ->execute(['id' => $submissionId]);
    }

    /**
     * The admin overview: newest first, optionally for one form only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(?int $formId = null, ?string $readState = null): array
    {
        $sql = 'SELECT s.*, (SELECT COUNT(*) FROM form_submission_attachments a WHERE a.submission_id = s.id) AS attachment_count
                  FROM form_submissions s';
        $where = [];
        $params = [];

        if ($formId !== null) {
            $where[] = 's.form_id = :form_id';
            $params['form_id'] = $formId;
        }

        if ($readState === 'unread' || $readState === 'read') {
            $where[] = 's.is_read = :is_read';
            $params['is_read'] = $readState === 'read' ? 1 : 0;
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY s.created_at DESC, s.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAdmin(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM form_submissions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function valuesFor(int $submissionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM form_submission_values WHERE submission_id = :id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['id' => $submissionId]);

        return $stmt->fetchAll();
    }

    /**
     * A compact preview for the overview: the first value that actually says
     * something about who sent it. Ordered by the field's own position, so
     * it is the first thing the visitor filled in.
     */
    public function previewFor(int $submissionId): string
    {
        $stmt = $this->db->prepare(
            "SELECT value FROM form_submission_values
              WHERE submission_id = :id AND value <> ''
              ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        $stmt->execute(['id' => $submissionId]);
        $value = $stmt->fetchColumn();

        return $value === false ? '' : (string) $value;
    }

    /**
     * Previews for a whole page of the overview in one query, so the list
     * never runs a query per row.
     *
     * @param list<int> $submissionIds
     * @return array<int, string> keyed by submission id
     */
    public function previewsFor(array $submissionIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $submissionIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT submission_id, value, sort_order, id
               FROM form_submission_values
              WHERE submission_id IN (" . $placeholders . ") AND value <> ''
              ORDER BY submission_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($ids);

        $previews = [];
        foreach ($stmt->fetchAll() as $row) {
            $submissionId = (int) $row['submission_id'];
            if (!isset($previews[$submissionId])) {
                $previews[$submissionId] = (string) $row['value'];
            }
        }

        return $previews;
    }

    /**
     * Every file of one submission, oldest first: one per upload field, plus
     * — on a submission from before Forms 2.0 phase 2 — the contact block's
     * single attachment, whose `field_key` is NULL.
     *
     * @return list<array<string, mixed>>
     */
    public function attachmentsFor(int $submissionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM form_submission_attachments WHERE submission_id = :id ORDER BY id ASC');
        $stmt->execute(['id' => $submissionId]);

        return $stmt->fetchAll();
    }

    /**
     * One file, found by its own id AND the submission it belongs to. Both
     * must match: a download asks for a file of a submission, and an id that
     * belongs to another submission is simply not found (FORMS.md, "Een
     * bestand downloaden").
     *
     * @return array<string, mixed>|null
     */
    public function attachment(int $submissionId, int $attachmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM form_submission_attachments WHERE id = :id AND submission_id = :submission_id LIMIT 1'
        );
        $stmt->execute(['id' => $attachmentId, 'submission_id' => $submissionId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function setReadState(int $id, bool $isRead): bool
    {
        $stmt = $this->db->prepare('UPDATE form_submissions SET is_read = :is_read, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['is_read' => $isRead ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanent, and it takes the values and the attachment rows with it
     * (ON DELETE CASCADE). The FILES are removed by the caller, after this
     * succeeded — a database cascade cannot touch the filesystem
     * (api/admin/delete-form-submission.php).
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM form_submissions WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function countForForm(int $formId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM form_submissions WHERE form_id = :form_id');
        $stmt->execute(['form_id' => $formId]);

        return (int) $stmt->fetchColumn();
    }

    public function countUnread(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM form_submissions WHERE is_read = 0')->fetchColumn();
    }

    /**
     * Submission counts for several forms at once, for the admin overview.
     *
     * @param list<int> $formIds
     * @return array<int, int> keyed by form id
     */
    public function countsForForms(array $formIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $formIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT form_id, COUNT(*) AS total FROM form_submissions
              WHERE form_id IN (' . $placeholders . ') GROUP BY form_id'
        );
        $stmt->execute($ids);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['form_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
