<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * How a feature answers "do you use any of these media items, and where?".
 *
 * THE ONE RULE THAT MAKES THIS WORK: a provider is asked about a whole BATCH
 * of media ids at once and must answer with a bounded number of queries —
 * one, in every implementation this project has. The Media Library listing
 * shows a page of items and needs a usage count for each; asking per item
 * would be the N+1 the requirement explicitly rules out.
 *
 * WHY PROVIDERS AND NOT A media_references TABLE. A reference table is a
 * second source of truth that every write path has to remember to update,
 * and the day one forgets, the library confidently reports that a used image
 * is unused and offers to delete it. Deriving usage from the feature's own
 * columns cannot drift: the column IS the reference. The cost is that a
 * feature must contribute a provider when it starts using media, which is
 * one small class and is the same shape as every other module contribution
 * in this codebase (MODULES.md).
 *
 * WHO MAY IMPLEMENT IT. Core does, for the features it owns. A module does,
 * for its own tables — which is what keeps Core Media from ever naming a
 * product, an order or a collection. See App\Module\ModuleDefinition.
 */
abstract class MediaUsageProvider
{
    /** Stable key, used in MediaUsage::$source and in tests. */
    abstract public function key(): string;

    /** Dutch label for the group heading in the admin. */
    abstract public function label(): string;

    /**
     * Every usage of every given media id.
     *
     * @param list<int> $mediaIds never empty when called
     *
     * @return array<int, list<MediaUsage>> keyed by media id; ids with no
     *                                      usage may be omitted entirely
     */
    abstract public function usagesFor(array $mediaIds): array;

    /**
     * The `?` placeholder list for an IN clause, so every implementation
     * builds one the same way instead of inventing its own.
     */
    final protected function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
