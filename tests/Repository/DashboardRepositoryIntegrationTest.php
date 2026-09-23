<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\DashboardRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\ShopLocalization;
use App\Repository\ProductVariantRepository;
use App\Repository\ProductImageRepository;
use App\Repository\ProductVariantImageRepository;
use App\Service\DashboardMetrics;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the real dev database, same convention as
 * tests/Repository/OrderHandlingStatusTest.php — the dashboard's queries are
 * date filtering, status filtering and a default-variant join, none of which
 * a mock would exercise meaningfully.
 *
 * The database already holds the owner's own orders and products, so every
 * test here is written to be immune to them: the order windows sit in the
 * year 2000, where no real row can land, and the product assertions look only
 * at the fixture rows by id. The one query with no window of its own
 * (countOrdersAwaitingHandling) is measured as a DIFFERENCE around the
 * fixtures instead of as an absolute number.
 *
 * Everything created here uses an obviously-fake slug ('__test_dashboard_*__')
 * and a @__test__.invalid customer address; tearDown() removes all of it.
 */
final class DashboardRepositoryIntegrationTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'dashboard-test@__test__.invalid';

    /** A month no real order can fall in, so windowed queries see only fixtures. */
    private const WINDOW_FROM = '2000-06-01 00:00:00';
    private const WINDOW_UNTIL = '2000-07-01 00:00:00';

    /** @var list<int> */
    private array $orderIds = [];
    /** @var list<int> */
    private array $productIds = [];
    private ?int $customerId = null;

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->orderIds as $orderId) {
            $db->prepare('DELETE FROM order_refunds WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $orderId]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }

        foreach ($this->productIds as $productId) {
            $db->prepare('DELETE FROM product_variants WHERE product_id = :id')->execute(['id' => $productId]);
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $productId]);
        }
        $this->productIds = [];
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * An order with a chosen payment status, creation moment, total and
     * refunded amount. Status/created_at/refunded_amount are written directly
     * because there is no non-Mollie way to produce a "failed" order dated in
     * the year 2000.
     */
    private function createOrder(
        string $paymentStatus,
        string $createdAt,
        float $total = 50.00,
        float $refunded = 0.00
    ): int {
        $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Dashboard Test',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);

        $orderId = (new OrderRepository())->create(
            $this->customerId,
            $total,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Dashboard', 'last_name' => 'Test', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );

        Database::connection()
            ->prepare('UPDATE orders SET status = :status, created_at = :created_at, refunded_amount = :refunded WHERE id = :id')
            ->execute([
                'status' => $paymentStatus,
                'created_at' => $createdAt,
                'refunded' => number_format($refunded, 2, '.', ''),
                'id' => $orderId,
            ]);

        $this->orderIds[] = $orderId;

        return $orderId;
    }

    private function createProduct(string $suffix, array $overrides = []): int
    {
        $productId = (new ProductRepository())->create($overrides + [
            'slug' => '__test_dashboard_' . $suffix . '__',
            'price' => 12.50,
            'image_path' => null,
            'active' => true,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($productId, 'nl', [ShopLocalization::NAME => 'Dashboardtest ' . $suffix]);
        ShopLocalization::clearCache();

        $this->productIds[] = $productId;

        return $productId;
    }

    /**
     * @return array<int, array<string, mixed>> the fixture products only, keyed by id
     */
    private function attentionRowsForFixtures(): array
    {
        $rows = [];

        foreach ((new DashboardRepository())->findActiveProductsForAttention() as $row) {
            $id = (int) $row['id'];
            if (in_array($id, $this->productIds, true)) {
                $rows[$id] = $row;
            }
        }

        return $rows;
    }

    private function totals(): array
    {
        return (new DashboardRepository())->orderTotalsBetween(
            DashboardMetrics::REVENUE_STATUSES,
            self::WINDOW_FROM,
            self::WINDOW_UNTIL
        );
    }

    /* ------------------------------------------------------------------ */
    /* Order totals                                                        */
    /* ------------------------------------------------------------------ */

    public function testAnEmptyWindowSumsToZeroRatherThanNull(): void
    {
        $totals = $this->totals();

        $this->assertSame(0, $totals['order_count']);
        $this->assertSame(0.0, (float) $totals['gross_total']);
        $this->assertSame(0.0, (float) $totals['refunded_total']);
    }

    public function testOnlyPaidOrdersAreSummed(): void
    {
        $this->createOrder('paid', '2000-06-10 12:00:00', 40.00);
        $this->createOrder('pending', '2000-06-10 12:00:00', 999.00);
        $this->createOrder('failed', '2000-06-10 12:00:00', 999.00);
        $this->createOrder('canceled', '2000-06-10 12:00:00', 999.00);
        $this->createOrder('expired', '2000-06-10 12:00:00', 999.00);

        $totals = $this->totals();

        $this->assertSame(1, $totals['order_count']);
        $this->assertSame(40.00, (float) $totals['gross_total']);
    }

    public function testOrdersOutsideTheWindowAreIgnored(): void
    {
        $this->createOrder('paid', '2000-05-31 23:59:59', 111.00);
        $this->createOrder('paid', '2000-06-15 09:00:00', 25.00);
        $this->createOrder('paid', '2000-07-01 00:00:01', 222.00);

        $totals = $this->totals();

        $this->assertSame(1, $totals['order_count']);
        $this->assertSame(25.00, (float) $totals['gross_total']);
    }

    /**
     * The window is half-open: an order at exactly the start belongs to it,
     * an order at exactly the end belongs to the next one. That is what stops
     * a midnight order from being counted in two months.
     */
    public function testTheWindowIsHalfOpenAtBothEnds(): void
    {
        $this->createOrder('paid', self::WINDOW_FROM, 10.00);
        $this->createOrder('paid', self::WINDOW_UNTIL, 999.00);

        $totals = $this->totals();

        $this->assertSame(1, $totals['order_count']);
        $this->assertSame(10.00, (float) $totals['gross_total']);
    }

    public function testRefundsAreSummedSeparatelyFromTheSaleAmounts(): void
    {
        $this->createOrder('paid', '2000-06-05 10:00:00', 100.00, 25.00);
        $this->createOrder('paid', '2000-06-06 10:00:00', 60.00, 60.00);

        $totals = $this->totals();
        $period = DashboardMetrics::period($totals);

        $this->assertSame(2, $period['order_count']);
        $this->assertSame(160.00, $period['gross']);
        $this->assertSame(85.00, $period['refunded']);
        $this->assertSame(75.00, $period['revenue']);
        $this->assertSame(37.50, $period['average_order_value']);
    }

    public function testAnEmptyStatusListIsAnsweredWithoutQuerying(): void
    {
        $this->createOrder('paid', '2000-06-10 12:00:00', 40.00);

        $totals = (new DashboardRepository())->orderTotalsBetween([], self::WINDOW_FROM, self::WINDOW_UNTIL);

        $this->assertSame(0, $totals['order_count']);
        $this->assertSame(0.0, (float) $totals['gross_total']);
    }

    /* ------------------------------------------------------------------ */
    /* Orders awaiting handling                                            */
    /* ------------------------------------------------------------------ */

    public function testOnlyPaidOrdersStillOpenCountAsAwaitingHandling(): void
    {
        $repository = new DashboardRepository();
        $before = $repository->countOrdersAwaitingHandling(
            DashboardMetrics::PAID_STATUS,
            OrderRepository::FULFILMENT_OPEN
        );

        // Two that must count.
        $this->createOrder('paid', '2000-06-10 12:00:00');
        $this->createOrder('paid', '2000-06-11 12:00:00');
        // An unpaid one is not a task, and a handled one is done.
        $this->createOrder('pending', '2000-06-12 12:00:00');
        $handled = $this->createOrder('paid', '2000-06-13 12:00:00');
        $this->assertTrue((new OrderRepository())->markHandled($handled));

        $after = $repository->countOrdersAwaitingHandling(
            DashboardMetrics::PAID_STATUS,
            OrderRepository::FULFILMENT_OPEN
        );

        $this->assertSame(2, $after - $before);
    }

    /* ------------------------------------------------------------------ */
    /* Recent orders                                                       */
    /* ------------------------------------------------------------------ */

    public function testRecentOrdersComeBackNewestFirstAndIncludeEveryPaymentStatus(): void
    {
        // Dated in the future so the fixtures are provably the newest rows,
        // whatever else the dev database happens to contain.
        $oldest = $this->createOrder('failed', '2099-01-01 08:00:00');
        $middle = $this->createOrder('pending', '2099-01-02 08:00:00');
        $newest = $this->createOrder('paid', '2099-01-03 08:00:00');

        $recent = (new DashboardRepository())->findRecentOrders(3);

        $this->assertSame(
            [$newest, $middle, $oldest],
            array_map(static fn (array $row): int => (int) $row['id'], $recent)
        );
        $this->assertSame(['paid', 'pending', 'failed'], array_column($recent, 'status'));
    }

    public function testRecentOrdersRespectTheLimit(): void
    {
        $this->createOrder('paid', '2099-01-01 08:00:00');
        $this->createOrder('paid', '2099-01-02 08:00:00');
        $this->createOrder('paid', '2099-01-03 08:00:00');

        $this->assertCount(2, (new DashboardRepository())->findRecentOrders(2));
    }

    public function testARecentOrderCarriesEverythingTheDashboardShows(): void
    {
        $orderId = $this->createOrder('paid', '2099-01-04 08:00:00', 77.40);

        $row = (new DashboardRepository())->findRecentOrders(1)[0];

        $this->assertSame($orderId, (int) $row['id']);
        $this->assertSame('Dashboard Test', $row['customer_name']);
        $this->assertSame(77.40, (float) $row['total']);
        $this->assertSame(OrderRepository::FULFILMENT_OPEN, $row['fulfilment_status']);
        $this->assertNotEmpty($row['created_at']);
    }

    /* ------------------------------------------------------------------ */
    /* Products for the attention list                                     */
    /* ------------------------------------------------------------------ */

    public function testAnInactiveProductIsNeverReturned(): void
    {
        $this->createProduct('inactive', ['active' => false]);

        $this->assertSame([], $this->attentionRowsForFixtures());
    }

    public function testAProductWithItsOwnPhotoHasAnImage(): void
    {
        $productId = $this->createProduct('withphoto', ['image_path' => 'assets/images/products/example.webp']);

        $this->assertSame(1, (int) $this->attentionRowsForFixtures()[$productId]['has_image']);
    }

    public function testAProductWithoutAnyPhotoHasNoImage(): void
    {
        $productId = $this->createProduct('nophoto');

        $this->assertSame(0, (int) $this->attentionRowsForFixtures()[$productId]['has_image']);
    }

    /**
     * A picture belongs to the product; its DEFAULT variant may show a
     * selection of them (product_variant_images). A picture the default
     * variant links to is what the card shows, so it counts.
     */
    public function testAPictureTheDefaultVariantShowsCountsAsTheProductsImage(): void
    {
        $productId = $this->createProduct('variantphoto');
        $variantId = (new ProductVariantRepository())->create($productId, [], 12.50, true);
        $imageId = (new ProductImageRepository())->create($productId, 'assets/images/products/variant.webp');
        (new ProductVariantImageRepository())->replaceForVariant($variantId, [$imageId]);

        $this->assertSame(1, (int) $this->attentionRowsForFixtures()[$productId]['has_image']);
    }

    /**
     * Adding a variant no longer hides the product's own pictures: a default
     * variant that chose none shows them, so the card is not blank.
     */
    public function testAProductWhoseDefaultVariantChoseNoPictureKeepsItsOwn(): void
    {
        $productId = $this->createProduct('variantnophoto', ['image_path' => 'assets/images/products/example.webp']);
        (new ProductVariantRepository())->create($productId, [], 12.50, true);

        $this->assertSame(1, (int) $this->attentionRowsForFixtures()[$productId]['has_image']);
    }

    public function testAProductWithVariantsAndNoPictureAnywhereHasNoImage(): void
    {
        $productId = $this->createProduct('variantsnothing');
        $variantRepository = new ProductVariantRepository();
        $variantRepository->create($productId, [], 12.50, false);
        $variantRepository->create($productId, [], 12.50, true);

        $this->assertSame(0, (int) $this->attentionRowsForFixtures()[$productId]['has_image']);
    }

    public function testTheAttentionRowCarriesPriceAndBothSalesChannels(): void
    {
        $productId = $this->createProduct('channels', [
            'price' => 19.95,
            'in_shop' => false,
            'in_personalization_catalog' => true,
        ]);

        $row = $this->attentionRowsForFixtures()[$productId];

        $this->assertSame(19.95, (float) $row['price']);
        $this->assertSame(0, (int) $row['in_shop']);
        $this->assertSame(1, (int) $row['in_personalization_catalog']);

        // The query carries no name since Multilingual 2.0 phase 5 wave C: a
        // product is named per website language, so admin/_dashboard_shop.php
        // adds the words and decides the alphabetical order.
        $this->assertArrayNotHasKey('name', $row);
        $this->assertSame('Dashboardtest channels', ShopLocalization::productName($productId));
    }
}
