<?php

declare(strict_types=1);

namespace Tests\Service\Shipping\PostNl;

use App\Repository\CarrierRateRepository;
use App\Repository\CarrierRateSyncRunRepository;
use App\Service\Shipping\PostNl\PostNlFetchException;
use App\Service\Shipping\PostNl\PostNlRateFetcher;
use App\Service\Shipping\PostNl\PostNlRateParser;
use App\Service\Shipping\PostNl\PostNlRateSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Covers MAIN.MD's PostNL-sync safety requirements ("Veiligheid bij
 * synchronisatie"): a fetch failure or missing/invalid data must never
 * overwrite an existing valid price, a manual-mode rate must never be
 * touched, and a too-large change must be held for review instead of
 * applied. Every collaborator (fetcher/parser/both repositories) is a
 * lightweight in-memory fake — same convention as
 * ShippingCalculationServiceTest — so this never touches the network or a
 * database.
 */
final class PostNlRateSyncServiceTest extends TestCase
{
    private function fetcherReturning(string $text): PostNlRateFetcher
    {
        return new class ($text) extends PostNlRateFetcher {
            public function __construct(private readonly string $text)
            {
            }

            public function fetchTariffText(): string
            {
                return $this->text;
            }
        };
    }

    private function fetcherThrowing(): PostNlRateFetcher
    {
        return new class extends PostNlRateFetcher {
            public function fetchTariffText(): string
            {
                throw new PostNlFetchException('simulated network failure');
            }
        };
    }

    private function parserReturning(array $rates): PostNlRateParser
    {
        return new class ($rates) extends PostNlRateParser {
            public function __construct(private readonly array $rates)
            {
            }

            public function parse(string $text): array
            {
                return $this->rates;
            }
        };
    }

    /**
     * @param array<string, array<string, mixed>> $initialRows keyed by rate_code
     */
    private function fakeCarrierRates(array $initialRows): CarrierRateRepository
    {
        return new class ($initialRows) extends CarrierRateRepository {
            /** @var array<string, array<string, mixed>> */
            public array $rows;
            /** @var array<int, string> method-call log, e.g. "applySyncedPrice:3:8.2" */
            public array $calls = [];

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function findByRateCode(string $rateCode): ?array
            {
                return $this->rows[$rateCode] ?? null;
            }

            public function touchLastChecked(int $id, \DateTimeImmutable $checkedAt): void
            {
                $this->calls[] = "touchLastChecked:{$id}";
                foreach ($this->rows as &$row) {
                    if ($row['id'] === $id) {
                        $row['last_checked_at'] = $checkedAt->format('Y-m-d H:i:s');
                    }
                }
            }

            public function applySyncedPrice(int $id, float $price, \DateTimeImmutable $checkedAt): void
            {
                $this->calls[] = "applySyncedPrice:{$id}:{$price}";
                foreach ($this->rows as &$row) {
                    if ($row['id'] === $id) {
                        $row['price'] = $price;
                        $row['pending_price'] = null;
                        $row['needs_review'] = false;
                        $row['last_checked_at'] = $checkedAt->format('Y-m-d H:i:s');
                    }
                }
            }

            public function recordPendingPrice(int $id, float $pendingPrice, \DateTimeImmutable $checkedAt, bool $needsReview): void
            {
                $this->calls[] = "recordPendingPrice:{$id}:{$pendingPrice}:" . ($needsReview ? '1' : '0');
                foreach ($this->rows as &$row) {
                    if ($row['id'] === $id) {
                        $row['pending_price'] = $pendingPrice;
                        $row['needs_review'] = $needsReview;
                        $row['last_checked_at'] = $checkedAt->format('Y-m-d H:i:s');
                    }
                }
            }
        };
    }

    private function fakeSyncRuns(): CarrierRateSyncRunRepository
    {
        return new class extends CarrierRateSyncRunRepository {
            /** @var array<int, array<string, mixed>> */
            public array $runs = [];

            public function __construct()
            {
            }

            public function create(string $provider, string $status, ?string $message, array $details, ?string $triggeredBy, \DateTimeImmutable $ranAt): int
            {
                $this->runs[] = compact('provider', 'status', 'message', 'details', 'triggeredBy');

                return count($this->runs);
            }
        };
    }

