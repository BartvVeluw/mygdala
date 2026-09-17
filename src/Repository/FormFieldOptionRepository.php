<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `form_field_options` SQL (db/migrations/20260918140000): the choices of
 * a select or radio field as rows, each with its language-neutral `value`
 * and its position. Their labels per language are
 * App\Service\Forms\FormLocalization's (`form_field_option_translations`).
 *
 * Deliberately dumb, like App\Repository\FormRepository: which rows to keep,
 * what a new option's value becomes and in which order they stand is decided
 * by api/admin/update-form-field.php. The schema holds what no caller can
 * walk past: one value once per field (UNIQUE, binary collation, the same
 * exact comparison validation makes) and no option without its field
 * (ON DELETE CASCADE).
 */
final class FormFieldOptionRepository extends Repository
{
    /**
     * The options of many fields in one query, each field's in display order.
     *
     * @param list<int> $fieldIds
     * @return array<int, list<array{id: int, value: string, sort_order: int}>> field id => options
     */
    public function findForFields(array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds), static fn (int $id): bool => $id > 0)));

        if ($fieldIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, form_field_id, value, sort_order FROM form_field_options
              WHERE form_field_id IN ({$placeholders})
              ORDER BY form_field_id ASC, sort_order ASC, id ASC"
        );
        $stmt->execute($fieldIds);

        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $options[(int) $row['form_field_id']][] = [
                'id' => (int) $row['id'],
                'value' => (string) $row['value'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }

        return $options;
    }

    /** @return int the new option's id */
    public function create(int $fieldId, string $value, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO form_field_options (form_field_id, value, sort_order, created_at, updated_at)
             VALUES (:form_field_id, :value, :sort_order, NOW(), NOW())'
        );
        $stmt->execute(['form_field_id' => $fieldId, 'value' => $value, 'sort_order' => $sortOrder]);

        return (int) $this->db->lastInsertId();
    }

    /** Moves one option of this field; an id of another field changes nothing. */
    public function setPosition(int $fieldId, int $optionId, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE form_field_options SET sort_order = :sort_order, updated_at = NOW()
              WHERE id = :id AND form_field_id = :form_field_id AND sort_order <> :sort_order_changed'
        );
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $optionId, 'form_field_id' => $fieldId, 'sort_order_changed' => $sortOrder]);
    }

    /** Deletes one option of this field, its labels with it (CASCADE). */
    public function delete(int $fieldId, int $optionId): void
    {
        $stmt = $this->db->prepare('DELETE FROM form_field_options WHERE id = :id AND form_field_id = :form_field_id');
        $stmt->execute(['id' => $optionId, 'form_field_id' => $fieldId]);
    }

    /** Deletes every option of a field: the confirmed loss of a type change. */
    public function deleteForField(int $fieldId): void
    {
        $stmt = $this->db->prepare('DELETE FROM form_field_options WHERE form_field_id = :form_field_id');
        $stmt->execute(['form_field_id' => $fieldId]);
    }
}
