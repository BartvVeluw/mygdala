<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One page of search results and what the screens need around it: the
 * query as normalised, the total, the page and the kinds of result that
 * could not be searched (SearchService).
 */
final class SearchResults
{
    /**
     * @param list<SearchHit> $hits
     * @param list<string> $failedTypes
     */
    public function __construct(
        public readonly SearchQuery $query,
        public readonly array $hits,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $failedTypes
    ) {
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages();
    }
}