    /**
     * PostNlRateSyncService always walks its fixed 3-code expected list, so a
     * test that only registers one rate_code sees "unknown rate code"
     * warnings for the other two. Most tests don't care about that noise,
     * but a couple assert an exact warning/status count — those use this
     * full, valid baseline (all three "already correct") and override just
     * the one rate under test.
     *
     * @return array<string, array<string, mixed>>
     */
    private function baselineCarrierRateRows(): array
    {
        return [
            'postnl_nl_letter_20g' => $this->rate(1, '1.40'),
            'postnl_nl_letter_50g' => $this->rate(2, '2.80'),
            'postnl_nl_parcel' => $this->rate(3, '7.45'),
        ];
    }

    /** @return array<string, float> */
    private function baselineParsedRates(): array
    {
        return ['postnl_nl_letter_20g' => 1.40, 'postnl_nl_letter_50g' => 2.80, 'postnl_nl_parcel' => 7.45];
    }

    private function rate(int $id, string $price, string $mode = 'automatic', string $currency = 'EUR'): array
    {
        return [
            'id' => $id, 'provider' => 'postnl', 'rate_code' => 'x', 'label' => 'Test rate',
            'price' => (float) $price, 'currency' => $currency, 'mode' => $mode,
            'pending_price' => null, 'pending_detected_at' => null, 'needs_review' => false, 'is_active' => true,
            'last_checked_at' => null,
        ];
    }

    public function testFetchFailureLeavesExistingRatesIntactAndLogsAFailedRun(): void
    {
        $carrierRates = $this->fakeCarrierRates(['postnl_nl_parcel' => $this->rate(3, '7.45')]);
        $syncRuns = $this->fakeSyncRuns();

        $service = new PostNlRateSyncService($this->fetcherThrowing(), new PostNlRateParser(), $carrierRates, $syncRuns);
        $result = $service->sync('cron');

        $this->assertFalse($result->success);
        $this->assertSame([], $carrierRates->calls, 'no rate should have been touched');
        $this->assertCount(1, $syncRuns->runs);
        $this->assertSame('failed', $syncRuns->runs[0]['status']);
    }

    public function testValidChangeWithinToleranceIsAppliedAndLogged(): void
    {
        $carrierRates = $this->fakeCarrierRates($this->baselineCarrierRateRows());
        $syncRuns = $this->fakeSyncRuns();
        // +6.7% on the parcel rate, well within tolerance; the other two stay correct/unchanged.
        $parser = $this->parserReturning(array_merge($this->baselineParsedRates(), ['postnl_nl_parcel' => 7.95]));

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $syncRuns);
        $result = $service->sync('cron');

