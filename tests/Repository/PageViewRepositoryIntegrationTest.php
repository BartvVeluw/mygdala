<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\PageViewRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database, same convention as
 * tests/Repository/CollectionRepositoryIntegrationTest.php. Analytics is
 * mostly aggregation SQL — half-open date ranges, GROUP BY,
 * COUNT(DISTINCT ...) — which is exactly the part a mock would prove nothing
 * about.
 *
 * ISOLATION FROM REAL TRAFFIC. The `page_views` table also fills up with real
 * visits (including this suite's own HTTP tests), so every row created here
 * is dated in the year 2001 and every assertion asks about a window inside
 * that year. Nothing recorded by the live site can fall inside it, and
 * nothing here can change what the dashboard shows. tearDown() then deletes
 * strictly within that window.
 */
final class PageViewRepositoryIntegrationTest extends TestCase
{
    /** Everything this test writes lives between these two moments. */
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
        $db = Database::connection();

        $views = $db->prepare('DELETE FROM page_views WHERE created_at >= :from AND created_at < :to');
        $views->execute(['from' => self::FAKE_FROM, 'to' => self::FAKE_TO]);

        $salts = $db->prepare('DELETE FROM analytics_visitor_salts WHERE salt_date >= :from AND salt_date < :to');
        $salts->execute(['from' => '2001-01-01', 'to' => '2002-01-01']);
    }

    private function record(string $path, string $visitor, string $createdAt, ?string $referrer = null): void
    {
        $this->repository->record($path, str_pad($visitor, 64, '0'), $referrer, 'desktop', $createdAt);
    }

    public function testPageviewsAreCountedWithinAHalfOpenRange(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00');
        $this->record('/', 'a', '2001-05-15 23:59:59');
        $this->record('/', 'a', '2001-05-16 00:00:00');

        // [16 May 00:00) excludes the third row exactly — the boundary that
        // keeps one pageview from being counted in two adjacent periods.
        $this->assertSame(2, $this->repository->countPageViews('2001-05-15 00:00:00', '2001-05-16 00:00:00'));
        $this->assertSame(1, $this->repository->countPageViews('2001-05-16 00:00:00', '2001-05-17 00:00:00'));
    }

    public function testVisitorsCountDistinctHashesNotPageviews(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00');
        $this->record('/shop.php', 'a', '2001-05-15 09:05:00');
        $this->record('/shop.php', 'b', '2001-05-15 10:00:00');

        $this->assertSame(3, $this->repository->countPageViews('2001-05-15 00:00:00', '2001-05-16 00:00:00'));
        $this->assertSame(2, $this->repository->countVisitors('2001-05-15 00:00:00', '2001-05-16 00:00:00'));
    }

    public function testDailyTotalsGroupPerCalendarDayAndOmitEmptyDays(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00');
        $this->record('/', 'b', '2001-05-15 18:00:00');
        $this->record('/', 'c', '2001-05-17 12:00:00');

        $totals = $this->repository->dailyTotals('2001-05-14 00:00:00', '2001-05-18 00:00:00');

        $this->assertSame(['2001-05-15', '2001-05-17'], array_keys($totals));
        $this->assertSame(['pageviews' => 2, 'visitors' => 2], $totals['2001-05-15']);
        $this->assertSame(['pageviews' => 1, 'visitors' => 1], $totals['2001-05-17']);
    }

    public function testTopPagesAreOrderedByPageviewsAndRespectTheLimit(): void
    {
        foreach (range(1, 5) as $i) {
            $this->record('/shop.php', 'v' . $i, '2001-05-15 09:0' . $i . ':00');
        }
        $this->record('/', 'v1', '2001-05-15 10:00:00');
        $this->record('/', 'v2', '2001-05-15 10:01:00');
        $this->record('/contact.php', 'v1', '2001-05-15 11:00:00');

        $top = $this->repository->topPages('2001-05-15 00:00:00', '2001-05-16 00:00:00', 2);

        $this->assertCount(2, $top);
        $this->assertSame('/shop.php', $top[0]['path']);
        $this->assertSame(5, $top[0]['pageviews']);
        $this->assertSame(5, $top[0]['visitors']);
        $this->assertSame('/', $top[1]['path']);
        $this->assertSame(2, $top[1]['pageviews']);
    }

    public function testTopReferrersIgnoreDirectAndInternalVisits(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00', 'google.com');
        $this->record('/', 'b', '2001-05-15 09:10:00', 'google.com');
        $this->record('/', 'c', '2001-05-15 09:20:00', 'facebook.com');
        // No external source: stored as NULL, and not a "source" at all.
        $this->record('/', 'd', '2001-05-15 09:30:00', null);

        $referrers = $this->repository->topReferrers('2001-05-15 00:00:00', '2001-05-16 00:00:00', 5);

        $this->assertCount(2, $referrers);
        $this->assertSame(['host' => 'google.com', 'pageviews' => 2], $referrers[0]);
        $this->assertSame(['host' => 'facebook.com', 'pageviews' => 1], $referrers[1]);
    }

    /**
     * One salt per day, created on first use and stable afterwards — two
     * salts for one day would split that day's visitor count in half.
     */
    public function testTheVisitorSaltIsCreatedOnceAndReusedForTheRestOfTheDay(): void
    {
        $first = $this->repository->visitorSaltForDate('2001-05-15');
        $second = $this->repository->visitorSaltForDate('2001-05-15');

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    public function testEachDayGetsItsOwnSalt(): void
    {
        $this->assertNotSame(
            $this->repository->visitorSaltForDate('2001-05-15'),
            $this->repository->visitorSaltForDate('2001-05-16')
        );
    }

    /**
     * Deleting a day's salt is what makes that day's hashes permanently
     * unlinkable to any IP address; the pageviews themselves survive as
     * anonymous aggregates.
     */
    public function testPruningRemovesOldSaltsButKeepsTheCounts(): void
    {
        $this->repository->visitorSaltForDate('2001-05-15');
        $this->repository->visitorSaltForDate('2001-05-20');
        $this->record('/', 'a', '2001-05-15 09:00:00');

        $deleted = $this->repository->deleteSaltsBefore('2001-05-16');

        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->repository->countPageViews('2001-05-15 00:00:00', '2001-05-16 00:00:00'));
    }

    public function testPruningRemovesOldPageviews(): void
    {
        $this->record('/', 'a', '2001-05-15 09:00:00');
        $this->record('/', 'b', '2001-05-20 09:00:00');

        $deleted = $this->repository->deletePageViewsBefore('2001-05-16 00:00:00');

        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->repository->countPageViews(self::FAKE_FROM, self::FAKE_TO));
    }
}
