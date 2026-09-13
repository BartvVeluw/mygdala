<?php

namespace App\Repository;

use App\Service\SiteSettings;

/**
 * All order + order_items SQL lives here.
 */
class OrderRepository extends Repository
{
    /**
     * The admin's own handling workflow for an order, deliberately just two
     * states: "Open" (still needs the owner's attention) and "Afgehandeld"
     * (dealt with). Completely separate from the Mollie-driven payment status
     * in `status`/`mollie_status` — see MAIN.MD "Afhandelingsstatus".
     */
    public const FULFILMENT_OPEN = 'Open';
    public const FULFILMENT_HANDLED = 'Afgehandeld';
    public const FULFILMENT_STATUSES = [self::FULFILMENT_OPEN, self::FULFILMENT_HANDLED];

    /**
     * The shape a NEW order's public number is made in — e.g.
     * "ORD-2026-000127" — from the prefix it is given, the order's creation
     * year and its own auto-increment id, which MySQL allocates unique and
     * race-free, so no generator or sequence table is needed.
     *
     * At runtime only assignOrderNumber(), inside create(), calls this. From
     * then on the number is a stored fact about the order,
     * `orders.order_number`, and everything that shows one reads it with
     * orderNumber(). Rebuilding it from today's `order_number_prefix` would
     * rename every existing order the moment an owner changed that setting
     * (db/migrations/20260913120000_snapshot_the_order_number_on_every_order.php);
     * Tests\Repository\OrderNumberSnapshotContractTest holds that line.
     *
     * THIS METHOD owns the separators: only the prefix's letters and digits
     * are used, so a stored "ORD-" can never become "ORD--2026". A prefix with
     * nothing usable left falls back to the generic default. It reads no
     * setting itself; the caller hands in the prefix.
     *
     * The prefix used to be a literal "VLD-" here. Every installation that
     * issued numbers with it was pinned to "VLD" by
     * db/migrations/20260913100000 before those numbers were stored.
     */
    public static function formatOrderNumber(int $orderId, \DateTimeInterface $createdAt, string $prefix): string
    {
        return self::orderNumberPrefix($prefix)
            . '-' . $createdAt->format('Y')
            . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    }

