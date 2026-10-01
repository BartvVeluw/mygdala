<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Repository\PageRepository;
use App\Service\Language\SiteText;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageSeo;
use App\Service\PageTranslation;

/**
 * Core's search provider: the site's CMS pages (SearchProvider).
 *
 * VISIBILITY is the sitemap's rule, the very same call:
 * App\Service\PageSeo::isIndexable() — published, not marked noindex, and
 * served by an enabled module (a route-bound page of a switched-off module,
 * a module's placeholder system page and every page nested under a
 * switched-off module's root answer 404, so they are not found either). A
 * page the editor marked noindex said it does not belong in search results;
 * the site's own search honours that too.
 *
 * WHAT IS SEARCHED: the page's title and its meta description, in the
 * language asked for with the usual fallback (PageLocalization), and — Search
 * 2.0 — the words of its shown content blocks in that language, from the
 * block text index (BlockSearchIndex), weighed below the page's own words.
 *
 * COST: pages are few, so every published page is read in one query and
 * their words in one more (PageLocalization::preload); the block text costs
 * two more, whatever the number of pages: the pages whose blocks have a term
 * (one escaped LIKE), then their text. SearchService decides what matches.
 * The page words are read only through PageLocalization
 * (Tests\Service\MultilingualBoundaryTest).
 *
 * THE ADDRESS is the one the site itself links to in that language
 * (PageContent::publicUrl): the page's own address there, else its address
 * in the default language — as a menu link to that page would be.
 */
final class PageSearchProvider implements SearchProvider
{
    public function label(string $language): string
    {
        return SiteText::pick(['nl' => 'Pagina', 'en' => 'Page'], $language);
    }

    public function documents(SearchQuery $query, string $language, int $limit): array
    {
        $pages = array_values(array_filter(
            (new PageRepository())->findAllPublished(),
            static fn (array $page): bool => PageSeo::isIndexable($page)
        ));
        PageLocalization::preload(array_map(static fn (array $page): int => (int) $page['id'], $pages));

        // Block text only of the published pages whose blocks have a term.
        $withBlockMatch = array_flip(BlockSearchIndex::pagesMatchingAny($query->needles(), $language));
        $blockTexts = BlockSearchIndex::pageTexts(array_values(array_filter(
            array_map(static fn (array $page): int => (int) $page['id'], $pages),
            static fn (int $id): bool => isset($withBlockMatch[$id])
        )), $language);

        // Matched here already (the same SearchText rule SearchService
        // applies), so $limit counts matches and a large site's later pages
        // are never cut off before they were compared.
        $folded = $query->folded();
        $terms = $query->foldedTerms();
        $documents = [];
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $title = trim(PageLocalization::title($id, $language));
            $text = SearchText::plain(PageLocalization::value($id, PageTranslation::META_DESCRIPTION, $language));
            $blocks = $blockTexts[$id] ?? ['headings' => '', 'body' => ''];
            if ($title === '' || SearchText::score($folded, $title, $text, $terms, $blocks['headings'], $blocks['body']) === 0) {
                continue;
            }

            $documents[] = new SearchDocument($title, $text, PageContent::publicUrl($page, $language), null, $blocks['headings'], $blocks['body']);

            if (count($documents) >= $limit) {
                break;
            }
        }

        return $documents;
    }
}
