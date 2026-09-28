<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\RandomOrder;
use PHPUnit\Framework\TestCase;

/**
 * App\Service\RandomOrder, the one random order of a public page: every item
 * exactly once, at most the maximum, only what it was given, a draw counted
 * per call — and, with a seeded engine, the same answer every run, so no test
 * that relies on it depends on luck. What is NOT tested, on purpose: that the
 * next draw differs from this one. It may not, and that is fine.
 */
final class RandomOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        RandomOrder::useEngineForTests(null);
    }

    public function testAShuffleKeepsEveryItemExactlyOnce(): void
    {
        $items = range(1, 40);

        for ($i = 0; $i < 20; $i++) {
            $shuffled = RandomOrder::shuffle($items);
            self::assertCount(40, $shuffled);
            self::assertSame($items, self::sorted($shuffled));
            self::assertSame(array_keys($shuffled), range(0, 39), 'a list, not a map');
        }
    }

    public function testASampleIsAtMostTheMaximumAndOnlyWhatItWasGiven(): void
    {
        $items = [3, 5, 8, 13, 21, 34];

        for ($i = 0; $i < 20; $i++) {
            $sample = RandomOrder::sample($items, 4);
            self::assertCount(4, $sample);
            self::assertSame([], array_diff($sample, $items));
            self::assertSame(array_values(array_unique($sample)), $sample);
        }

        self::assertSame($items, self::sorted(RandomOrder::sample($items, null)), 'no maximum: all of them');
        self::assertCount(6, RandomOrder::sample($items, 10), 'never more than there are');
        self::assertSame([], RandomOrder::sample([], 3));
        self::assertSame([7], RandomOrder::sample([7], 3));
    }

    public function testEveryCallIsADrawAndASeededEngineRepeatsItself(): void
    {
        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(42));
        self::assertSame(0, RandomOrder::calls());

        $first = RandomOrder::sample(range(1, 20), 5);
        RandomOrder::shuffle([1]);
        self::assertSame(2, RandomOrder::calls(), 'a draw is counted even when there is nothing to shuffle');

        RandomOrder::useEngineForTests(new \Random\Engine\Mt19937(42));
        self::assertSame($first, RandomOrder::sample(range(1, 20), 5));
    }

    /**
     * @param list<int> $items
     *
     * @return list<int>
     */
    private static function sorted(array $items): array
    {
        sort($items);

        return $items;
    }
}
