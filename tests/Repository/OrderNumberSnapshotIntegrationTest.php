<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\CustomerRepository;
use App\Repository\DashboardRepository;
use App\Repository\OrderRepository;
use App\Service\OrderCsvExport;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests against the test database (the convention of
 * OrderSnapshotIntegrationTest): an order is given its public number once, by
 * OrderRepository::create(), and every read model hands out that stored
 * number, whatever `order_number_prefix` says by the time someone looks.
 *
 * The prefix is pinned per test with SiteSettings::overrideForTests(), which
 * is what create() reads, so the test database's own settings never decide
 * the outcome.
 *
 * The read models are the ones behind every screen and document that shows a
 * number: the order-status API and the order detail (findById,
 * findByIdForAdmin), the order list (findAllForAdmin), the bookkeeping export
 * (findForExport, through OrderCsvExport) and the dashboard
 * (DashboardRepository::findRecentOrders).
 */
final class OrderNumberSnapshotIntegrationTest extends TestCase
{
    private const CUSTOMER_EMAIL = 'order-number-snapshot@__test__.invalid';

    private ?int $customerId = null;

    /** @var list<int> */
    private array $orderIds = [];

    protected function tearDown(): void
    {
        SiteSettings::overrideForTests(null);

        $db = Database::connection();
        foreach ($this->orderIds as $orderId) {
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $orderId]);
        }
        $this->orderIds = [];

        if ($this->customerId !== null) {
            $db->prepare('DELETE FROM customers WHERE id = :id')->execute(['id' => $this->customerId]);
            $this->customerId = null;
        }
    }

    public function testAnOrderKeepsTheNumberItWasCreatedWithWhenThePrefixChanges(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'VLD']);
        $orderId = $this->createOrder();
        $created = 'VLD-' . $this->creationYear($orderId) . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);

        $this->assertSame($this->everywhere($created), $this->numbersFromEveryReadModel($orderId));
    }

    public function testAnOrderCreatedAfterThePrefixChangedGetsTheNewPrefix(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'VLD']);
        $before = $this->createOrder();

        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);
        $after = $this->createOrder();

        $this->assertSame(
            $this->everywhere('SHOP-' . $this->creationYear($after) . '-' . str_pad((string) $after, 6, '0', STR_PAD_LEFT)),
            $this->numbersFromEveryReadModel($after)
        );
        $this->assertSame(
            $this->everywhere('VLD-' . $this->creationYear($before) . '-' . str_pad((string) $before, 6, '0', STR_PAD_LEFT)),
            $this->numbersFromEveryReadModel($before)
        );
    }

    /**
     * A stored number that no id, year or prefix could produce: a read model
     * that still rebuilt the number would show here.
     */
    public function testEveryReadModelHandsOutTheStoredNumberEvenWhenItCannotBeRebuilt(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'SHOP']);
        $orderId = $this->createOrder();
        $stored = 'HIST-1999-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

        Database::connection()
            ->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id')
            ->execute(['order_number' => $stored, 'id' => $orderId]);

        $this->assertSame($this->everywhere($stored), $this->numbersFromEveryReadModel($orderId));
    }

    /**
     * create() joins the transaction the checkout already holds: the order
     * and its number are written, and rolled back, as one. There is no moment
     * at which the order exists without its number.
     */
    public function testTheNumberIsWrittenInsideTheTransactionThatCreatesTheOrder(): void
    {
        SiteSettings::overrideForTests(['order_number_prefix' => 'ORD']);
        $this->customer();
        $db = Database::connection();
        $orders = new OrderRepository($db);

        $db->beginTransaction();
        try {
            $orderId = $this->createOrder($orders);
            $this->assertStringStartsWith('ORD-', (string) $orders->findById($orderId)['order_number']);
        } finally {
            $db->rollBack();
        }

        $this->assertNull($orders->findById($orderId), 'Rolled back together with its number.');
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, string> the same $number under every read model's name */
    private function everywhere(string $number): array
    {
        return array_fill_keys(['findById', 'findByIdForAdmin', 'findAllForAdmin', 'findForExport', 'findRecentOrders'], $number);
    }

    /** @return array<string, string> read model => the number it hands out for $orderId */
    private function numbersFromEveryReadModel(int $orderId): array
    {
        $orders = new OrderRepository();
        $thisOrder = static fn (array $rows): array => array_values(
            array_filter($rows, static fn (array $row): bool => (int) $row['id'] === $orderId)
        )[0] ?? [];

        return [
            'findById' => OrderRepository::orderNumber($orders->findById($orderId) ?? []),
            'findByIdForAdmin' => OrderRepository::orderNumber($orders->findByIdForAdmin($orderId) ?? []),
            'findAllForAdmin' => OrderRepository::orderNumber($thisOrder($orders->findAllForAdmin())),
            'findForExport' => OrderCsvExport::row($thisOrder($orders->findForExport(null, null)))[
                array_search('Ordernummer', OrderCsvExport::header(), true)
            ],
            'findRecentOrders' => OrderRepository::orderNumber($thisOrder((new DashboardRepository())->findRecentOrders(50))),
        ];
    }

    private function customer(): int
    {
        return $this->customerId ??= (new CustomerRepository())->findOrCreateByEmail([
            'name' => 'Order Number Snapshot',
            'email' => self::CUSTOMER_EMAIL,
            'phone' => null,
            'address_line' => 'Teststraat 1',
            'postal_code' => '1234AB',
            'city' => 'Teststad',
            'country' => 'NL',
        ]);
    }

    private function createOrder(?OrderRepository $orders = null): int
    {
        $orderId = ($orders ?? new OrderRepository())->create(
            $this->customer(),
            10.00,
            0.00,
            'afhalen',
            'EUR',
            true,
            new \DateTimeImmutable(),
            hash('sha256', 'test-terms'),
            [
                'first_name' => 'Order', 'last_name' => 'Number', 'company' => null,
                'country' => 'NL', 'postal_code' => '1234AB', 'house_number' => '1',
                'house_number_addition' => null, 'street' => 'Teststraat', 'city' => 'Teststad',
            ],
            null
        );
        $this->orderIds[] = $orderId;

        return $orderId;
    }

    /** The year the database recorded for the order: the one its number was made with. */
    private function creationYear(int $orderId): string
    {
        $statement = Database::connection()->prepare('SELECT created_at FROM orders WHERE id = :id');
        $statement->execute(['id' => $orderId]);

        return substr((string) $statement->fetchColumn(), 0, 4);
    }
}