        $this->assertTrue($result->success);
        $this->assertSame(7.95, $carrierRates->rows['postnl_nl_parcel']['price']);
        $this->assertStringContainsString('applySyncedPrice:3:7.95', implode(',', $carrierRates->calls));
        $this->assertCount(1, $result->changed);
        $this->assertSame('success', $syncRuns->runs[0]['status']);
    }

    public function testUnchangedRateIsLeftAloneButMarkedChecked(): void
    {
        $carrierRates = $this->fakeCarrierRates(['postnl_nl_parcel' => $this->rate(3, '7.45')]);
        $parser = $this->parserReturning(['postnl_nl_parcel' => 7.45]);

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $this->fakeSyncRuns());
        $result = $service->sync('cron');

        $this->assertSame(['touchLastChecked:3'], $carrierRates->calls);
        $this->assertSame(7.45, $carrierRates->rows['postnl_nl_parcel']['price']);
        $this->assertCount(1, $result->unchanged);
    }

    public function testRateCodeMissingFromParsedDataIsNotOverwritten(): void
    {
        $carrierRates = $this->fakeCarrierRates([
            'postnl_nl_letter_20g' => $this->rate(1, '1.40'),
            'postnl_nl_letter_50g' => $this->rate(2, '2.80'),
            'postnl_nl_parcel' => $this->rate(3, '7.45'),
        ]);
        // Simulates PostNL reformatting the document so the parcel row can't be found.
        $parser = $this->parserReturning(['postnl_nl_letter_20g' => 1.40, 'postnl_nl_letter_50g' => 2.80]);

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $this->fakeSyncRuns());
        $result = $service->sync('cron');

        $this->assertSame(7.45, $carrierRates->rows['postnl_nl_parcel']['price'], 'missing code must never be overwritten/zeroed');
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('niet gevonden', $result->warnings[0]);
    }

    public function testInvalidNonPositivePriceIsRejectedWithoutOverwriting(): void
    {
        $carrierRates = $this->fakeCarrierRates($this->baselineCarrierRateRows());
        $parser = $this->parserReturning(array_merge($this->baselineParsedRates(), ['postnl_nl_parcel' => 0.0]));

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $this->fakeSyncRuns());
        $result = $service->sync('cron');

        $this->assertStringNotContainsString(':3:', implode(',', $carrierRates->calls), 'the invalid rate (id 3) must never be written to');
        $this->assertSame(7.45, $carrierRates->rows['postnl_nl_parcel']['price']);
        $this->assertCount(1, $result->warnings);
    }

    public function testManualModeRateIsNeverOverwrittenButPendingIsRecorded(): void
    {
        $carrierRates = $this->fakeCarrierRates(['postnl_nl_parcel' => $this->rate(3, '8.20', mode: 'manual')]);
        $parser = $this->parserReturning(['postnl_nl_parcel' => 7.45]);

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $this->fakeSyncRuns());
        $result = $service->sync('cron');

        $this->assertSame(8.20, $carrierRates->rows['postnl_nl_parcel']['price'], 'manual override must survive a sync');
        $this->assertSame(7.45, $carrierRates->rows['postnl_nl_parcel']['pending_price']);
        $this->assertFalse($carrierRates->rows['postnl_nl_parcel']['needs_review'], 'manual hold-back is expected, not a review warning');
        $this->assertCount(1, $result->pendingManual);
    }

    public function testLargeUnexpectedChangeIsFlaggedForReviewNotAutoApplied(): void
    {
        $carrierRates = $this->fakeCarrierRates(['postnl_nl_letter_20g' => $this->rate(1, '1.40')]);
        // MAIN.MD's own example: 1,40 -> 14,00 must never apply automatically.
        $parser = $this->parserReturning(['postnl_nl_letter_20g' => 14.00]);

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $this->fakeSyncRuns());
        $result = $service->sync('cron');

        $this->assertSame(1.40, $carrierRates->rows['postnl_nl_letter_20g']['price'], 'implausible jump must not be applied');
        $this->assertSame(14.00, $carrierRates->rows['postnl_nl_letter_20g']['pending_price']);
        $this->assertTrue($carrierRates->rows['postnl_nl_letter_20g']['needs_review']);
        $this->assertCount(1, $result->flaggedForReview);
    }

    public function testDryRunComputesTheDiffButWritesNothing(): void
    {
        $carrierRates = $this->fakeCarrierRates(['postnl_nl_parcel' => $this->rate(3, '7.45')]);
        $syncRuns = $this->fakeSyncRuns();
        $parser = $this->parserReturning(['postnl_nl_parcel' => 7.95]);

        $service = new PostNlRateSyncService($this->fetcherReturning('irrelevant'), $parser, $carrierRates, $syncRuns);
        $result = $service->sync('cron', dryRun: true);

        $this->assertCount(1, $result->changed, 'the preview should still report what WOULD change');
        $this->assertSame([], $carrierRates->calls, 'dry run must never write a rate');
        $this->assertSame([], $syncRuns->runs, 'dry run must never write a sync-run log entry either');
        $this->assertSame(7.45, $carrierRates->rows['postnl_nl_parcel']['price']);
    }
}
