<?php

namespace App\Repository;

/**
 * All product_options / product_option_values SQL lives here. An "option" is
 * a selectable group on a product (e.g. "Kleur"); each option has one or more
 * "values" (e.g. "Noten", "Berken"). Generic on purpose — nothing here
 * assumes a specific option like colour, so a future option (e.g. "Maat")
 * needs no schema/repository change. See MAIN.MD.
 */
class ProductOptionRepository extends Repository
{
    /**
     * Options for a product, each with its values nested under `values`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByProductId(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_id, name, display_type, sort_order
             FROM product_options
             WHERE product_id = :product_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $options = $stmt->fetchAll();

        foreach ($options as &$option) {
            $option['values'] = $this->findValuesByOptionId((int) $option['id']);
        }
        unset($option);

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findValuesByOptionId(int $optionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_option_id, value, hex_color, sort_order
             FROM product_option_values
             WHERE product_option_id = :product_option_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['product_option_id' => $optionId]);

        return $stmt->fetchAll();
    }

    public function findOptionById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, product_id, name, display_type, sort_order FROM product_options WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findValueById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, product_option_id, value, hex_color, sort_order FROM product_option_values WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param string $displayType one of DISPLAY_TYPES ('standard'/'color'), invalid values fall back to 'standard'
     */
    public function createOption(int $productId, string $name, string $displayType = 'standard'): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM product_options WHERE product_id = :product_id'
        );
        $stmt->execute(['product_id' => $productId]);
        $nextSortOrder = (int) $stmt->fetch()['next_sort_order'];

        $stmt = $this->db->prepare(
            'INSERT INTO product_options (product_id, name, display_type, sort_order, created_at, updated_at)
             VALUES (:product_id, :name, :display_type, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'product_id' => $productId,
            'name' => $name,
            'display_type' => self::normalizeDisplayType($displayType),
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateOption(int $id, string $name, string $displayType): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_options SET name = :name, display_type = :display_type, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'display_type' => self::normalizeDisplayType($displayType),
            'id' => $id,
        ]);
    }

    /** @var array<int, string> */
    public const DISPLAY_TYPES = ['standard', 'color'];

    public static function normalizeDisplayType(string $displayType): string
    {
        return in_array($displayType, self::DISPLAY_TYPES, true) ? $displayType : 'standard';
    }

    /**
     * Deleting an option cascades to its values. If any of those values is
     * still used by a variant, the RESTRICT on product_variant_values makes
     * the whole delete fail at the database level — isOptionInUse() below is
     * the friendly pre-check so the admin UI can explain why up front.
     */
    public function deleteOption(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM product_options WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function isOptionInUse(int $optionId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM product_variant_values vv
             JOIN product_option_values v ON v.id = vv.product_option_value_id
             WHERE v.product_option_id = :option_id
             LIMIT 1'
        );
        $stmt->execute(['option_id' => $optionId]);

        return $stmt->fetch() !== false;
    }

    public function isValueInUse(int $valueId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM product_variant_values WHERE product_option_value_id = :id LIMIT 1');
        $stmt->execute(['id' => $valueId]);

        return $stmt->fetch() !== false;
    }

    public function moveOption(int $productId, int $id, string $direction): void
    {
        $options = $this->findByProductId($productId);
        $this->swapSortOrder('product_options', $options, $id, $direction);
    }

    public function createValue(int $optionId, string $value, ?string $hexColor = null): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM product_option_values WHERE product_option_id = :option_id'
        );
        $stmt->execute(['option_id' => $optionId]);
        $nextSortOrder = (int) $stmt->fetch()['next_sort_order'];

        $stmt = $this->db->prepare(
            'INSERT INTO product_option_values (product_option_id, value, hex_color, sort_order, created_at, updated_at)
             VALUES (:product_option_id, :value, :hex_color, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            'product_option_id' => $optionId,
            'value' => $value,
            'hex_color' => $hexColor,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateValueText(int $id, string $value, ?string $hexColor = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_option_values SET value = :value, hex_color = :hex_color, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['value' => $value, 'hex_color' => $hexColor, 'id' => $id]);
    }

    public function deleteValue(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM product_option_values WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function moveValue(int $optionId, int $id, string $direction): void
    {
        $values = $this->findValuesByOptionId($optionId);
        $this->swapSortOrder('product_option_values', $values, $id, $direction);
    }

    /**
     * Shared "swap sort_order with the previous/next sibling" logic used by
     * both moveOption() and moveValue() — same approach as
     * ProductImageRepository::moveImage().
     *
     * @param array<int, array<string, mixed>> $orderedRows already sorted by sort_order ASC, id ASC
     */
    private function swapSortOrder(string $table, array $orderedRows, int $id, string $direction): void
    {
        $index = null;
        foreach ($orderedRows as $i => $row) {
            if ((int) $row['id'] === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($orderedRows)) {
            return;
        }

        $a = $orderedRows[$index];
        $b = $orderedRows[$swapWith];

        $stmt = $this->db->prepare("UPDATE {$table} SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['sort_order' => $b['sort_order'], 'id' => $a['id']]);
        $stmt->execute(['sort_order' => $a['sort_order'], 'id' => $b['id']]);
    }
}
