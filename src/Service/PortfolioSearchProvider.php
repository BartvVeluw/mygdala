<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\SiteText;
use App\Service\Routing\LocalizedUrl;
use App\Service\Search\SearchCandidates;
use App\Service\Search\SearchDocument;
use App\Service\Search\SearchProvider;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchText;

/**
 * Portfolio's contribution to the site search (App\Module\PortfolioModule::
 * searchProviders()): projects, by title, subtitle and intro.
 *
 * VISIBILITY is the project page's own rule (PortfolioSlug::isPublic():
 * visible, project page switched on, a slug to reach it by), applied in SQL
 * by PortfolioGalleryContent::searchableProjects(), which also leaves out an
 * item that redirects to a legacy published page — Core's page search finds
 * that page itself. A hidden item, or one without a project page, is never
 * a result: it has no address a visitor could open.
 *
 * COST, per search: one LIKE per field and term over
 * portfolio_item_translations (SearchCandidates::ids(), any language,
 * escaped), one query for the public rows among those ids, one for
 * the linked pages, one for their words. The picture is the item's own
 * thumbnail path, already on the row.
 */
final class PortfolioSearchProvider implements SearchProvider
{
    private const FIELDS = [PortfolioLocalization::TITLE, PortfolioLocalization::SUBTITLE, PortfolioLocalization::INTRO];

    public function label(string $language): string
    {
        return SiteText::pick(['nl' => 'Project', 'en' => 'Project'], $language);
    }

    public function documents(SearchQuery $query, string $language, int $limit): array
    {
        $ids = SearchCandidates::ids(PortfolioLocalization::items(), self::FIELDS, $query);
        if ($ids === []) {
            return [];
        }

        $items = PortfolioGalleryContent::searchableProjects($ids);
        PortfolioLocalization::preloadItems(array_map(static fn (array $item): int => $item['id'], $items));

        $documents = [];
        foreach ($items as $item) {
            $id = $item['id'];
            $title = trim(PortfolioLocalization::item($id, PortfolioLocalization::TITLE, $language));
            if ($title === '') {
                continue;
            }

            $subtitle = trim(PortfolioLocalization::item($id, PortfolioLocalization::SUBTITLE, $language));
            $intro = SearchText::plain(PortfolioLocalization::itemRich($id, PortfolioLocalization::INTRO, $language));
            $picture = (string) ($item['thumbnail_path'] ?? '') !== '' ? (string) $item['thumbnail_path'] : (string) ($item['image_path'] ?? '');

            $documents[] = new SearchDocument(
                $title,
                trim($subtitle . ($subtitle !== '' && $intro !== '' ? ' — ' : '') . $intro),
                LocalizedUrl::path(PortfolioGalleryContent::publicPath($item['slug']), $language),
                $picture !== '' ? '/' . ltrim($picture, '/') : null
            );

            if (count($documents) >= $limit) {
                break;
            }
        }

        return $documents;
    }
}
