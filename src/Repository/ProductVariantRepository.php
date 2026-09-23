<?php

namespace App\Repository;

/**
 * All product_variants / product_variant_values SQL lives here. A variant is
 * a purchasable combination of exactly one product_option_value per option
 * group belonging to the product, with an optional price override. Photos
 * are pictures of the product that the variant links to
 * (ProductVariantImageRepository) — a variant's `images` are always attached
 * here, sorted, so the first one is its default photo.
 */
class ProductVariantRepository extends Repository
{
    /**
     * All variants for a product (admin), each with its selected values under
     * `values` (id, option_id, option_name, value).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, price, active, sort_order
             FROM product_variants
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $variants = $stmt->fetchAll();

        return $this->attachRelations($variants);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, price, active, sort_order
             FROM product_variants
             WHERE product_id = :product_id AND active = 1
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $variants = $stmt->fetchAll();

        return $this->attachRelations($variants);
    }

    /**
     * The default variant for a product: the first active variant by
     * sort_order, with its option values and images attached. Returns null
     * for a product with no active variants at all.
     */
    public function findDefaultForProduct(int $productId): ?array
    {
        return $this->findActiveByProductId($productId)[0] ?? null;
    }

    /**
     * Adds `values` (selected option values) and `images` (the product
     * pictures this variant shows, in its own order — the first one is its
     * default picture; ProductVariantImageRepository) to each variant row. An
     * empty `images` means the variant chose none and shows every picture of
     * its product. One batched images query for the whole set instead of
     * N+1.
     *
     * @param array<int, array<string, mixed>> $variants
     * @return array<int, array<string, mixed>>
     */
    private function attachRelations(array $variants): array
    {
        if ($variants === []) {
            return $variants;
        }

        $imagesByVariant = (new ProductVariantImageRepository($this->db))->findByVariantIds(
            array_map(static fn (array $v): int => (int) $v['id'], $variants)
        );

        foreach ($variants as &$variant) {
            $variantId = (int) $variant['id'];
            $variant['values'] = $this->findValuesForVariant($variantId);
            $variant['images'] = $imagesByVariant[$variantId] ?? [];
        }
        unset($variant);

        return $variants;
    }

    /**
     * @return array<int, array<string, mixed>> id, option_id, option_name, value_id, value
     */
    private function findValuesForVariant(int $variantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.id AS option_id, o.name AS option_name, v.id AS value_id, v.value
             FROM product_variant_values vv
             JOIN product_option_values v ON v.id = vv.product_option_value_id
             JOIN product_options o ON o.id = v.product_option_id
             WHERE vv.variant_id = :variant_id
             ORDER BY o.sort_order ASC, o.id ASC'
        );
        $stmt->execute(['variant_id' => $variantId]);

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, price, active, sort_order FROM product_variants WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $row['images'] = (new ProductVariantImageRepository($this->db))->findByVariantIds([$id])[$id] ?? [];

        return $row;
    }

    /**
     * Used by checkout: the variant must exist, belong to the given product,
     * and be active. Never trust a variant id/price sent by the frontend
     * beyond this lookup.
     */
    public function findActiveForProduct(int $variantId, int $productId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, price, active
             FROM product_variants
             WHERE id = :id AND product_id = :product_id AND active = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $variantId, 'product_id' => $productId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Human-readable snapshot label, e.g. "Kleur: Noten" or
     * "Kleur: Noten, Maat: L" for multiple option groups — stored on
     * order_items so historical orders stay understandable even if the
     * option/value is later renamed. Returns null if the variant has no
     * values (shouldn't normally happen).
     */
    public function buildLabel(int $variantId): ?string
    {
        $values = $this->findValuesForVariant($variantId);
        if ($values === []) {
            return null;
        }

        return implode(', ', array_map(
            static fn (array $v): string => $v['option_name'] . ': ' . $v['value'],
            $values
        ));
    }

    /**
     * True if a variant already exists for this product with exactly this
     * set of option values — prevents creating duplicate combinations.
     *
     * @param array<int, int> $valueIds
     */
    public function comboExists(int $productId, array $valueIds, ?int $excludeVariantId = null): bool
    {
        $valueIds = array_values(array_unique(array_map('intval', $valueIds)));
        if ($valueIds === []) {
            return false;
        }

        foreach ($this->findByProductId($productId) as $variant) {
            if ($excludeVariantId !== null && (int) $variant['id'] === $excludeVariantId) {
                continue;
            }
            $existingIds = array_map(static fn (array $v): int => (int) $v['value_id'], $variant['values']);
            sort($existingIds);
            $compare = $valueIds;
            sort($compare);
            if ($existingIds === $compare) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $valueIds exactly one value id per option group of the product
     */
    public function create(int $productId, array $valueIds, ?float $price, bool $active): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM product_variants WHERE product_id = :product_id'
        );
        $stmt->execute(['product_id' => $productId]);
        $nextSortOrder = (int) $stmt->fetch()['next_sort_order'];

        $stmt = $this->db->prepare(
            'INSERT INTO product_variants (product_id, price, active, sort_order, created_at, updated_at)
             VALUES (:product_id, :price, :active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'price' => $price !== null ? number_format($price, 2, '.', '') : null,
            'active' => $active ? 1 : 0,
            'sort_order' => $nextSortOrder,
        ]);
        $variantId = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare(
            'INSERT INTO product_variant_values (variant_id, product_option_value_id) VALUES (:variant_id, :value_id)'
        );
        foreach ($valueIds as $valueId) {
            $stmt->execute(['variant_id' => $variantId, 'value_id' => $valueId]);
        }

        return $variantId;
    }

    public function updatePriceAndActive(int $id, ?float $price, bool $active): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_variants SET price = :price, active = :active, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'price' => $price !== null ? number_format($price, 2, '.', '') : null,
            'active' => $active ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->db->prepare('UPDATE product_variants SET active = :active, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function isReferencedByOrders(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM order_items WHERE variant_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM product_variants WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function move(int $productId, int $id, string $direction): void
    {
        $variants = $this->findByProductId($productId);

        $index = null;
        foreach ($variants as $i => $variant) {
            if ((int) $variant['id'] === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($variants)) {
            return;
        }

        $a = $variants[$index];
        $b = $variants[$swapWith];

        $stmt = $this->db->prepare('UPDATE product_variants SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $b['sort_order'], 'id' => $a['id']]);
        $stmt->execute(['sort_order' => $a['sort_order'], 'id' => $b['id']]);
    }
}
