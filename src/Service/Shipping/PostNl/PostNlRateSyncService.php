<?php

namespace App\Service\Shipping\PostNl;

use App\Repository\CarrierRateRepository;
use App\Repository\CarrierRateSyncRunRepository;

/**
 * Orchestrates one PostNL rate sync: fetch -> parse -> validate -> apply.
 * Used by both scripts/sync-postnl-rates.php (cron) and
 * api/admin/sync-postnl-rates.php (the admin "PostNL-tarieven nu bijwerken"
 * button) — one implementation, so a manual run and a scheduled run can
 * never disagree on what counts as safe to apply. See MAIN.MD "Veiligheid
 * bij synchronisatie" for the requirements this encodes.
 *
 * Every carrier_rates row is judged independently — one missing/implausible
 * rate code never blocks the others from updating, and a network/parse
 * failure updates nothing at all (never a partial guess). Checkout never
 * calls this class or the PostNL fetcher; it only ever reads the
 * already-stored `carrier_rates.price` via ShippingRateRepository, so this
 * entire pipeline being slow, down, or wrong can never affect checkout.
 */
class PostNlRateSyncService
{
    private const PROVIDER = 'postnl';

    /** Only rate codes an actual shipping rule uses today — see the carrier_rates seed migration. */
    private const EXPECTED_RATE_CODES = ['postnl_nl_letter_20g', 'postnl_nl_letter_50g', 'postnl_nl_parcel'];

    /** A sanity ceiling no real letter/parcel consumer tariff should ever exceed. */
    private const MAX_PLAUSIBLE_PRICE = 500.0;

    /**
     * How far a price may move in one sync before it's treated as
     * implausible and held for manual review instead of applied — e.g. the
     * MAIN.MD example of €1,40 jumping to €14,00 (900%) is nowhere near this
     * threshold. 30% comfortably covers a real annual PostNL price increase
     * (historically a few percent to occasionally ~10%) while still catching
     * a scraping error (wrong table row/column) or a genuine but exceptional
     * repricing that deserves a human look before it reaches checkout.
     */
    private const MAX_RELATIVE_CHANGE = 0.30;

    public function __construct(
        private readonly PostNlRateFetcher $fetcher = new PostNlRateFetcher(),
        private readonly PostNlRateParser $parser = new PostNlRateParser(),
        private readonly CarrierRateRepository $carrierRateRepository = new CarrierRateRepository(),
        private readonly CarrierRateSyncRunRepository $syncRunRepository = new CarrierRateSyncRunRepository(),
    ) {
    }

