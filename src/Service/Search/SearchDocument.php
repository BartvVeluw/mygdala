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
 *
 * Four kinds of words, weighed in this order (SearchText::score()):
 *
 *   $title            the name: a page title, a product name ...
 *   $text             the owner's own summary: meta description,
 *                     product description, intro, excerpt
 *   $contentHeadings  the headings in its content (Search 2.0: the block
 *                     headings, BlockSearchIndex)
 *   $content          all of its content as read (the block text, or a
 *                     classic blog post's body), headings included
 */
final class SearchDocument
{
    public function __construct(
        public readonly string $title,
        public readonly string $text,
        public readonly string $url,
        public readonly ?string $thumbnail = null,
        public readonly string $contentHeadings = '',
        public readonly string $content = ''
    ) {
    }
}
