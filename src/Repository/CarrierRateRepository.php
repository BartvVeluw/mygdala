<?php

namespace App\Repository;

/**
 * All carrier_rates SQL lives here — see
 * db/migrations/20260907100000_create_carrier_rates_table.php for the model
 * (automatic vs. manual mode, pending_price for a sync-detected value that
 * wasn't applied) and App\Service\Shipping\PostNl\PostNlRateSyncService for
 * the only writer of the sync-specific columns.
 */
class CarrierRateRepository extends Repository
{
    private const COLUMNS = 'id, provider, rate_code, label, price, currency, mode,
                              pending_price, pending_detected_at, needs_review, is_active,
                              last_checked_at, created_at, updated_at';

    /**
     * Admin "Carrier-tarieven" overview: every rate for a provider, most
     * recently updated first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForProvider(string $provider): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM carrier_rates
             WHERE provider = :provider
             ORDER BY rate_code ASC'
        );
        $stmt->execute(['provider' => $provider]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM carrier_rates WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    public function findByRateCode(string $rateCode): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM carrier_rates WHERE rate_code = :rate_code LIMIT 1');
        $stmt->execute(['rate_code' => $rateCode]);

        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    /**
     * Used by the admin "Verzendinstellingen" rate form to populate the
     * "carrier rate" dropdown — only active rates are offered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveForProvider(string $provider): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM carrier_rates
             WHERE provider = :provider AND is_active = 1
             ORDER BY rate_code ASC'
        );
        $stmt->execute(['provider' => $provider]);

        return array_map([self::class, 'normalize'], $stmt->fetchAll());
    }

    /**
     * Admin edit: mode, price (a manual price edit — see class docblock),
     * and active state. Never touches the sync-only columns.
     *
     * @param array{mode:string, price:float, is_active:bool} $data
     */
    public function updateManual(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carrier_rates SET
                mode = :mode, price = :price, is_active = :is_active, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'mode' => $data['mode'],
            'price' => number_format($data['price'], 2, '.', ''),
            'is_active' => $data['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Admin accepts a sync-detected price that was held back (manual mode or
     * a large-change review flag) — promotes pending_price to price and
     * clears the pending state.
     */
    public function applyPendingPrice(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE carrier_rates SET
                price = pending_price, pending_price = NULL, pending_detected_at = NULL,
                needs_review = 0, updated_at = NOW()
             WHERE id = :id AND pending_price IS NOT NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Sync applied a new, trusted price (automatic mode, change within
     * tolerance) — updates the live price and clears any stale pending state.
     */
    public function applySyncedPrice(int $id, float $price, \DateTimeImmutable $checkedAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carrier_rates SET
                price = :price, pending_price = NULL, pending_detected_at = NULL,
                needs_review = 0, last_checked_at = :checked_at, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'price' => number_format($price, 2, '.', ''),
            'checked_at' => $checkedAt->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /**
     * Sync saw a price it will not apply automatically — either because the
     * rate is in manual mode, or because the change looked implausible
     * ($needsReview = true). The current `price` is left completely
     * untouched; only the "here's what we saw" pending fields are recorded.
     */
    public function recordPendingPrice(int $id, float $pendingPrice, \DateTimeImmutable $checkedAt, bool $needsReview): void
    {
        $stmt = $this->db->prepare(
            'UPDATE carrier_rates SET
                pending_price = :pending_price, pending_detected_at = :checked_at,
                needs_review = :needs_review, last_checked_at = :checked_at_2
             WHERE id = :id'
        );
        $stmt->execute([
            'pending_price' => number_format($pendingPrice, 2, '.', ''),
            'checked_at' => $checkedAt->format('Y-m-d H:i:s'),
            'checked_at_2' => $checkedAt->format('Y-m-d H:i:s'),
            'needs_review' => $needsReview ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Sync confirmed the price is unchanged — no price/pending write needed,
     * just record that the source was checked.
     */
    public function touchLastChecked(int $id, \DateTimeImmutable $checkedAt): void
    {
        $stmt = $this->db->prepare('UPDATE carrier_rates SET last_checked_at = :checked_at WHERE id = :id');
        $stmt->execute(['checked_at' => $checkedAt->format('Y-m-d H:i:s'), 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['price'] = (float) $row['price'];
        $row['pending_price'] = $row['pending_price'] !== null ? (float) $row['pending_price'] : null;
        $row['needs_review'] = (bool) $row['needs_review'];
        $row['is_active'] = (bool) $row['is_active'];

        return $row;
    }
}
