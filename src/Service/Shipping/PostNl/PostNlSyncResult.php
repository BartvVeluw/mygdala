<?php

namespace App\Service\Shipping\PostNl;

/**
 * The outcome of one PostNlRateSyncService::sync() run — rendered by the
 * admin "Carrier-tarieven" page ("PostNL rates checked. Changed: ... /
 * Unchanged: ... / Warnings: ...") and printed by
 * scripts/sync-postnl-rates.php for cron. Each array holds one human-readable
 * line per rate code, already formatted (e.g. "Briefpost t/m 20 g: €1,40 →
 * €1,50") so both callers can just print them without duplicating formatting
 * logic.
 */
final class PostNlSyncResult
{
    /**
     * @param array<int, string> $changed automatic rates whose price was updated
     * @param array<int, string> $unchanged rates checked and confirmed already correct
     * @param array<int, string> $pendingManual manual-mode rates where PostNL now shows a different price, not applied
     * @param array<int, string> $flaggedForReview automatic rates whose detected change exceeded the safety tolerance, not applied
     * @param array<int, string> $warnings anything else worth the admin's attention (e.g. a rate code that couldn't be found in the source at all)
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $changed = [],
        public readonly array $unchanged = [],
        public readonly array $pendingManual = [],
        public readonly array $flaggedForReview = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'changed' => $this->changed,
            'unchanged' => $this->unchanged,
            'pending_manual' => $this->pendingManual,
            'flagged_for_review' => $this->flaggedForReview,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * @param array<string, mixed> $data as produced by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            message: (string) ($data['message'] ?? ''),
            changed: $data['changed'] ?? [],
            unchanged: $data['unchanged'] ?? [],
            pendingManual: $data['pending_manual'] ?? [],
            flaggedForReview: $data['flagged_for_review'] ?? [],
            warnings: $data['warnings'] ?? [],
        );
    }
}
