<?php

namespace App\Repository;

/**
 * All carrier_rate_sync_runs SQL lives here — a plain, append-only audit log
 * of PostNL sync attempts. See
 * db/migrations/20260907120000_create_carrier_rate_sync_runs_table.php and
 * App\Service\Shipping\PostNl\PostNlRateSyncService (the only writer).
 */
class CarrierRateSyncRunRepository extends Repository
{
    /**
     * @param array<string, mixed> $details structured changed/unchanged/warnings breakdown, stored as JSON
     */
    public function create(string $provider, string $status, ?string $message, array $details, ?string $triggeredBy, \DateTimeImmutable $ranAt): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO carrier_rate_sync_runs (provider, status, message, details_json, triggered_by, ran_at)
             VALUES (:provider, :status, :message, :details_json, :triggered_by, :ran_at)'
        );
        $stmt->execute([
            'provider' => $provider,
            'status' => $status,
            'message' => $message,
            'details_json' => json_encode($details, JSON_THROW_ON_ERROR),
            'triggered_by' => $triggeredBy,
            'ran_at' => $ranAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Most recent run for a provider — the admin "Carrier-tarieven" page's
     * "last sync" status line.
     *
     * @return array<string, mixed>|null
     */
    public function findMostRecentForProvider(string $provider): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, provider, status, message, details_json, triggered_by, ran_at
             FROM carrier_rate_sync_runs
             WHERE provider = :provider
             ORDER BY ran_at DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute(['provider' => $provider]);

        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, provider, status, message, details_json, triggered_by, ran_at
             FROM carrier_rate_sync_runs
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : self::normalize($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['details'] = $row['details_json'] !== null
            ? json_decode($row['details_json'], true, flags: JSON_THROW_ON_ERROR)
            : [];

        return $row;
    }
}
