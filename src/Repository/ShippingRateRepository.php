<?php

namespace App\Repository;

/**
 * All shipping_rates SQL lives here. One row = one weight bracket for one
 * shipping profile within one zone — see
 * db/migrations/20260906130000_create_shipping_rates_table.php for the
 * weight-matching rule and App\Service\Shipping\ShippingCalculationService
 * for where it's applied.
 *
 * A row's *effective* price is either its own `price` column (a fixed/manual
 * rate — the original, still fully supported model) or, when
 * `carrier_rate_id` is set, the live price of the linked `carrier_rates` row
 * (see 20260907100000_create_carrier_rates_table.php /
 * 20260907110000_add_carrier_rate_id_to_shipping_rates.php). Every query
 * below computes that effective price in SQL so callers — most importantly
 * ShippingCalculationService — keep reading a plain `price` key and never
 * need to know which of the two models a given row uses. A linked row whose
 * carrier rate has been deactivated (`carrier_rates.is_active = 0`) is
 * treated as if the shipping_rates row itself were disabled, matching the
 * existing `enabled` semantics.
 */
class ShippingRateRepository extends Repository
{
    private const SELECT_WITH_EFFECTIVE_PRICE = 'sr.id, sr.shipping_zone_id, sr.shipping_profile,
        sr.min_weight_grams, sr.max_weight_grams,
        CASE WHEN sr.carrier_rate_id IS NOT NULL THEN cr.price ELSE sr.price END AS price,
        sr.enabled, sr.sort_order, sr.carrier_rate_id,
        cr.rate_code AS carrier_rate_code, cr.label AS carrier_rate_label,
        cr.is_active AS carrier_rate_is_active, cr.mode AS carrier_rate_mode,
        cr.last_checked_at AS carrier_rate_last_checked_at
        FROM shipping_rates sr
        LEFT JOIN carrier_rates cr ON cr.id = sr.carrier_rate_id';

    /**
     * Every enabled rate for a zone, across all shipping profiles — the
     * calculator filters by profile itself (see
     * ShippingCalculationService::pickRate()), so a single query here
     * covers every profile in one round-trip.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findEnabledForZone(int $zoneId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_WITH_EFFECTIVE_PRICE . '
             WHERE sr.shipping_zone_id = :zone_id AND sr.enabled = 1
               AND (sr.carrier_rate_id IS NULL OR cr.is_active = 1)'
        );
        $stmt->execute(['zone_id' => $zoneId]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll());
    }

    /**
     * Admin overview for one zone: every rate (enabled or not), ordered for
     * display — by profile, then by how "small" the bracket is (ascending
     * max weight, unlimited/flat rates last), then by the admin's own
     * sort_order as a tie-breaker. Unlike findEnabledForZone(), a row linked
     * to a deactivated carrier rate is still included (with its live,
     * inactive-carrier price) so the admin can see and fix it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForZone(int $zoneId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_WITH_EFFECTIVE_PRICE . '
             WHERE sr.shipping_zone_id = :zone_id
             ORDER BY sr.shipping_profile ASC, (sr.max_weight_grams IS NULL) ASC, sr.max_weight_grams ASC, sr.sort_order ASC, sr.id ASC'
        );
        $stmt->execute(['zone_id' => $zoneId]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::SELECT_WITH_EFFECTIVE_PRICE . '
             WHERE sr.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    /**
     * @param array{shipping_zone_id:int, shipping_profile:string, min_weight_grams:?int, max_weight_grams:?int, price:float, enabled:bool, sort_order:int, carrier_rate_id?:?int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO shipping_rates
                (shipping_zone_id, shipping_profile, min_weight_grams, max_weight_grams, price, enabled, sort_order, carrier_rate_id, created_at, updated_at)
             VALUES
                (:shipping_zone_id, :shipping_profile, :min_weight_grams, :max_weight_grams, :price, :enabled, :sort_order, :carrier_rate_id, NOW(), NOW())'
        );
        $stmt->execute([
            'shipping_zone_id' => $data['shipping_zone_id'],
            'shipping_profile' => $data['shipping_profile'],
            'min_weight_grams' => $data['min_weight_grams'],
            'max_weight_grams' => $data['max_weight_grams'],
            'price' => number_format($data['price'], 2, '.', ''),
            'enabled' => $data['enabled'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
            'carrier_rate_id' => $data['carrier_rate_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array{shipping_profile:string, min_weight_grams:?int, max_weight_grams:?int, price:float, enabled:bool, sort_order:int, carrier_rate_id?:?int} $data
     */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE shipping_rates SET
                shipping_profile = :shipping_profile,
                min_weight_grams = :min_weight_grams,
                max_weight_grams = :max_weight_grams,
                price = :price,
                enabled = :enabled,
                sort_order = :sort_order,
                carrier_rate_id = :carrier_rate_id,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'shipping_profile' => $data['shipping_profile'],
            'min_weight_grams' => $data['min_weight_grams'],
            'max_weight_grams' => $data['max_weight_grams'],
            'price' => number_format($data['price'], 2, '.', ''),
            'enabled' => $data['enabled'] ? 1 : 0,
            'sort_order' => $data['sort_order'],
            'carrier_rate_id' => $data['carrier_rate_id'] ?? null,
            'id' => $id,
        ]);
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $stmt = $this->db->prepare('UPDATE shipping_rates SET enabled = :enabled, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM shipping_rates WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['min_weight_grams'] = $row['min_weight_grams'] !== null ? (int) $row['min_weight_grams'] : null;
        $row['max_weight_grams'] = $row['max_weight_grams'] !== null ? (int) $row['max_weight_grams'] : null;
        $row['enabled'] = (bool) $row['enabled'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['carrier_rate_id'] = isset($row['carrier_rate_id']) && $row['carrier_rate_id'] !== null ? (int) $row['carrier_rate_id'] : null;
        if (array_key_exists('carrier_rate_is_active', $row)) {
            $row['carrier_rate_is_active'] = $row['carrier_rate_is_active'] !== null ? (bool) $row['carrier_rate_is_active'] : null;
        }

        return $row;
    }
}