    /**
     * @param string $triggeredBy 'cron' or 'admin' — stored on the sync run for the admin overview
     * @param bool $dryRun when true, computes and returns the full result but never writes anything (no rate changes, no sync-run log entry) — used by scripts/sync-postnl-rates.php --dry-run to preview safely
     */
    public function sync(string $triggeredBy, bool $dryRun = false): PostNlSyncResult
    {
        $now = new \DateTimeImmutable();

        try {
            $text = $this->fetcher->fetchTariffText();
        } catch (PostNlFetchException $e) {
            error_log('[PostNlRateSyncService] fetch failed: ' . $e->getMessage());
            $result = new PostNlSyncResult(
                success: false,
                message: 'PostNL-tarieven konden niet worden opgehaald: ' . $e->getMessage() . '. Bestaande prijzen zijn ongewijzigd gebleven.',
            );
            if (!$dryRun) {
                $this->logRun('failed', $result, $triggeredBy, $now);
            }

            return $result;
        }

        $parsed = $this->parser->parse($text);

        $changed = [];
        $unchanged = [];
        $pendingManual = [];
        $flaggedForReview = [];
        $warnings = [];

        foreach (self::EXPECTED_RATE_CODES as $rateCode) {
            $carrierRate = $this->carrierRateRepository->findByRateCode($rateCode);
            if ($carrierRate === null) {
                $warnings[] = "Onbekende rate code '{$rateCode}' — geen carrier_rates-rij, overgeslagen.";
                continue;
            }

            $label = (string) $carrierRate['label'];
            $currentPrice = (float) $carrierRate['price'];

            if (!array_key_exists($rateCode, $parsed)) {
                $warnings[] = "{$label}: niet gevonden in de PostNL-bron — huidige prijs (€" . self::fmt($currentPrice) . ') ongewijzigd gelaten.';
                continue;
            }

            $newPrice = $parsed[$rateCode];

            if (!self::isPlausiblePrice($newPrice)) {
                $warnings[] = "{$label}: gevonden waarde (€" . self::fmt($newPrice) . ') is ongeldig — genegeerd, huidige prijs blijft staan.';
                continue;
            }

            if ($carrierRate['currency'] !== 'EUR') {
                $warnings[] = "{$label}: tarief staat niet in EUR — synchronisatie overgeslagen.";
                continue;
            }

            $carrierRateId = (int) $carrierRate['id'];
            $isUnchanged = abs($newPrice - $currentPrice) < 0.005;

            if ($isUnchanged) {
                $unchanged[] = "{$label}: €" . self::fmt($currentPrice);
                if (!$dryRun) {
                    $this->carrierRateRepository->touchLastChecked($carrierRateId, $now);
                }
                continue;
            }

            if ($carrierRate['mode'] === 'manual') {
                $pendingManual[] = "{$label}: PostNL geeft nu €" . self::fmt($newPrice) . ' (handmatige modus — huidige prijs €' . self::fmt($currentPrice) . ' blijft actief)';
                if (!$dryRun) {
                    $this->carrierRateRepository->recordPendingPrice($carrierRateId, $newPrice, $now, false);
                }
                continue;
            }

            if (!self::isWithinTolerance($currentPrice, $newPrice)) {
                $flaggedForReview[] = "{$label}: €" . self::fmt($currentPrice) . ' → €' . self::fmt($newPrice) . ' (grote wijziging — controle vereist, niet automatisch toegepast)';
                if (!$dryRun) {
                    $this->carrierRateRepository->recordPendingPrice($carrierRateId, $newPrice, $now, true);
                }
                continue;
            }

            $changed[] = "{$label}: €" . self::fmt($currentPrice) . ' → €' . self::fmt($newPrice);
            if (!$dryRun) {
                $this->carrierRateRepository->applySyncedPrice($carrierRateId, $newPrice, $now);
            }
        }

        // Nothing at all could be validated/applied — treat as a failed run
        // even though the fetch itself succeeded (e.g. PostNL reformatted
        // the document and none of our patterns matched anymore).
        $nothingUsable = $changed === [] && $unchanged === [] && $pendingManual === [] && $flaggedForReview === [];
        $status = $nothingUsable ? 'failed' : (($warnings !== [] || $flaggedForReview !== []) ? 'partial' : 'success');

        $result = new PostNlSyncResult(
            success: $status !== 'failed',
            message: self::buildMessage($status, $changed, $unchanged, $flaggedForReview, $warnings),
            changed: $changed,
            unchanged: $unchanged,
            pendingManual: $pendingManual,
            flaggedForReview: $flaggedForReview,
            warnings: $warnings,
        );

        if (!$dryRun) {
            $this->logRun($status, $result, $triggeredBy, $now);
        }

        return $result;
    }

    private static function isPlausiblePrice(float $price): bool
    {
        return $price > 0.0 && $price < self::MAX_PLAUSIBLE_PRICE;
    }

    private static function isWithinTolerance(float $currentPrice, float $newPrice): bool
    {
        $base = $currentPrice > 0.0 ? $currentPrice : 0.01;

        return abs($newPrice - $currentPrice) / $base <= self::MAX_RELATIVE_CHANGE;
    }

    /**
     * @param array<int, string> $changed
     * @param array<int, string> $unchanged
     * @param array<int, string> $flaggedForReview
     * @param array<int, string> $warnings
     */
    private static function buildMessage(string $status, array $changed, array $unchanged, array $flaggedForReview, array $warnings): string
    {
        if ($status === 'failed') {
            return 'PostNL-tarieven controleren is mislukt — geen van de verwachte tarieven kon worden gevalideerd. Bestaande prijzen zijn ongewijzigd gebleven.';
        }

        $parts = [
            'PostNL-tarieven gecontroleerd.',
            count($changed) . ' gewijzigd, ' . count($unchanged) . ' ongewijzigd.',
        ];
        if ($flaggedForReview !== []) {
            $parts[] = count($flaggedForReview) . ' wijziging(en) gemarkeerd voor controle (grote afwijking).';
        }
        if ($warnings !== []) {
            $parts[] = count($warnings) . ' waarschuwing(en).';
        }

        return implode(' ', $parts);
    }

    private static function fmt(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    private function logRun(string $status, PostNlSyncResult $result, string $triggeredBy, \DateTimeImmutable $now): void
    {
        try {
            $this->syncRunRepository->create(self::PROVIDER, $status, $result->message, $result->toArray(), $triggeredBy, $now);
        } catch (\Throwable $e) {
            error_log('[PostNlRateSyncService] failed to record sync run: ' . $e->getMessage());
        }
    }
}
