<?php

declare(strict_types=1);

namespace App\Service;

/**
 * THE one way a public page puts things in a random order: the Projecten
 * block and a gallery on "Willekeurig", and the related projects of a project
 * page (App\Service\PortfolioGalleryContent, App\Service\PortfolioRelatedProjects).
 *
 * THE SERVER CHOOSES, per request. Whoever asks passes the ids that may be
 * shown — already filtered to what is visible, never a list the browser
 * sees — and renders only what comes back; nothing random happens in a
 * browser. There is no page cache in front of a public page (no Cache-Control
 * on its HTML and no stored output, CONTENT-BLOCKS.md "Willekeurige
 * volgorde"), and the only caches are per request, so every real request is a
 * new draw, while one request that draws the same block twice keeps its
 * first answer through that block's own per-request cache.
 *
 * PHP's own \Random\Randomizer (8.2) with its default, cryptographically
 * secure engine: a shuffle of a few hundred ids is nothing next to one query.
 *
 * THE TEST SEAM: useEngineForTests() swaps in a seeded engine, so a test sees
 * the same "random" order every run and never depends on luck, and calls()
 * counts the draws, so a test can prove a draw happens per render rather than
 * once and stored. Never set outside a test.
 */
final class RandomOrder
{
    private static ?\Random\Randomizer $randomizer = null;

    private static int $calls = 0;

    /**
     * $items in a random order, every one of them exactly once.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function shuffle(array $items): array
    {
        self::$calls++;

        if (count($items) < 2) {
            return array_values($items);
        }

        return array_values((self::$randomizer ?? new \Random\Randomizer())->shuffleArray(array_values($items)));
    }

    /**
     * At most $max of $items, chosen at random and in a random order; all of
     * them, shuffled, when $max is null.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public static function sample(array $items, ?int $max): array
    {
        $shuffled = self::shuffle($items);

        return $max === null ? $shuffled : array_slice($shuffled, 0, max(0, $max));
    }

    /** How many draws this process made since the last reset: a test's proof that a render draws again. */
    public static function calls(): int
    {
        return self::$calls;
    }

    /**
     * Test seam: every draw from now on comes from $engine (a seeded
     * \Random\Engine\Mt19937, say), or from the secure default again with
     * null. Resets calls().
     */
    public static function useEngineForTests(?\Random\Engine $engine): void
    {
        self::$randomizer = $engine === null ? null : new \Random\Randomizer($engine);
        self::$calls = 0;
    }
}
