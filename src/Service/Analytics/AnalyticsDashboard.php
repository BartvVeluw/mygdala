<?php

namespace App\Service\Analytics;

use App\Repository\PageViewRepository;
use DateTimeImmutable;

/**
 * The reporting half of the first-party website statistics: turns the
 * `page_views` rows into the handful of numbers the CMS dashboard shows.
 *
 * Everything the dashboard renders comes from ONE call to summary(), so
 * admin/_dashboard_analytics.php stays a template and this class stays the
 * only place that knows what "this month so far" means.
 *
 * COMPARING PERIODS FAIRLY is the only real logic here. Today is four hours
 * old at breakfast; comparing it against a complete yesterday would show a
 * catastrophic drop every morning and a recovery every evening. So each
 * comparison period is cut to exactly the same ELAPSED length as the current
 * one:
 *
 *   today          [00:00 today,             now)
 *   vs yesterday   [00:00 yesterday,         00:00 yesterday + that elapsed time)
 *   this month     [00:00 on the 1st,        now)
 *   vs last month  [00:00 on the 1st before, that + the same elapsed time)
 *
 * Taking "the same elapsed time" rather than "the same calendar day of the
 * previous month" also sidesteps every month-length trap at once: no need to
 * decide what the 31st of the previous month means in a 30-day month, and a
 * comparison never silently covers a different number of days.
 *
 * All arithmetic is done in PHP's configured timezone, on the same clock that
 * wrote the rows (DATETIME columns are stored and compared verbatim, MySQL
 * never converts them), so "today" means the same thing at both ends.
 *
 * VISITOR NUMBERS ARE APPROXIMATE BY DESIGN. A visitor is a distinct
 * visitor_hash, and that hash is re-salted every night (see VisitorHash), so
 * "visitors this month" is closer to "daily unique visitors added up" than to
 * a headcount: someone who returns on five days counts five times. The
 * dashboard says so in as many words. The alternative — a salt that survives
 * for a month — would create exactly the durable cross-day identifier this
 * feature is built to avoid.
 */
final class AnalyticsDashboard
{
    /** Days in the trend chart, today included. */
    public const CHART_DAYS = 30;

    /** Rows in the "most viewed pages" and "referrers" lists. */
    public const TOP_LIST_LIMIT = 5;

    /**
     * Every number the dashboard renders.
     *
     * @param DateTimeImmutable|null  $now        injected by the tests; defaults to the real clock
     * @param PageViewRepository|null $repository injected by the tests
     *
     * @return array{
     *     has_data: bool,
     *     today: array{pageviews: int, visitors: int},
     *     yesterday: array{pageviews: int, visitors: int},
     *     month: array{pageviews: int, visitors: int},
     *     previous_month: array{pageviews: int, visitors: int},
     *     month_label: string,
     *     previous_month_label: string,
     *     chart: list<array{date: string, pageviews: int, visitors: int}>,
     *     top_pages: list<array{path: string, pageviews: int, visitors: int}>,
     *     top_referrers: list<array{host: string, pageviews: int}>
     * }
     */
    public static function summary(?DateTimeImmutable $now = null, ?PageViewRepository $repository = null): array
    {
        $now ??= new DateTimeImmutable('now');
        $repository ??= new PageViewRepository();

        $todayStart = $now->setTime(0, 0);
        $yesterdayStart = $todayStart->modify('-1 day');
        $monthStart = $todayStart->modify('first day of this month');
        $previousMonthStart = $monthStart->modify('-1 month');

        // The ranges are half-open and timestamps have one-second precision,
        // so "up to now" has to end at the NEXT second: a pageview recorded
        // in the very second the dashboard is rendered would otherwise be
        // missing from "today" while still appearing in the 30-day lists —
        // a visible inconsistency the moment the owner opens the CMS right
        // after visiting their own site.
        $periodEnd = $now->modify('+1 second');

        $dayElapsed = $periodEnd->getTimestamp() - $todayStart->getTimestamp();
        $monthElapsed = $periodEnd->getTimestamp() - $monthStart->getTimestamp();

        $chartStart = $todayStart->modify('-' . (self::CHART_DAYS - 1) . ' days');
        // Exclusive upper bound: tomorrow 00:00, so everything recorded today
        // is inside the last bucket regardless of the time of day.
        $chartEnd = $todayStart->modify('+1 day');

        return [
            'has_data' => $repository->hasAnyData(),
            'today' => self::totals($repository, $todayStart, $periodEnd),
            'yesterday' => self::totals($repository, $yesterdayStart, self::plusSeconds($yesterdayStart, $dayElapsed)),
            'month' => self::totals($repository, $monthStart, $periodEnd),
            'previous_month' => self::totals(
                $repository,
                $previousMonthStart,
                self::plusSeconds($previousMonthStart, $monthElapsed)
            ),
            'month_label' => $monthStart->format('Y-m'),
            'previous_month_label' => $previousMonthStart->format('Y-m'),
            'chart' => self::chart($repository, $chartStart, $chartEnd),
            'top_pages' => $repository->topPages(
                self::sql($chartStart),
                self::sql($chartEnd),
                self::TOP_LIST_LIMIT
            ),
            'top_referrers' => $repository->topReferrers(
                self::sql($chartStart),
                self::sql($chartEnd),
                self::TOP_LIST_LIMIT
            ),
        ];
    }

    /**
     * Percentage change between two comparable numbers, or null when there is
     * nothing to compare against.
     *
     * Growth from zero is deliberately null rather than "+100%" or "+∞": the
     * first visitor after a quiet day is not a doubling, and a made-up
     * percentage on a brand-new site reads as a real trend. The dashboard
     * shows the previous period's raw number in that case instead.
     */
    public static function percentageChange(int $current, int $previous): ?int
    {
        if ($previous <= 0) {
            return null;
        }

        return (int) round((($current - $previous) / $previous) * 100);
    }

    /**
     * @return array{pageviews: int, visitors: int}
     */
    private static function totals(
        PageViewRepository $repository,
        DateTimeImmutable $from,
        DateTimeImmutable $toExclusive
    ): array {
        $fromSql = self::sql($from);
        $toSql = self::sql($toExclusive);

        return [
            'pageviews' => $repository->countPageViews($fromSql, $toSql),
            'visitors' => $repository->countVisitors($fromSql, $toSql),
        ];
    }

    /**
     * One entry per day in the window, oldest first, with zeroes for the days
     * nobody visited — a chart that silently skips its empty days draws a
     * quiet week as a straight line between two busy ones.
     *
     * @return list<array{date: string, pageviews: int, visitors: int}>
     */
    private static function chart(
        PageViewRepository $repository,
        DateTimeImmutable $from,
        DateTimeImmutable $toExclusive
    ): array {
        $totals = $repository->dailyTotals(self::sql($from), self::sql($toExclusive));

        $days = [];
        for ($offset = 0; $offset < self::CHART_DAYS; $offset++) {
            $date = $from->modify('+' . $offset . ' days')->format('Y-m-d');

            $days[] = [
                'date' => $date,
                'pageviews' => $totals[$date]['pageviews'] ?? 0,
                'visitors' => $totals[$date]['visitors'] ?? 0,
            ];
        }

        return $days;
    }

    private static function plusSeconds(DateTimeImmutable $moment, int $seconds): DateTimeImmutable
    {
        return $moment->modify('+' . $seconds . ' seconds');
    }

    private static function sql(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }
}
