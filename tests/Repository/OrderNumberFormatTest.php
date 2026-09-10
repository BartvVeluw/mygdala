<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Repository\OrderRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers OrderRepository::formatOrderNumber() — see MAIN.MD "Order
 * numbering". No database needed: it's a pure function of (id, created_at).
 */
final class OrderNumberFormatTest extends TestCase
{
    public function testFormatIncludesYearAndZeroPaddedId(): void
    {
        $number = OrderRepository::formatOrderNumber(127, new \DateTimeImmutable('2026-03-14'));

        $this->assertSame('VLD-2026-000127', $number);
    }

    public function testFormatIsStableForTheSameIdAndYear(): void
    {
        $a = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-01-01 00:00:01'));
        $b = OrderRepository::formatOrderNumber(42, new \DateTimeImmutable('2026-12-31 23:59:59'));

        $this->assertSame($a, $b);
    }

    public function testDifferentOrdersNeverProduceTheSameNumber(): void
    {
        $a = OrderRepository::formatOrderNumber(1, new \DateTimeImmutable('2026-01-01'));
        $b = OrderRepository::formatOrderNumber(2, new \DateTimeImmutable('2026-01-01'));

        $this->assertNotSame($a, $b);
    }

    public function testLargeIdIsNotTruncated(): void
    {
        $number = OrderRepository::formatOrderNumber(1234567, new \DateTimeImmutable('2027-05-01'));

        $this->assertSame('VLD-2027-1234567', $number);
    }
}
