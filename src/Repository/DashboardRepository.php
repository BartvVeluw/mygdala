<?php

namespace App\Repository;

/**
 * All SQL the CMS dashboard needs, and nothing else.
 *
 * It exists for the same reason every other repository here does — raw SQL
 * stays out of the page — but it is deliberately a SEPARATE repository rather
 * than four more methods on OrderRepository/ProductRepository: these queries
 * answer "what does the owner need to see when they open the CMS", which is a
 * screen's question, not the order or product domain's. Nothing else reads
 * them, and no other screen's behaviour can change by editing them.
 *
 * It is also deliberately DUMB: it knows how to count, sum and join, but not
 * which payment statuses count as revenue or what makes a product
 * problematic. Those are rules, and they live in App\Service\DashboardMetrics
 * and App\Service\DashboardAttention where they can be tested without a
 * database.
 *
 * This is NOT an analytics/reporting layer: there is no event tracking, no
 * per-day history table and no aggregation job. Every number below is read
 * straight from the existing `orders`/`products` rows at page load, in a
 * handful of aggregate queries — never one query per order or per product.
 */
class DashboardRepository extends Repository
{
    /**
     * The order totals for one half-open time window [$from, $until), limited
     * to the payment statuses the caller counts as revenue.
     *
     * Half-open on purpose: "today" is `>= 00:00:00 today AND < 00:00:00
     * tomorrow`, which needs no assumption about the smallest representable
     * time and can never double-count an order placed at exactly midnight.
     *
     * `gross_total` and `refunded_total` come back as strings, exactly as
     * MySQL returns a DECIMAL sum — the caller decides how to combine them
     * (see App\Service\DashboardMetrics::period()).
     *
     * An empty `$statuses` is answered without touching the database: "count
     * nothing" has a known answer, and `IN ()` is not valid SQL.
     *
     * @param list<string> $statuses
     * @return array{order_count: int, gross_total: string, refunded_total: string}
     */
    public function orderTotalsBetween(array $statuses, string $from, string $until): array
    {
        if ($statuses === []) {
            return ['order_count' => 0, 'gross_total' => '0.00', 'refunded_total' => '0.00'];
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS order_count,
                    COALESCE(SUM(total), 0) AS gross_total,
                    COALESCE(SUM(refunded_amount), 0) AS refunded_total
             FROM orders
             WHERE status IN ({$placeholders})
               AND created_at >= ?
               AND created_at < ?"
        );
        $stmt->execute(array_merge(array_values($statuses), [$from, $until]));

        $row = $stmt->fetch();

        return [
            'order_count' => (int) ($row['order_count'] ?? 0),
            'gross_total' => (string) ($row['gross_total'] ?? '0.00'),
            'refunded_total' => (string) ($row['refunded_total'] ?? '0.00'),
        ];
    }

    /**
     * The most recent orders, newest first — every payment status included.
     *
     * Deliberately unfiltered, unlike the summary above: this list is "what
     * happened last", and a checkout that failed or is still waiting for
     * payment is part of that. Each row therefore carries both statuses an
     * order has (see MAIN.MD "Afhandelingsstatus") so the dashboard can show
     * them instead of implying every listed order was paid.
     *
     * LEFT JOIN on customers, not the INNER JOIN the order list uses: a row
     * without a resolvable customer must still appear here (the dashboard
     * shows the order number, which never depends on the customer).
     *
     * `$limit` is clamped and interpolated as an integer rather than bound:
     * it is a page-level constant, never request input.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findRecentOrders(int $limit): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->db->query(
            'SELECT o.id, o.order_number, o.status, o.fulfilment_status, o.total, o.currency, o.created_at,
                    c.name AS customer_name
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT ' . $limit
        );

        return $stmt->fetchAll();
    }

    /**
     * How many orders are waiting for the owner to do something: paid, and
     * still on the working list. A COUNT rather than a fetch — the dashboard
     * only ever shows the number and links to the existing filtered list.
     *
     * Both values are passed in (from OrderRepository's own constants) so
     * this method never becomes a second place that decides what "needs
     * handling" means.
     */
    public function countOrdersAwaitingHandling(string $paymentStatus, string $fulfilmentStatus): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM orders WHERE status = :status AND fulfilment_status = :fulfilment_status'
        );
        $stmt->execute(['status' => $paymentStatus, 'fulfilment_status' => $fulfilmentStatus]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every ACTIVE product with the handful of facts the dashboard's
     * "Aandacht nodig" section reasons about. One query for the whole
     * catalogue — no per-product lookups — and no judgement: the row says
     * what is true, App\Service\DashboardAttention decides what is wrong.
     *
     * Inactive products are left out entirely: a draft without a photo is
     * work in progress, not a problem, and nothing a visitor can run into.
     *
     * `has_image` answers the question the shop card actually asks. A product
     * with variants shows its DEFAULT variant's first image instead of its
     * own `image_path` (see api/products.php and admin/products.php), so for
     * such a product the product-level photo is irrelevant and the default
     * variant's images decide. The default variant is the first ACTIVE one by
     * sort_order — resolved in the join, exactly like
     * ProductVariantRepository::findDefaultForProduct() resolves it — so a
     * product whose only photos sit on a deactivated variant is correctly
     * reported as having none.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveProductsForAttention(): array
    {
        $stmt = $this->db->query(
            "SELECT p.id, p.name, p.price, p.in_shop, p.in_personalization_catalog,
                    CASE
                        WHEN dv.id IS NOT NULL
                            THEN EXISTS (SELECT 1 FROM variant_images vi WHERE vi.variant_id = dv.id)
                        ELSE (p.image_path IS NOT NULL AND p.image_path <> '')
                    END AS has_image
             FROM products p
             LEFT JOIN product_variants dv ON dv.id = (
                 SELECT v.id
                 FROM product_variants v
                 WHERE v.product_id = p.id AND v.active = 1
                 ORDER BY v.sort_order ASC, v.id ASC
                 LIMIT 1
             )
             WHERE p.active = 1
             ORDER BY p.name ASC, p.id ASC"
        );

        return $stmt->fetchAll();
    }
}
