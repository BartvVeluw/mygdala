<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * One kind of public content the site search looks through: Core's pages
 * (PageSearchProvider), and whatever an enabled module contributes through
 * App\Module\ModuleDefinition::searchProviders() — products, projects, blog
 * posts. SearchService never names a module's class; it asks the registry,
 * as App\Service\Sitemap does for its collectors (SEARCH.md).
 *
 * The contract, for every implementation:
 *
 *   - VISIBILITY IS THE PROVIDER'S, and it is the same rule the public page
 *     applies: a document a visitor would get a 404 for (a draft, an
 *     inactive product, a hidden project, a post not yet published, a page
 *     of a switched-off module) is never returned. A module that is off
 *     contributes no provider at all.
 *   - A candidate list, not the answer: return what MAY match, in the
 *     provider's natural order (newest post first, the catalogue's order).
 *     SearchService scores and drops the rest, so a cheap prefilter (a
 *     LIKE over the translation table) is enough.
 *   - Words and address in $language, with the site's own fallback for
 *     words (App\Service\Language\LanguageFallback) and the address the site
 *     itself links to in that language.
 *   - Batched: a fixed number of queries per search, never one per result.
 *   - At most $limit documents.
 */
interface SearchProvider
{
    /** What one result is, for the visitor: "Pagina", "Product" … */
    public function label(string $language): string;

    /** @return list<SearchDocument> */
    public function documents(SearchQuery $query, string $language, int $limit): array;
}
