<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The order details a customer gave for an order line (`order_item_fields`,
 * Shop Product & Ordering 2.0, MODULES.md "Bestelvelden"): a SNAPSHOT. Each
 * row holds the question as it was asked and the answer as it was given, in
 * the default website language, written once in the checkout's transaction
 * (api/checkout.php). Nothing reads the product's questions to show an
 * order: a question that is renamed, changed or deleted later never changes
 * what an order says.
 */
class OrderItemFieldRepository extends Repository
{
    /**
     * @param list<array{field_id: ?int, field_type: string, label: string, value: string, option_id: ?int}> $answers in the order they were asked
     * @return list<int> the new rows' ids, in the same order
     */
    public function create(int $orderItemId, array $answers): array
    {
        $ids = [];
        $stmt = $this->db->prepare(
            'INSERT INTO order_item_fields (order_item_id, field_id, field_type, label, value, option_id, sort_order, created_at)
             VALUES (:order_item_id, :field_id, :field_type, :label, :value, :option_id, :sort_order, NOW())'
        );

        foreach (array_values($answers) as $position => $answer) {
            $stmt->execute([
                'order_item_id' => $orderItemId,
                'field_id' => $answer['field_id'],
                'field_type' => $answer['field_type'],
                'label' => mb_substr($answer['label'], 0, 150),
                'value' => $answer['value'],
                'option_id' => $answer['option_id'],
                'sort_order' => $position,
            ]);
            $ids[] = (int) $this->db->lastInsertId();
        }

        return $ids;
    }

    /**
     * Every answer of an order, by order line, in the order asked. A
     * picture's answer carries its private upload (`upload`: id, filename,
     * MIME type, size, dimensions) for the order screen; null for any other
     * answer.
     *
     * @return array<int, list<array{label: string, value: string, field_type: string, upload: ?array{id: int, original_filename: string, mime_type: string, byte_size: int, width: int, height: int}}>> order_item_id => answers
     */
    public function findByOrderIdGrouped(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.order_item_id, f.label, f.value, f.field_type,
                    u.id AS upload_id, u.original_filename, u.mime_type, u.byte_size, u.image_width, u.image_height
             FROM order_item_fields f
             INNER JOIN order_items oi ON oi.id = f.order_item_id
             LEFT JOIN order_field_uploads u ON u.order_item_field_id = f.id
             WHERE oi.order_id = :order_id
             ORDER BY f.order_item_id ASC, f.sort_order ASC, f.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['order_item_id']][] = [
                'label' => (string) $row['label'],
                'value' => (string) $row['value'],
                'field_type' => (string) $row['field_type'],
                'upload' => $row['upload_id'] !== null ? [
                    'id' => (int) $row['upload_id'],
                    'original_filename' => (string) $row['original_filename'],
                    'mime_type' => (string) $row['mime_type'],
                    'byte_size' => (int) $row['byte_size'],
                    'width' => (int) $row['image_width'],
                    'height' => (int) $row['image_height'],
                ] : null,
            ];
        }

        return $grouped;
    }
}