    /** The letters and digits of $prefix, or the generic default when none are left. */
    private static function orderNumberPrefix(string $prefix): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9]/', '', $prefix);

        return $clean !== '' ? $clean : SiteSettings::defaults()['order_number_prefix'];
    }

    /**
     * The public number $order was created with, as stored on it: what the
     * Mollie payment, the customer and shop e-mails, the admin, the dashboard,
     * the order-status page, the export and the invoice all show.
     *
     * A row without one should not exist: create() writes it before its
     * transaction commits, and 20260913120000 gave one to every order before
     * it. Should one turn up anyway, this answers with the technical "#<id>"
     * and logs it — never with a number rebuilt from today's prefix, which
     * would look real and be wrong.
     *
     * @param array<string, mixed> $order an `orders` row, or a list row that selects `order_number`
     */
    public static function orderNumber(array $order): string
    {
        $stored = (string) ($order['order_number'] ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $orderId = (int) ($order['id'] ?? 0);
        error_log('[OrderRepository] Order ' . $orderId . ' has no stored order_number; showing its id instead.');

        return '#' . $orderId;
    }

    /**
     * `$termsContentHash` must be a real SHA-256 of the Terms & Conditions
     * content the customer actually accepted (see App\Service\LegalPages) —
     * api/checkout.php never calls this without one, since it fails the
     * whole checkout before reaching order creation if the CMS page/hash
     * couldn't be loaded.
     *
     * `$shippingAddress` is the order's own address snapshot — see
     * db/migrations/20260907150000_add_billing_and_structured_addresses_to_orders.php
     * — already verified/canonicalized by
     * App\Service\Address\CheckoutAddressResolver for NL destinations.
     * `$billingAddress` is null when the customer left "billing address is
     * the same as shipping" checked: the billing_* columns are then left
     * null rather than duplicating the shipping address (see
     * resolveBillingAddress()), and `billing_same_as_shipping` records that.
     *
     * @param array{first_name:string,last_name:string,company:?string,country:string,postal_code:string,house_number:string,house_number_addition:?string,street:string,city:string} $shippingAddress
     * @param array{first_name:string,last_name:string,company:?string,country:string,postal_code:string,house_number:string,house_number_addition:?string,street:string,city:string}|null $billingAddress
     */
    public function create(
        int $customerId,
        float|string $total,
        float|string $shippingCost,
        string $shippingMethod,
        string $currency,
        bool $termsAccepted,
        \DateTimeImmutable $termsAcceptedAt,
        string $termsContentHash,
        array $shippingAddress,
        ?array $billingAddress
    ): int {
        // The row and its public number are written in one transaction. The
        // checkout already holds one and this joins it; any other caller gets
        // its own. Either way no committed moment exists at which the order is
        // there without its number.
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $orderId = $this->insertOrder(
                $customerId,
                $total,
                $shippingCost,
                $shippingMethod,
                $currency,
                $termsAccepted,
                $termsAcceptedAt,
                $termsContentHash,
                $shippingAddress,
                $billingAddress
            );
            $this->assignOrderNumber($orderId);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $orderId;
    }

    /**
     * Gives a just-inserted order its public number, once: the prefix
     * configured at this moment, the creation year the database itself
     * recorded, and the id. The one place a number is ever made — see
     * formatOrderNumber() for why nothing else may make one.
     */
    private function assignOrderNumber(int $orderId): void
    {
        $stmt = $this->db->prepare('SELECT created_at FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $createdAt = $stmt->fetchColumn();

        if ($createdAt === false || $createdAt === null) {
            throw new \RuntimeException('Order ' . $orderId . ' has no created_at, so it cannot be given an order number.');
        }

        $number = self::formatOrderNumber(
            $orderId,
            new \DateTimeImmutable((string) $createdAt),
            SiteSettings::get('order_number_prefix')
        );

        $this->db->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id AND order_number IS NULL')
            ->execute(['order_number' => $number, 'id' => $orderId]);
    }

    /**
     * The order row itself, exactly as create() always wrote it. Kept apart
     * only so create() can wrap it and the number in one transaction.
     *
     * @param array{first_name:string,last_name:string,company:?string,country:string,postal_code:string,house_number:string,house_number_addition:?string,street:string,city:string} $shippingAddress
     * @param array{first_name:string,last_name:string,company:?string,country:string,postal_code:string,house_number:string,house_number_addition:?string,street:string,city:string}|null $billingAddress
     */
    private function insertOrder(
        int $customerId,
        float|string $total,
        float|string $shippingCost,
        string $shippingMethod,
        string $currency,
        bool $termsAccepted,
        \DateTimeImmutable $termsAcceptedAt,
        string $termsContentHash,
        array $shippingAddress,
        ?array $billingAddress
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO orders (
                customer_id, status, total, shipping_cost, shipping_method,
                terms_accepted, terms_accepted_at, terms_content_hash,
                shipping_first_name, shipping_last_name, shipping_company,
                shipping_country, shipping_postal_code, shipping_house_number,
                shipping_house_number_addition, shipping_street, shipping_city,
                billing_same_as_shipping,
                billing_first_name, billing_last_name, billing_company,
                billing_country, billing_postal_code, billing_house_number,
                billing_house_number_addition, billing_street, billing_city,
                currency, created_at, updated_at
             )
             VALUES (
                :customer_id, 'pending', :total, :shipping_cost, :shipping_method,
                :terms_accepted, :terms_accepted_at, :terms_content_hash,
                :shipping_first_name, :shipping_last_name, :shipping_company,
                :shipping_country, :shipping_postal_code, :shipping_house_number,
                :shipping_house_number_addition, :shipping_street, :shipping_city,
                :billing_same_as_shipping,
                :billing_first_name, :billing_last_name, :billing_company,
                :billing_country, :billing_postal_code, :billing_house_number,
                :billing_house_number_addition, :billing_street, :billing_city,
                :currency, NOW(), NOW()
             )"
        );

        $billingSameAsShipping = $billingAddress === null;
        $billing = $billingAddress ?? [
            'first_name' => null, 'last_name' => null, 'company' => null,
            'country' => null, 'postal_code' => null, 'house_number' => null,
            'house_number_addition' => null, 'street' => null, 'city' => null,
        ];

        $stmt->execute([
            'customer_id' => $customerId,
            // Either an already-exact decimal string (what checkout now
            // produces, summed in integer cents) or a float from an older
            // caller — both end up as a canonical "77.40".
            'total' => self::decimal($total),
            'shipping_cost' => self::decimal($shippingCost),
            'shipping_method' => $shippingMethod,
            'terms_accepted' => $termsAccepted ? 1 : 0,
            'terms_accepted_at' => $termsAcceptedAt->format('Y-m-d H:i:s'),
            'terms_content_hash' => $termsContentHash,
            'shipping_first_name' => $shippingAddress['first_name'],
            'shipping_last_name' => $shippingAddress['last_name'],
            'shipping_company' => $shippingAddress['company'],
            'shipping_country' => $shippingAddress['country'],
            'shipping_postal_code' => $shippingAddress['postal_code'],
            'shipping_house_number' => $shippingAddress['house_number'],
            'shipping_house_number_addition' => $shippingAddress['house_number_addition'],
            'shipping_street' => $shippingAddress['street'],
            'shipping_city' => $shippingAddress['city'],
            'billing_same_as_shipping' => $billingSameAsShipping ? 1 : 0,
            'billing_first_name' => $billing['first_name'],
            'billing_last_name' => $billing['last_name'],
            'billing_company' => $billing['company'],
            'billing_country' => $billing['country'],
            'billing_postal_code' => $billing['postal_code'],
            'billing_house_number' => $billing['house_number'],
            'billing_house_number_addition' => $billing['house_number_addition'],
            'billing_street' => $billing['street'],
            'billing_city' => $billing['city'],
            'currency' => $currency,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The order's shipping address: its own order-level columns when
     * present, falling back to the joined customer row for an order placed
     * before order-level addresses existed (see
     * db/migrations/20260907150000_add_billing_and_structured_addresses_to_orders.php)
     * — that customer row is exactly what that order used at the time.
     *
     * @param array<string, mixed> $order a row from findById()/findByIdForAdmin() etc. (must include the joined customer columns for the legacy fallback)
     * @return array{first_name:?string,last_name:?string,company:?string,country:?string,postal_code:?string,house_number:?string,house_number_addition:?string,street:?string,city:?string}
     */
    public static function resolveShippingAddress(array $order): array
    {
        if (!empty($order['shipping_street']) || !empty($order['shipping_postal_code'])) {
            return [
                'first_name' => $order['shipping_first_name'] ?? null,
                'last_name' => $order['shipping_last_name'] ?? null,
                'company' => $order['shipping_company'] ?? null,
                'country' => $order['shipping_country'] ?? null,
                'postal_code' => $order['shipping_postal_code'] ?? null,
                'house_number' => $order['shipping_house_number'] ?? null,
                'house_number_addition' => $order['shipping_house_number_addition'] ?? null,
                'street' => $order['shipping_street'] ?? null,
                'city' => $order['shipping_city'] ?? null,
            ];
        }

        return [
            'first_name' => null,
            'last_name' => null,
            'company' => null,
            'country' => $order['country'] ?? null,
            'postal_code' => $order['postal_code'] ?? null,
            'house_number' => null,
            'house_number_addition' => null,
            'street' => $order['address_line'] ?? null,
            'city' => $order['city'] ?? null,
        ];
    }

    /**
     * The order's billing address: its own explicit billing_* columns when
     * `billing_same_as_shipping` is false, otherwise identical to the
     * shipping address (see resolveShippingAddress()) — the whole point of
     * `billing_same_as_shipping` is to avoid ever having to duplicate the
     * shipping address into the billing columns just to answer "what is the
     * billing address for this order", which must always have an answer.
     *
     * @param array<string, mixed> $order
     * @return array{first_name:?string,last_name:?string,company:?string,country:?string,postal_code:?string,house_number:?string,house_number_addition:?string,street:?string,city:?string}
     */
    public static function resolveBillingAddress(array $order): array
    {
        if (empty($order['billing_same_as_shipping'])) {
            return [
                'first_name' => $order['billing_first_name'] ?? null,
                'last_name' => $order['billing_last_name'] ?? null,
                'company' => $order['billing_company'] ?? null,
                'country' => $order['billing_country'] ?? null,
                'postal_code' => $order['billing_postal_code'] ?? null,
                'house_number' => $order['billing_house_number'] ?? null,
                'house_number_addition' => $order['billing_house_number_addition'] ?? null,
                'street' => $order['billing_street'] ?? null,
                'city' => $order['billing_city'] ?? null,
            ];
        }

        return self::resolveShippingAddress($order);
    }

    /**
     * Returns the new order_items ids, in the same order as `$items`, so a
     * caller that has something to attach to one specific line — today the
     * personalization record, see
     * App\Repository\OrderItemPersonalizationRepository — can do that inside
     * its own transaction without re-querying which row is which. Callers
     * that don't need them simply ignore the return value, which is why
     * adding it changed nothing for the existing ones.
     *
     * `unit_price` stays the AUTHORITATIVE per-unit price of the line — the
     * product/variant price PLUS any personalization surcharge — which is why
     * the invoice PDF, the confirmation email and the CSV export all keep
     * working untouched. `base_unit_price` and `personalization_surcharge` are
     * optional and only explain how that number came about; both are NULL for
     * a line that was never personalized, and for every order placed before
     * personalization surcharges existed.
     *
     * @param array<int, array{product_id:int, variant_id:?int, variant_label:?string, quantity:int, unit_price:float|string, product_name:string, product_name_en:?string, base_unit_price?:float|string|null, personalization_surcharge?:float|string|null}> $items
     * @return array<int, int>
     */
    public function addItems(int $orderId, array $items): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, variant_id, variant_label, product_name, product_name_en,
                                      quantity, unit_price, base_unit_price, personalization_surcharge, created_at, updated_at)
             VALUES (:order_id, :product_id, :variant_id, :variant_label, :product_name, :product_name_en,
                     :quantity, :unit_price, :base_unit_price, :personalization_surcharge, NOW(), NOW())'
        );

        $ids = [];

        foreach ($items as $item) {
            $stmt->execute([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'] ?? null,
                'variant_label' => $item['variant_label'] ?? null,
                'product_name' => $item['product_name'],
                'product_name_en' => $item['product_name_en'] ?? null,
                'quantity' => $item['quantity'],
                'unit_price' => self::decimal($item['unit_price']),
                'base_unit_price' => self::nullableDecimal($item['base_unit_price'] ?? null),
                'personalization_surcharge' => self::nullableDecimal($item['personalization_surcharge'] ?? null),
            ]);

            $ids[] = (int) $this->db->lastInsertId();
        }

        return $ids;
    }

    /**
     * Accepts either an already-exact decimal string (what the personalization
     * pricing produces, built from integer cents) or a float (what every
     * existing caller passes) and stores a canonical "12.50".
     */
    private static function decimal(float|string $amount): string
    {
        return is_string($amount) ? $amount : number_format($amount, 2, '.', '');
    }

    private static function nullableDecimal(float|string|null $amount): ?string
    {
        return $amount === null ? null : self::decimal($amount);
    }

    public function setMolliePaymentId(int $orderId, string $paymentId): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET mollie_payment_id = :payment_id, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['payment_id' => $paymentId, 'id' => $orderId]);
    }

    public function findById(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, c.name AS customer_name, c.email AS customer_email
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);

        $order = $stmt->fetch();

        return $order === false ? null : $order;
    }

    public function findByMolliePaymentId(string $paymentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, c.name AS customer_name, c.email AS customer_email
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.mollie_payment_id = :payment_id
             LIMIT 1'
        );
        $stmt->execute(['payment_id' => $paymentId]);

        $order = $stmt->fetch();

        return $order === false ? null : $order;
    }

    /**
     * `name`/`name_en` come from the order_items snapshot columns
     * (product_name/product_name_en — see db/migrations/
     * 20260907170000_add_product_snapshot_to_order_items.php), never from a
     * live join to `products`, so a later product rename never changes what
     * a historical order shows. `image_path` is still a live join — purely
     * decorative, not commercial data that needs to survive a product being
     * re-photographed.
     *
     * The join to `products` is deliberately a LEFT JOIN: since
     * db/migrations/20260908120000_relax_order_item_product_foreign_keys.php
     * a catalog product can be deleted outright, which sets `product_id` to
     * NULL on its order lines. An INNER JOIN would silently drop those lines
     * from the order, the admin order page, the confirmation email and any
     * regenerated invoice PDF — the exact damage the snapshot columns exist to
     * prevent. With LEFT JOIN the line still renders in full from its own
     * snapshot; only the decorative `image_path` comes back NULL.
     *
     * `id` is the order_items row's own id, which the admin order page uses
     * to look up this line's personalization (see
     * App\Repository\OrderItemPersonalizationRepository::findByOrderIdGrouped()).
     * Every other consumer — the confirmation email, the invoice PDF —
     * simply ignores it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findItems(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT oi.id, oi.quantity, oi.unit_price, oi.base_unit_price, oi.personalization_surcharge,
                    oi.variant_label,
                    oi.product_name AS name, oi.product_name_en AS name_en, p.image_path
             FROM order_items oi
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = :order_id
             ORDER BY oi.id ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Applies the outcome of a Mollie payment to the order: our own simplified
     * `status` plus Mollie's raw `mollie_status`, so we always keep both what we
     * concluded and exactly what Mollie last reported.
     */
    public function updateStatusFromMollie(int $orderId, string $localStatus, string $mollieStatus): void
    {
        $stmt = $this->db->prepare(
            'UPDATE orders SET status = :status, mollie_status = :mollie_status, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'status' => $localStatus,
            'mollie_status' => $mollieStatus,
            'id' => $orderId,
        ]);
    }

    /**
     * Admin order overview: one row per order with just what the list view needs.
     *
     * `$fulfilmentStatus` filters on the admin's own handling status (server-side,
     * so the "Open" working list is a real query, not a client-side hide); null
     * means "all orders". An unrecognised value is ignored rather than returning
     * nothing, matching ContactRequestRepository::findAllForAdmin().
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForAdmin(?string $fulfilmentStatus = null): array
    {
        $sql = 'SELECT o.id, o.order_number, o.status, o.fulfilment_status, o.handled_at, o.total, o.shipping_cost,
                       o.shipping_method, o.created_at, c.name AS customer_name
                FROM orders o
                JOIN customers c ON c.id = o.customer_id';
        $params = [];

        if ($fulfilmentStatus !== null && in_array($fulfilmentStatus, self::FULFILMENT_STATUSES, true)) {
            $sql .= ' WHERE o.fulfilment_status = :fulfilment_status';
            $params['fulfilment_status'] = $fulfilmentStatus;
        }

        $sql .= ' ORDER BY o.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Admin order detail: same as findById() but also includes the customer's
     * shipping/contact details needed on the order detail page.
     */
    public function findByIdForAdmin(int $orderId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                    c.address_line, c.postal_code, c.city, c.country
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId]);

        $order = $stmt->fetch();

        return $order === false ? null : $order;
    }

    /**
     * Marks the order as handled and stamps `handled_at`. Touches only those
     * two admin-owned columns — never `status`/`mollie_status` (Mollie's), and
     * never the invoice or refund records.
     *
     * Restricted to paid orders at the SQL level as a second guard on top of
     * the same check in api/admin/update-fulfilment-status.php: an order that
     * was never paid must never be presentable as dealt with. Returns false
     * when nothing was updated (unknown order, or not paid).
     */
    public function markHandled(int $orderId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET fulfilment_status = :status, handled_at = NOW(), updated_at = NOW()
             WHERE id = :id AND status = 'paid'"
        );
        $stmt->execute([
            'status' => self::FULFILMENT_HANDLED,
            'id' => $orderId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Puts the order back on the working list and clears `handled_at` — the
     * undo for markHandled(), for when the wrong order was ticked off. Same
     * two columns, same hands-off treatment of payment/invoice/refund data.
     *
     * Deliberately *not* restricted to paid orders, unlike markHandled():
     * reopening only ever moves an order back onto the to-do list, so it can
     * never make an unpaid order look dealt with. Guarding it would instead
     * risk stranding an order as "Afgehandeld" with no way back if its payment
     * status ever changed afterwards.
     */
    public function markOpen(int $orderId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE orders SET fulfilment_status = :status, handled_at = NULL, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => self::FULFILMENT_OPEN,
            'id' => $orderId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Sets the order's cached total refunded amount — always the value
     * Mollie itself reports (Payment::getAmountRefunded()), never a sum we
     * compute locally, so it can never drift from what Mollie considers
     * refunded. Never touches `total`: the original sale amount and the
     * refunded amount must stay independently readable (see MAIN.MD
     * "Refunds").
     */
    public function setRefundedAmount(int $orderId, float $refundedAmount): void
    {
        $stmt = $this->db->prepare(
            'UPDATE orders SET refunded_amount = :amount, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'amount' => number_format($refundedAmount, 2, '.', ''),
            'id' => $orderId,
        ]);
    }

    /**
     * Records/updates one Mollie refund locally, keyed by its Mollie refund
     * id (unique) — safe to call repeatedly for the same refund (duplicate
     * webhook delivery, or a refund's status progressing from "pending" to
     * "refunded" across several webhook calls) without ever creating a
     * duplicate row.
     */
    public function upsertRefund(
        int $orderId,
        string $mollieRefundId,
        float $amount,
        string $status,
        ?string $description,
        \DateTimeInterface $createdAt
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO order_refunds (order_id, mollie_refund_id, amount, status, description, created_at, updated_at)
             VALUES (:order_id, :mollie_refund_id, :amount, :status, :description, :created_at, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), amount = VALUES(amount), description = VALUES(description), updated_at = NOW()'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'mollie_refund_id' => $mollieRefundId,
            'amount' => number_format($amount, 2, '.', ''),
            'status' => $status,
            'description' => $description,
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRefunds(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT mollie_refund_id, amount, status, description, created_at
             FROM order_refunds
             WHERE order_id = :order_id
             ORDER BY created_at ASC'
        );
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /**
     * Admin CSV export: one row per order (not per order line — see
     * App\Service\OrderCsvExport), with a summarized, human-readable product
     * list. `items_summary` is NULL for an order whose items were somehow
     * deleted (never happens in practice — order_items.order_id cascades
     * from `orders`, so an order's own items only disappear if the order
     * itself is deleted).
     *
     * The summary is assembled in PHP rather than by GROUP_CONCAT, which
     * stops at group_concat_max_len (1024 bytes by default) and says nothing
     * when it does: an order with enough lines reached the bookkeeping CSV
     * with part of its product list cut off mid-word, and nothing downstream
     * could tell. Raising that setting would only move the ceiling, and it
     * would do so by mutating shared connection state for every later query
     * in the request; building the string here removes the ceiling instead.
     *
     * Two queries whatever the date range covers — every matched order's
     * lines are read at once, never one query per order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(?string $fromDate, ?string $toDate): array
    {
        $conditions = [];
        $params = [];
        if ($fromDate !== null) {
            $conditions[] = 'o.created_at >= :from_date';
            $params['from_date'] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== null) {
            $conditions[] = 'o.created_at <= :to_date';
            $params['to_date'] = $toDate . ' 23:59:59';
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $stmt = $this->db->prepare(
            "SELECT o.id, o.order_number, o.created_at, o.total, o.shipping_cost, o.refunded_amount,
                    o.status, o.fulfilment_status, o.mollie_payment_id, o.currency,
                    COALESCE(o.shipping_country, c.country) AS country,
                    c.name AS customer_name, c.email AS customer_email
             FROM orders o
             JOIN customers c ON c.id = o.customer_id"
            . $where .
            ' ORDER BY o.created_at ASC'
        );
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $summaries = $this->itemSummaries(
            array_map(static fn (array $order): int => (int) $order['id'], $orders)
        );

        foreach ($orders as $index => $order) {
            $orders[$index]['items_summary'] = $summaries[(int) $order['id']] ?? null;
        }

        return $orders;
    }

    /**
     * One "2x Naam (Variant); 1x Andere naam" line per order id, for all the
     * given orders in a single query.
     *
     * An order that contributes nothing is simply absent from the result,
     * which is what leaves its `items_summary` NULL. A line without a
     * product-name snapshot contributes nothing either — the same silent
     * skip the SQL had, where CONCAT() over a NULL column produced NULL and
     * GROUP_CONCAT() left it out.
     *
     * @param list<int> $orderIds
     * @return array<int, string>
     */
    private function itemSummaries(array $orderIds): array
    {
        $ids = array_values(array_unique($orderIds));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->db->prepare(
            "SELECT order_id, quantity, product_name, variant_label
             FROM order_items
             WHERE order_id IN ({$placeholders})
             ORDER BY order_id ASC, id ASC"
        );
        $stmt->execute($ids);

        $lines = [];

        foreach ($stmt->fetchAll() as $item) {
            if ($item['product_name'] === null || $item['product_name'] === '') {
                continue;
            }

            $variant = (string) ($item['variant_label'] ?? '');

            $lines[(int) $item['order_id']][] = (int) $item['quantity'] . 'x ' . $item['product_name']
                . ($variant !== '' ? ' (' . $variant . ')' : '');
        }

        return array_map(
            static fn (array $orderLines): string => implode('; ', $orderLines),
            $lines
        );
    }
}
