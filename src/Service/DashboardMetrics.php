<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The dashboard's shop summary as pure arithmetic: which orders count as
 * revenue, what time windows "vandaag" and "deze maand" mean, and how the
 * four numbers on the dashboard are derived from a single aggregate row.
 *
 * Everything here is static and side-effect free — no database, no clock of
 * its own (the caller passes "now") — so every rule below is testable
 * directly, which is the whole reason it isn't inlined into admin/index.php.
 *
 * ## Which orders count as revenue
 *
 * ONLY `status = 'paid'`. An order has two independent statuses (see MAIN.MD
 * "Afhandelingsstatus"): the Mollie-driven payment status
 * (`pending`/`paid`/`failed`/`canceled`/`expired`, written only by
 * App\Service\OrderPaymentSync) and the owner's own handling status
 * (`Open`/`Afgehandeld`). Money is the payment status' business alone:
 *
 *   - `pending` — a checkout that reached Mollie and never came back. In this
 *     shop that is mostly an abandoned basket; nothing was received, so it is
 *     not revenue and not an order that happened.
 *   - `failed` / `canceled` / `expired` — no money at all, by definition.
 *   - `paid` — Mollie confirmed the payment. This, and only this, is revenue.
 *
 * The handling status is deliberately NOT part of the definition: whether the
 * owner has already engraved and shipped an order changes nothing about
 * whether it was sold. That is what keeps this consistent with the invoices,
 * which are issued on `paid` too (App\Service\InvoiceService).
 *
 * ## Refunds
 *
 * `orders.refunded_amount` is Mollie's own total refunded for that payment,
 * never a number computed here (see OrderRepository::setRefundedAmount), and
 * a refund does NOT change the payment status — Mollie keeps it `paid`. So a
 * refunded order stays in the count and its refund is SUBTRACTED from
 * revenue, which is what makes "omzet" the money actually kept. A refund is
 * attributed to the month the ORDER was placed in, not the month the refund
 * happened: the alternative would need refund-date bookkeeping this shop does
 * not have, and it keeps one order's whole story in one month.
 */
class DashboardMetrics
{
    /**
     * The one `orders.status` value that means Mollie confirmed the money —
     * the same literal App\Service\OrderPaymentSync::mapStatus() writes and
     * OrderRepository::markHandled() guards on.
     */
    public const PAID_STATUS = 'paid';

    /**
     * The payment statuses that count as a sale. A list rather than a single
     * string so the one place that decides this reads like the decision it
     * is, and so a future status could be added here instead of in a query.
     *
     * @var list<string>
     */
    public const REVENUE_STATUSES = [self::PAID_STATUS];

    /** How many recent orders the dashboard lists. */
    public const RECENT_ORDER_LIMIT = 5;

    /**
     * [today 00:00:00, tomorrow 00:00:00) — half-open, so an order created at
     * exactly midnight belongs to exactly one day.
     *
     * @return array{from: string, until: string}
     */
    public static function dayWindow(\DateTimeImmutable $now): array
    {
        $start = $now->setTime(0, 0, 0);

        return [
            'from' => $start->format('Y-m-d H:i:s'),
            'until' => $start->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * [the 1st of this month 00:00:00, the 1st of next month 00:00:00).
     *
     * Built from the first of the month rather than by adding a month to
     * "now", because "+1 month" on the 31st lands in the month after next.
     *
     * @return array{from: string, until: string}
     */
    public static function monthWindow(\DateTimeImmutable $now): array
    {
        $start = $now->setDate((int) $now->format('Y'), (int) $now->format('n'), 1)->setTime(0, 0, 0);

        return [
            'from' => $start->format('Y-m-d H:i:s'),
            'until' => $start->modify('+1 month')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Turns one DashboardRepository::orderTotalsBetween() row into the
     * numbers a dashboard card shows.
     *
     * `revenue` is net of refunds and is NOT clamped at zero: a window whose
     * refunds exceed its sales is a real (if unusual) situation — an order
     * from last month refunded this one cannot cause it, since a refund is
     * counted in its order's own window — and showing a negative number is
     * more honest than showing "0".
     *
     * @param array{order_count?: int|string, gross_total?: float|int|string, refunded_total?: float|int|string} $totals
     * @return array{order_count: int, gross: float, refunded: float, revenue: float, average_order_value: float}
     */
    public static function period(array $totals): array
    {
        $count = max(0, (int) ($totals['order_count'] ?? 0));
        $gross = round((float) ($totals['gross_total'] ?? 0), 2);
        $refunded = round((float) ($totals['refunded_total'] ?? 0), 2);
        $revenue = round($gross - $refunded, 2);

        return [
            'order_count' => $count,
            'gross' => $gross,
            'refunded' => $refunded,
            'revenue' => $revenue,
            'average_order_value' => self::averageOrderValue($count, $revenue),
        ];
    }

    /**
     * Average order value — net revenue divided over the orders that produced
     * it, so a fully refunded order drags the average down instead of being
     * quietly dropped from the divisor.
     *
     * Zero orders is 0.00, never a division by zero: an empty month has no
     * average, and 0 is what a card should show for it.
     */
    public static function averageOrderValue(int $orderCount, float $revenue): float
    {
        if ($orderCount <= 0) {
            return 0.0;
        }

        return round($revenue / $orderCount, 2);
    }

    /**
     * The Dutch money formatting the rest of the CMS uses, in one place so
     * every dashboard card renders "1.234,50" the same way. Without the euro
     * sign — the template puts that in front, exactly like the order list.
     */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }
}
