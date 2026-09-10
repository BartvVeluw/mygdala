<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\DashboardMetrics;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's arithmetic, tested without a database — which is exactly
 * why it lives in a static service instead of in admin/index.php.
 *
 * Two things are worth guarding here above all: WHICH orders count as
 * revenue (only `paid`, refunds subtracted — see the class docblock), and
 * that the time windows are half-open and month-safe, because "+1 month" on
 * the 31st is a classic way to skip a whole month.
 */
final class DashboardMetricsTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /* Which orders count                                                  */
    /* ------------------------------------------------------------------ */

    public function testOnlyPaidOrdersCountAsRevenue(): void
    {
        $this->assertSame(['paid'], DashboardMetrics::REVENUE_STATUSES);
        $this->assertSame('paid', DashboardMetrics::PAID_STATUS);
    }

    public function testUnpaidStatusesAreExcludedFromRevenue(): void
    {
        foreach (['pending', 'failed', 'canceled', 'expired'] as $status) {
            $this->assertNotContains(
                $status,
                DashboardMetrics::REVENUE_STATUSES,
                $status . ' received no money and must never count as revenue'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Time windows                                                        */
    /* ------------------------------------------------------------------ */

    public function testDayWindowRunsFromMidnightToMidnight(): void
    {
        $window = DashboardMetrics::dayWindow(new \DateTimeImmutable('2026-09-08 14:37:12'));

        $this->assertSame('2026-09-08 00:00:00', $window['from']);
        $this->assertSame('2026-09-09 00:00:00', $window['until']);
    }

    public function testDayWindowCrossesIntoTheNextMonth(): void
    {
        $window = DashboardMetrics::dayWindow(new \DateTimeImmutable('2026-01-31 23:59:59'));

        $this->assertSame('2026-01-31 00:00:00', $window['from']);
        $this->assertSame('2026-02-01 00:00:00', $window['until']);
    }

    public function testDayWindowAtExactlyMidnightBelongsToTheDayThatStarts(): void
    {
        $window = DashboardMetrics::dayWindow(new \DateTimeImmutable('2026-09-08 00:00:00'));

        $this->assertSame('2026-09-08 00:00:00', $window['from']);
        $this->assertSame('2026-09-09 00:00:00', $window['until']);
    }

    public function testMonthWindowRunsFromTheFirstToTheFirst(): void
    {
        $window = DashboardMetrics::monthWindow(new \DateTimeImmutable('2026-09-08 14:37:12'));

        $this->assertSame('2026-09-01 00:00:00', $window['from']);
        $this->assertSame('2026-10-01 00:00:00', $window['until']);
    }

    /**
     * The reason monthWindow() starts from the 1st: "2026-01-31 +1 month" is
     * 2026-03-03, which would silently swallow all of February.
     */
    public function testMonthWindowIsCorrectOnTheThirtyFirst(): void
    {
        $window = DashboardMetrics::monthWindow(new \DateTimeImmutable('2026-01-31 09:00:00'));

        $this->assertSame('2026-01-01 00:00:00', $window['from']);
        $this->assertSame('2026-02-01 00:00:00', $window['until']);
    }

    public function testMonthWindowRollsOverIntoTheNextYear(): void
    {
        $window = DashboardMetrics::monthWindow(new \DateTimeImmutable('2026-12-20 08:00:00'));

        $this->assertSame('2026-12-01 00:00:00', $window['from']);
        $this->assertSame('2027-01-01 00:00:00', $window['until']);
    }

    public function testMonthWindowHandlesALeapDay(): void
    {
        $window = DashboardMetrics::monthWindow(new \DateTimeImmutable('2028-02-29 12:00:00'));

        $this->assertSame('2028-02-01 00:00:00', $window['from']);
        $this->assertSame('2028-03-01 00:00:00', $window['until']);
    }

    /* ------------------------------------------------------------------ */
    /* The four figures                                                    */
    /* ------------------------------------------------------------------ */

    public function testPeriodDerivesEveryFigureFromOneAggregateRow(): void
    {
        // Exactly what MySQL hands back: an int count and DECIMAL sums as
        // strings.
        $period = DashboardMetrics::period([
            'order_count' => 4,
            'gross_total' => '249.80',
            'refunded_total' => '0.00',
        ]);

        $this->assertSame(4, $period['order_count']);
        $this->assertSame(249.80, $period['gross']);
        $this->assertSame(0.0, $period['refunded']);
        $this->assertSame(249.80, $period['revenue']);
        $this->assertSame(62.45, $period['average_order_value']);
    }

    public function testRefundsAreSubtractedFromRevenueAndFromTheAverage(): void
    {
        $period = DashboardMetrics::period([
            'order_count' => 2,
            'gross_total' => '100.00',
            'refunded_total' => '30.00',
        ]);

        $this->assertSame(100.00, $period['gross'], 'the gross sale amount stays readable on its own');
        $this->assertSame(30.00, $period['refunded']);
        $this->assertSame(70.00, $period['revenue']);
        $this->assertSame(35.00, $period['average_order_value']);
    }

    /**
     * A fully refunded order stays in the count on purpose: it happened, and
     * hiding it would quietly inflate the average of the orders that did
     * stick.
     */
    public function testAFullyRefundedOrderStillCountsAsAnOrder(): void
    {
        $period = DashboardMetrics::period([
            'order_count' => 2,
            'gross_total' => '80.00',
            'refunded_total' => '40.00',
        ]);

        $this->assertSame(2, $period['order_count']);
        $this->assertSame(40.00, $period['revenue']);
        $this->assertSame(20.00, $period['average_order_value']);
    }

    public function testAnEmptyPeriodIsAllZeroesAndNeverDividesByZero(): void
    {
        $period = DashboardMetrics::period([
            'order_count' => 0,
            'gross_total' => '0.00',
            'refunded_total' => '0.00',
        ]);

        $this->assertSame(0, $period['order_count']);
        $this->assertSame(0.0, $period['revenue']);
        $this->assertSame(0.0, $period['average_order_value']);
    }

    public function testAMissingAggregateRowIsTreatedAsAnEmptyPeriod(): void
    {
        $period = DashboardMetrics::period([]);

        $this->assertSame(0, $period['order_count']);
        $this->assertSame(0.0, $period['gross']);
        $this->assertSame(0.0, $period['revenue']);
        $this->assertSame(0.0, $period['average_order_value']);
    }

    /**
     * Refunds beyond the sales of the window are not clamped away: showing
     * "0" would hide that more money went out than came in.
     */
    public function testRefundsBeyondTheSalesProduceANegativeRevenue(): void
    {
        $period = DashboardMetrics::period([
            'order_count' => 1,
            'gross_total' => '25.00',
            'refunded_total' => '30.00',
        ]);

        $this->assertSame(-5.00, $period['revenue']);
        $this->assertSame(-5.00, $period['average_order_value']);
    }

    public function testMoneyIsRoundedToCentsRatherThanLeftAsBinaryFloat(): void
    {
        $period = DashboardMetrics::period([
            'order_count' => 3,
            'gross_total' => '100.00',
            'refunded_total' => '0.00',
        ]);

        $this->assertSame(33.33, $period['average_order_value']);
    }

    public function testAverageOrderValueIsZeroWithoutOrders(): void
    {
        $this->assertSame(0.0, DashboardMetrics::averageOrderValue(0, 500.00));
        $this->assertSame(0.0, DashboardMetrics::averageOrderValue(-3, 500.00));
    }

    public function testAmountsAreFormattedTheDutchWay(): void
    {
        $this->assertSame('1.234,50', DashboardMetrics::formatAmount(1234.5));
        $this->assertSame('0,00', DashboardMetrics::formatAmount(0.0));
        $this->assertSame('-5,00', DashboardMetrics::formatAmount(-5.0));
    }

    public function testTheRecentOrderLimitIsTheFiveTheDashboardPromises(): void
    {
        $this->assertSame(5, DashboardMetrics::RECENT_ORDER_LIMIT);
    }
}
