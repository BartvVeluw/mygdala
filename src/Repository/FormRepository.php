<?php

namespace App\Repository;

/**
 * All `forms` and `form_fields` SQL — the definitions half of Core Forms
 * (db/migrations/20260909300000_create_the_core_forms_tables.php,
 * App\Service\Forms\FormCatalog). Submissions live in
 * App\Repository\FormSubmissionRepository; the two are separate because they
 * are read by different screens under different permissions (FORMS.md,
 * "Rechten").
 *
 * Ordinary repository shape for this project: every statement prepared, no
 * value interpolated into SQL, and nothing here decides anything — a caller
 * that wants a FormDefinition asks FormCatalog, which asks this class for
 * rows.
 *
 * NO WORDS HERE since Multilingual 2.0 phase 4: a form's submit label and
 * thank-you message, a field's label, placeholder and help text, and an
 * option's label are stored per website language through
 * App\Service\Forms\FormLocalization; a field's options are rows of
 * App\Repository\FormFieldOptionRepository.
 */
class FormRepository extends Repository
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByInternalKey(string $internalKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM forms WHERE internal_key = :key LIMIT 1');
        $stmt->execute(['key' => $internalKey]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Every form, newest name-ordered for the admin overview.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM forms ORDER BY name ASC, id ASC')->fetchAll();
    }

    /**
     * The forms an editor may pick in a form block: active ones only, plus
     * whichever form the block already points at, so an accidentally
     * deactivated form does not silently vanish from its own block's
     * dropdown.
     *
     * @return array<int, array<string, mixed>>
     */
    public function selectable(?int $includeId = null): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM forms WHERE is_active = 1 OR id = :include ORDER BY name ASC, id ASC'
        );
        $stmt->execute(['include' => $includeId ?? 0]);

        return $stmt->fetchAll();
    }

    /**
     * Field rows of one form, in display order. `sort_order` first and `id`
     * as the tie-breaker, so two fields that were given the same position
     * still come back in a stable order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldsFor(int $formId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM form_fields WHERE form_id = :form_id ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['form_id' => $formId]);

        return $stmt->fetchAll();
    }

    /**
     * Field rows for several forms at once — one query, so the admin
     * overview never runs a query per row.
     *
     * @param list<int> $formIds
     * @return array<int, array<int, array<string, mixed>>> keyed by form id
     */
    public function fieldsForMany(array $formIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $formIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT * FROM form_fields WHERE form_id IN (' . $placeholders . ') ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($ids);

        $byForm = [];
        foreach ($stmt->fetchAll() as $row) {
            $byForm[(int) $row['form_id']][] = $row;
        }

        return $byForm;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findField(int $fieldId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM form_fields WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $fieldId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, mixed> $values
     * @return int the new form's id
     */
    public function create(array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO forms
                (name, internal_key, is_active, notification_email,
                 reply_to_field_key, store_submissions, created_at, updated_at)
             VALUES
                (:name, :internal_key, :is_active, :notification_email,
                 :reply_to_field_key, :store_submissions, NOW(), NOW())'
        );

        $stmt->execute($this->formParameters($values) + ['internal_key' => (string) $values['internal_key']]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE forms SET
                name = :name,
                is_active = :is_active,
                notification_email = :notification_email,
                reply_to_field_key = :reply_to_field_key,
                store_submissions = :store_submissions,
                updated_at = NOW()
              WHERE id = :id'
        );

        $stmt->execute($this->formParameters($values) + ['id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM forms WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether an internal key is already taken — the generator in
     * App\Service\Forms\FormCatalog uses it to add a suffix.
     */
    public function internalKeyExists(string $internalKey): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM forms WHERE internal_key = :key LIMIT 1');
        $stmt->execute(['key' => $internalKey]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $values
     * @return int the new field's id
     */
    public function createField(int $formId, array $values): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO form_fields
                (form_id, field_key, field_type, is_required, sort_order, default_value,
                 created_at, updated_at)
             VALUES
                (:form_id, :field_key, :field_type, :is_required, :sort_order, :default_value,
                 NOW(), NOW())'
        );

        $stmt->execute($this->fieldParameters($values) + [
            'form_id' => $formId,
            'field_key' => (string) $values['field_key'],
            'field_type' => (string) $values['field_type'],
            'sort_order' => (int) ($values['sort_order'] ?? $this->nextFieldPosition($formId)),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates everything about a field EXCEPT its key and its position. The
     * key is immutable once answers have been filed under it, and the
     * position is moved with moveField().
     *
     * @param array<string, mixed> $values
     */
    public function updateField(int $fieldId, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE form_fields SET
                field_type = :field_type,
                is_required = :is_required,
                default_value = :default_value,
                updated_at = NOW()
              WHERE id = :id'
        );

        $stmt->execute($this->fieldParameters($values) + [
            'id' => $fieldId,
            'field_type' => (string) $values['field_type'],
        ]);
    }

    public function deleteField(int $fieldId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM form_fields WHERE id = :id');
        $stmt->execute(['id' => $fieldId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps a field with its neighbour in the given direction. Positions are
     * renumbered first, so a list that grew gaps (or duplicates) through
     * deletions still moves one step at a time.
     */
    public function moveField(int $formId, int $fieldId, string $direction): void
    {
        $this->renumberFields($formId);

        $fields = $this->fieldsFor($formId);
        $index = null;
        foreach ($fields as $position => $field) {
            if ((int) $field['id'] === $fieldId) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swapWith < 0 || $swapWith >= count($fields)) {
            return;
        }

        $stmt = $this->db->prepare('UPDATE form_fields SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $swapWith, 'id' => $fieldId]);
        $stmt->execute(['sort_order' => $index, 'id' => (int) $fields[$swapWith]['id']]);
    }

    /** Closes gaps so `sort_order` is 0..n-1 in the current display order. */
    public function renumberFields(int $formId): void
    {
        $stmt = $this->db->prepare('UPDATE form_fields SET sort_order = :sort_order WHERE id = :id');

        foreach ($this->fieldsFor($formId) as $position => $field) {
            if ((int) $field['sort_order'] !== $position) {
                $stmt->execute(['sort_order' => $position, 'id' => (int) $field['id']]);
            }
        }
    }

    public function nextFieldPosition(int $formId): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM form_fields WHERE form_id = :form_id');
        $stmt->execute(['form_id' => $formId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Clears the form's Reply-To setting when it points at this field —
     * called just before the field is deleted, so the setting can never
     * outlive the field it names.
     */
    public function clearReplyToField(int $formId, string $fieldKey): void
    {
        $stmt = $this->db->prepare(
            'UPDATE forms SET reply_to_field_key = NULL, updated_at = NOW()
              WHERE id = :form_id AND reply_to_field_key = :field_key'
        );
        $stmt->execute(['form_id' => $formId, 'field_key' => $fieldKey]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function formParameters(array $values): array
    {
        return [
            'name' => (string) ($values['name'] ?? ''),
            'is_active' => !empty($values['is_active']) ? 1 : 0,
            'notification_email' => self::nullIfEmpty($values['notification_email'] ?? null),
            'reply_to_field_key' => self::nullIfEmpty($values['reply_to_field_key'] ?? null),
            'store_submissions' => !empty($values['store_submissions']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function fieldParameters(array $values): array
    {
        return [
            'is_required' => !empty($values['is_required']) ? 1 : 0,
            // The pre-selected option VALUE of a choice field. Validated against
            // that field's own option list before it ever gets here — see
            // api/admin/update-form-field.php and App\Service\Forms\FormField.
            'default_value' => self::nullIfEmpty($values['default_value'] ?? null),
        ];
    }

    private static function nullIfEmpty(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return ($value !== null && $value !== '') ? $value : null;
    }
}
