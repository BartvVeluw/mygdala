<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL of the specification library and the values on products (Shop
 * Product & Ordering 2.0, MODULES.md "Specificaties"). The words — a
 * property's name, a value — are per website language and go through
 * App\Service\ShopLocalization, never through here.
 */
class ProductSpecificationRepository extends Repository
{
    /**
     * The library, in its order, each property with how many products use it.
     *
     * @return list<array{id: int, unit: string, sort_order: int, usage: int}>
     */
    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT s.id, s.unit, s.sort_order,
                    (SELECT COUNT(*) FROM product_specification_values v WHERE v.specification_id = s.id) AS usage_count
             FROM product_specifications s
             ORDER BY s.sort_order ASC, s.id ASC'
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'unit' => (string) ($row['unit'] ?? ''),
            'sort_order' => (int) $row['sort_order'],
            'usage' => (int) $row['usage_count'],
        ], $stmt->fetchAll());
    }

    public function create(?string $unit, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_specifications (unit, sort_order, created_at, updated_at) VALUES (:unit, :sort_order, NOW(), NOW())'
        );
        $stmt->execute(['unit' => $unit, 'sort_order' => $sortOrder]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, ?string $unit, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_specifications SET unit = :unit, sort_order = :sort_order, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['unit' => $unit, 'sort_order' => $sortOrder, 'id' => $id]);
    }

    /** A property, and with it its value on every product (the foreign keys cascade). */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_specifications WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * The properties on one product, in the product's order.
     *
     * @return list<array{id: int, specification_id: int, unit: string, sort_order: int}> id is the value row's
     */
    public function valuesForProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT v.id, v.specification_id, s.unit, v.sort_order
             FROM product_specification_values v
             INNER JOIN product_specifications s ON s.id = v.specification_id
             WHERE v.product_id = :product_id
             ORDER BY v.sort_order ASC, v.id ASC'
        );
        $stmt->execute(['product_id' => $productId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'specification_id' => (int) $row['specification_id'],
            'unit' => (string) ($row['unit'] ?? ''),
            'sort_order' => (int) $row['sort_order'],
        ], $stmt->fetchAll());
    }

    public function createValue(int $productId, int $specificationId, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_specification_values (product_id, specification_id, sort_order, created_at, updated_at)
             VALUES (:product_id, :specification_id, :sort_order, NOW(), NOW())'
        );
        $stmt->execute(['product_id' => $productId, 'specification_id' => $specificationId, 'sort_order' => $sortOrder]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A value row keeps the property it was made for (a different property
     * is a different row): only its place in the product's list changes.
     */
    public function updateValueOrder(int $id, int $productId, int $sortOrder): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_specification_values SET sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id AND product_id = :product_id'
        );
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id, 'product_id' => $productId]);
    }

    public function deleteValue(int $id, int $productId): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_specification_values WHERE id = :id AND product_id = :product_id');
        $stmt->execute(['id' => $id, 'product_id' => $productId]);
    }
}
