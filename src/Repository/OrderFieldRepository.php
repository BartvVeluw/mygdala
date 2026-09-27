<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of a product's order questions (Shop Product & Ordering 2.0,
 * MODULES.md "Bestelvelden"): the "Bestelgegevens vragen" switch on the
 * product, its questions (`product_order_fields`) and the choices of a radio
 * or select question (`product_order_field_options`). Their words — label,
 * help text, a choice's label — are per website language and go through
 * App\Service\ShopLocalization, never through here.
 *
 * Every write names the product (or the question) it belongs to, so a key
 * from a request can never touch another product's rows.
 */
class OrderFieldRepository extends Repository
{
    public function isEnabled(int $productId): bool
    {
        $stmt = $this->db->prepare('SELECT order_fields_enabled FROM products WHERE id = :id');
        $stmt->execute(['id' => $productId]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function setEnabled(int $productId, bool $enabled): void
    {
        $stmt = $this->db->prepare('UPDATE products SET order_fields_enabled = :enabled WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $productId]);
    }

    /**
     * A product's questions in their order, each with its choices in theirs.
     *
     * @return list<array{id: int, product_id: int, field_type: string, is_required: bool, max_length: ?int, sort_order: int, options: list<array{id: int, sort_order: int}>}>
     */
    public function fieldsForProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, field_type, is_required, max_length, sort_order
             FROM product_order_fields
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        $fields = [];
        foreach ($stmt->fetchAll() as $row) {
            $fields[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'field_type' => (string) $row['field_type'],
                'is_required' => (int) $row['is_required'] === 1,
                'max_length' => $row['max_length'] !== null ? (int) $row['max_length'] : null,
                'sort_order' => (int) $row['sort_order'],
                'options' => [],
            ];
        }

        if ($fields !== []) {
            $placeholders = implode(',', array_fill(0, count($fields), '?'));
            $options = $this->db->prepare(
                "SELECT id, field_id, sort_order FROM product_order_field_options
                 WHERE field_id IN ({$placeholders})
                 ORDER BY sort_order ASC, id ASC"
            );
            $options->execute(array_keys($fields));
            foreach ($options->fetchAll() as $row) {
                $fields[(int) $row['field_id']]['options'][] = ['id' => (int) $row['id'], 'sort_order' => (int) $row['sort_order']];
            }
        }

        return array_values($fields);
    }

    public function createField(int $productId, string $type, bool $required, ?int $maxLength, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_order_fields (product_id, field_type, is_required, max_length, sort_order, created_at, updated_at)
             VALUES (:product_id, :field_type, :is_required, :max_length, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'field_type' => $type,
            'is_required' => $required ? 1 : 0,
            'max_length' => $maxLength,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateField(int $id, int $productId, string $type, bool $required, ?int $maxLength, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_order_fields
             SET field_type = :field_type, is_required = :is_required, max_length = :max_length, sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id AND product_id = :product_id'
        );
        $stmt->execute([
            'field_type' => $type,
            'is_required' => $required ? 1 : 0,
            'max_length' => $maxLength,
            'sort_order' => $sortOrder,
            'id' => $id,
            'product_id' => $productId,
        ]);
    }

    /** A question and, with it, its choices and all their words (the foreign keys cascade). */
    public function deleteField(int $id, int $productId): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_order_fields WHERE id = :id AND product_id = :product_id');
        $stmt->execute(['id' => $id, 'product_id' => $productId]);
    }

    public function createOption(int $fieldId, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_order_field_options (field_id, sort_order, created_at, updated_at)
             VALUES (:field_id, :sort_order, NOW(), NOW())'
        );
        $stmt->execute(['field_id' => $fieldId, 'sort_order' => $sortOrder]);

        return (int) $this->db->lastInsertId();
    }

    public function updateOptionOrder(int $id, int $fieldId, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_order_field_options SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND field_id = :field_id'
        );
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id, 'field_id' => $fieldId]);
    }

    public function deleteOption(int $id, int $fieldId): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_order_field_options WHERE id = :id AND field_id = :field_id');
        $stmt->execute(['id' => $id, 'field_id' => $fieldId]);
    }
}
