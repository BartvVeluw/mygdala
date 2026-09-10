<?php

declare(strict_types=1);

namespace Tests\Service\Analytics;

use App\Database;
use App\Repository\PageViewRepository;
use App\Service\Analytics\AnalyticsDashboard;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The reporting rules, exercised end to end against the real dev database
 * with the clock injected — the period arithmetic only means something in
 * combination with the SQL it produces, so mocking the repository here would
 * test the wrong half.
 *
 * Like tests/Repository/PageViewRepositoryIntegrationTest.php, every row and
 * every assertion lives in the year 2001, so real traffic can never fall
 * inside a window under test and nothing here can disturb what the owner sees
 * on the dashboard.
 *
 * The fixed "now" is 15 May 2001, 12:00 — deliberately mid-day and mid-month,
 * because both comparisons are about cutting the previous period to the same
 * elapsed length. A "now" at midnight would make every one of these tests
 * pass even if that logic were missing.
 */
final class AnalyticsDashboardTest extends TestCase
{
    private const NOW = '2001-05-15 12:00:00';
    private const FAKE_FROM = '2001-01-01 00:00:00';
    private const FAKE_TO = '2002-01-01 00:00:00';

    private PageViewRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new PageViewRepository();
        $this->deleteFakeRows();
    }

    protected function tearDown(): void
    {
        $this->deleteFakeRows();
    }

    private function deleteFakeRows(): void
    {
        $stmt = Database::connection()
            ->prepare('DELETE FROM page_views WHERE created_at >= :from AND created_at < :to');
        $stmt->execute(['from' => self::FAKE_FROM, 'to' => self::FAKE_TO]);
    }

    private function record(string $path, string $visitor, string $createdAt): void
    {
        $this->repository->record($path, str_pad($visitor, 64, '0'), null, 'desktop', $createdAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return AnalyticsDashboard::summary(new DateTimeImmutable(self::NOW), $this->repository);
    }

    public function testTodayCountsOnlyTodayUpToNow(): void
    {
        $this->record('/', 'a', '2001-05-15 08:00:00');
        $this->record('/shop.php', 'a', '2001-05-15 11:59:00');
        // After "now": tomorrow's data as far as this report is concerned.
        $this->record('/', 'b', '2001-05-15 13:00:00');
        $this->record('/', 'c', '2001-05-14 23:59:00');

        $summary = $this->summary();

        $this->assertSame(2, $summary['today']['pageviews']);
        $this->assertSame(1, $summary['today']['visitors']);
    }

    /**
     * A pageview recorded in the very second the dashboard is rendered still
     * belongs to today. Timestamps have one-second precision and the range is
     * half-open, so an upper bound of exactly "now" drops it — and the owner
     * who opens the CMS straight after visiting their own site sees it in the
     * 30-day list but not in "today".
     */
    public function testAPageviewInTheCurrentSecondStillCountsAsToday(): void
    {
        $this->record('/', 'a', self::NOW);

        $summary = $this->summary();

        $this->assertSame(1, $summary['today']['pageviews']);
        $this->assertSame(1, $summary['month']['pageviews']);
    }

    /**
     * The heart of the comparison: yesterday is cut off at the same clock
     * time as today, so the morning never looks like a collapse.
     */
    public function testYesterdayIsTruncatedToTheSameElapsedTimeAsToday(): void
    {
        $this->record('/', 'a', '2001-05-14 09:00:00');
        $this->record('/', 'b', '2001-05-14 11:00:00');
        // Yesterday afternoon — real, but outside the comparable window.
        $this->record('/', 'c', '2001-05-14 20:00:00');

        $this->assertSame(2, $this->summary()['yesterday']['pageviews']);
    }

    public function testThisMonthRunsFromTheFirstOfTheMonthUpToNow(): void
    {
        $this->record('/', 'a', '2001-05-01 00:00:00');
        $this->record('/', 'b', '2001-05-09 15:00:00');
        $this->record('/', 'c', '2001-05-15 11:00:00');
        // April, and later today — both outside the window.
        $this->record('/', 'd', '2001-04-30 23:59:59');
        $this->record('/', 'e', '2001-05-15 18:00:00');

        $summary = $this->summary();

        $this->assertSame(3, $summary['month']['pageviews']);
        $this->assertSame('2001-05', $summary['month_label']);
        $this->assertSame('2001-04', $summary['previous_month_label']);
    }

    /**
     * The previous month is measured over the same number of elapsed days as
     * the current one — 14 days and 12 hours here — instead of the whole
     * month, which would make every month look like a disaster until the 30th.
     */
    public function testThePreviousMonthIsCutToTheSameElapsedLength(): void
    {
        $this->record('/', 'a', '2001-04-01 08:00:00');
        $this->record('/', 'b', '2001-04-15 11:00:00');
        // 15 April 13:00 is already past the elapsed window (which ends at
        // 15 April 12:00), and 28 April is far outside it.
        $this->record('/', 'c', '2001-04-15 13:00:00');
        $this->record('/', 'd', '2001-04-28 09:00:00');

        $this->assertSame(2, $this->summary()['previous_month']['pageviews']);
    }

    public function testTheChartHasOneEntryPerDayIncludingEmptyOnes(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00');
        $this->record('/', 'b', '2001-05-15 09:30:00');
        $this->record('/', 'a', '2001-05-10 09:00:00');

        $chart = $this->summary()['chart'];

        $this->assertCount(AnalyticsDashboard::CHART_DAYS, $chart);
        $this->assertSame('2001-04-16', $chart[0]['date']);
        $this->assertSame('2001-05-15', $chart[AnalyticsDashboard::CHART_DAYS - 1]['date']);

        $byDate = array_column($chart, null, 'date');
        $this->assertSame(2, $byDate['2001-05-15']['pageviews']);
        $this->assertSame(2, $byDate['2001-05-15']['visitors']);
        $this->assertSame(1, $byDate['2001-05-10']['pageviews']);
        // A day nobody visited is a zero, not a missing point.
        $this->assertSame(0, $byDate['2001-05-11']['pageviews']);
    }

    /**
     * Everything recorded today belongs in the chart's last bucket, whatever
     * the time of day — the window's upper bound is tomorrow midnight, not
     * "now".
     */
    public function testTodayIsFullyInsideTheChartWindow(): void
    {
        $this->record('/', 'a', '2001-05-15 23:30:00');

        $chart = $this->summary()['chart'];

        $this->assertSame(1, $chart[AnalyticsDashboard::CHART_DAYS - 1]['pageviews']);
    }

    public function testTopPagesAreLimitedAndOrdered(): void
    {
        foreach (['a', 'b', 'c'] as $index => $visitor) {
            $this->record('/shop.php', $visitor, '2001-05-1' . (2 + $index) . ' 09:00:00');
        }
        $this->record('/', 'a', '2001-05-14 09:00:00');

        $top = $this->summary()['top_pages'];

        $this->assertLessThanOrEqual(AnalyticsDashboard::TOP_LIST_LIMIT, count($top));
        $this->assertSame('/shop.php', $top[0]['path']);
        $this->assertSame(3, $top[0]['pageviews']);
    }

    public function testPercentageChangeIsRoundedAndSigned(): void
    {
        $this->assertSame(100, AnalyticsDashboard::percentageChange(20, 10));
        $this->assertSame(-50, AnalyticsDashboard::percentageChange(5, 10));
        $this->assertSame(0, AnalyticsDashboard::percentageChange(10, 10));
        $this->assertSame(33, AnalyticsDashboard::percentageChange(4, 3));
    }

    /**
     * Growth from nothing has no percentage. "+100%" for the first visitor
     * after a quiet day reads as a trend that isn't there, so the dashboard
     * is given null and shows the raw comparison number instead.
     */
    public function testThereIsNoPercentageWhenThePreviousPeriodWasEmpty(): void
    {
        $this->assertNull(AnalyticsDashboard::percentageChange(7, 0));
        $this->assertNull(AnalyticsDashboard::percentageChange(0, 0));
    }
}
