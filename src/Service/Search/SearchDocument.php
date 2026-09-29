<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One piece of public content a SearchProvider offers for a query: its words
 * in the language asked for (fallback already applied), its address in that
 * language and, when the provider has one at hand, a thumbnail. PLAIN TEXT
 * only — a provider turns rich text into text with SearchText::plain() — so
 * nothing in a document is ever HTML, and every screen escapes it.
 *
 * A provider hands over only content a visitor may see (its own visibility
 * rule); whether a document MATCHES is decided by SearchService, with one
 * scoring rule for every provider.
 */
final class SearchDocument
{
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $url,
        public readonly ?string $thumbnail = null
    ) {
    }
}
