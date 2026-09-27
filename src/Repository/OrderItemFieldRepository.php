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
     */
    public function create(int $orderItemId, array $answers): void
    {
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
        }
    }

    /**
     * Every answer of an order, by order line, in the order asked.
     *
     * @return array<int, list<array{label: string, value: string, field_type: string}>> order_item_id => answers
     */
    public function findByOrderIdGrouped(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.order_item_id, f.label, f.value, f.field_type
             FROM order_item_fields f
             INNER JOIN order_items oi ON oi.id = f.order_item_id
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
            ];
        }

        return $grouped;
    }
}
