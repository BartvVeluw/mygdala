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
     * Rows per page of Beheer → Inzendingen. "Alles selecteren" there means
     * this page and nothing more, so it is also the most a normal bulk
     * request carries (App\Service\Forms\FormSubmissionBulk::MAX_IDS is
     * deliberately larger).
     */
    public const PAGE_SIZE = 25;

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
        [$where, $params] = $this->adminFilter($formId, $readState);

        $stmt = $this->db->prepare(
            'SELECT s.*, (SELECT COUNT(*) FROM form_submission_attachments a WHERE a.submission_id = s.id) AS attachment_count
               FROM form_submissions s' . $where . '
              ORDER BY s.created_at DESC, s.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One page of the admin overview, in the same order as findAllForAdmin()
     * and under the same two filters, plus how many rows the filters match
     * in all. A page past the last one comes back empty; the screen decides
     * what to show instead.
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int}
     */
    public function findPageForAdmin(?int $formId, ?string $readState, int $page): array
    {
        [$where, $params] = $this->adminFilter($formId, $readState);

        $count = $this->db->prepare('SELECT COUNT(*) FROM form_submissions s' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = (max(1, $page) - 1) * self::PAGE_SIZE;
        $stmt = $this->db->prepare(
            'SELECT s.* FROM form_submissions s' . $where . '
              ORDER BY s.created_at DESC, s.id DESC
              LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * The overview's WHERE clause. Read state is a closed pair; anything
     * else is "all".
     *
     * @return array{0: string, 1: array<string, int>}
     */
    private function adminFilter(?int $formId, ?string $readState): array
    {
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

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
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

    /**
     * The form each of these submissions belongs to, for the ids that exist,
     * locked for the rest of the caller's transaction so nothing changes
     * between checking a bulk selection and acting on it
     * (App\Service\Forms\FormSubmissionBulk). An id that does not exist is
     * simply absent; a submission whose form was deleted maps to null.
     *
     * @param list<int> $ids
     * @return array<int, int|null> keyed by submission id
     */
    public function formIdsForUpdate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT id, form_id FROM form_submissions WHERE id IN (' . $this->placeholders($ids) . ') FOR UPDATE'
        );
        $stmt->execute($ids);

        $forms = [];
        foreach ($stmt->fetchAll() as $row) {
            $forms[(int) $row['id']] = $row['form_id'] === null ? null : (int) $row['form_id'];
        }

        return $forms;
    }

    /**
     * @param list<int> $ids
     */
    public function setReadStateForMany(array $ids, bool $isRead): void
    {
        if ($ids === []) {
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE form_submissions SET is_read = ?, updated_at = NOW() WHERE id IN (' . $this->placeholders($ids) . ')'
        );
        $stmt->execute([$isRead ? 1 : 0, ...$ids]);
    }

    /**
     * Every file of these submissions, read before they are deleted: the
     * cascade removes the rows, never the files (delete()).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function attachmentsForMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM form_submission_attachments WHERE submission_id IN (' . $this->placeholders($ids) . ') ORDER BY id ASC'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /**
     * delete() for a whole selection, in one statement. Values and
     * attachment rows cascade; the files are the caller's job.
     *
     * @param list<int> $ids
     */
    public function deleteMany(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $stmt = $this->db->prepare('DELETE FROM form_submissions WHERE id IN (' . $this->placeholders($ids) . ')');
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /**
     * @param list<int> $ids
     */
    private function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
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
