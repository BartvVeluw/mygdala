<?php

namespace App\Repository;

/**
 * All shipping_zones / shipping_zone_countries SQL lives here. A country
 * belongs to at most one zone (unique index on country_code), so destination
 * lookup is always unambiguous. See db/migrations/*_create_shipping_zones*
 * and MAIN.MD "Shipping zones".
 */
class ShippingZoneRepository extends Repository
{
    /**
     * Resolves a destination country to its shipping zone. Returns null when
     * the country isn't part of any configured zone — callers must treat
     * that as "shipping unavailable", never fall back to a default zone.
     */
    public function findZoneForCountry(string $countryCode): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT z.id, z.code, z.name
             FROM shipping_zones z
             JOIN shipping_zone_countries c ON c.shipping_zone_id = z.id
             WHERE c.country_code = :country
             LIMIT 1'
        );
        $stmt->execute(['country' => strtoupper(trim($countryCode))]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT id, code, name FROM shipping_zones WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Admin overview: every zone with the country codes assigned to it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithCountries(): array
    {
        $stmt = $this->db->query('SELECT id, code, name FROM shipping_zones ORDER BY sort_order ASC, id ASC');
        $zones = $stmt->fetchAll();

        $countryStmt = $this->db->prepare(
            'SELECT country_code FROM shipping_zone_countries WHERE shipping_zone_id = :zone_id ORDER BY country_code ASC'
        );

        foreach ($zones as &$zone) {
            $countryStmt->execute(['zone_id' => $zone['id']]);
            $zone['countries'] = array_column($countryStmt->fetchAll(), 'country_code');
        }
        unset($zone);

        return $zones;
    }
}
