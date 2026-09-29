<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Module\ModuleRegistry;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\RouteSegments;
use App\Service\SiteSettings;

/**
 * Site search (SEARCH.md): one query through every provider, scored with one
 * rule, sorted and cut into pages.
 *
 * THE PROVIDERS are Core's pages plus whatever the ENABLED modules
 * contribute (ModuleRegistry::collectMap('searchProviders')) — the pattern of
 * App\Service\Sitemap::collectors() and App\Service\Routing\LinkTargets. Core
 * imports no module class, and a module that is off has no provider, so its
 * content cannot appear by a condition someone forgot.
 *
 * THE ORDER: score first (SearchText: exact title, title starts with, a
 * title word starts with, title contains, text contains), then the order
 * of the providers (pages, then the modules in registry order), then each
 * provider's own natural order. No other signal.
 *
 * ONE PROVIDER FAILING (a module table missing on a half-migrated site, say)
 * is logged and leaves out that kind of result; the others still answer, and
 * the result says which kinds are missing, so the screen can say so.
 *
 * Search is OFF until the site's administrator switches it on (Navigatie →
 * "Zoeken tonen", the site setting SETTING); until then no header control is
 * rendered and the results route answers 404, so an update changes nothing
 * on an existing site.
 */
final class SearchService
{
    public const SETTING = 'nav_search_enabled';

    /** The header control's files, shell files while search is on (App\Service\PageAssets). */
    public const STYLE = 'assets/css/search.css';

    public const SCRIPT = 'assets/js/search.js';

    /** The results page's route segment (App\Service\Routing\RouteSegments). */
    public const ROUTE_SEGMENT = 'core.search';

    /** The page number on the results page: the Blog's word (BlogUrls::PAGE_PARAM). */
    public const PAGE_PARAM = 'pagina';

    /** Results per page of the results page. */
    public const PER_PAGE = 10;

    /** Results in the live list under the search field. */
    public const LIVE_LIMIT = 8;

    /** The most documents one provider may hand over for one query. */
    public const MAX_PER_PROVIDER = 200;

    public static function isEnabled(): bool
    {
        return SiteSettings::get(self::SETTING) === '1';
    }

    /**
     * The results page in a language: /zoeken, /en/search. No query string —
     * a form adds `q`, and a link adds it with http_build_query().
     */
    public static function path(?string $language = null): string
    {
        $language ??= RequestLanguage::current();

        return LocalizedUrl::path('/' . RouteSegments::value(self::ROUTE_SEGMENT, $language), $language);
    }

    /**
     * Core's providers first, then the enabled modules'. "+" rather than
     * array_merge(): a module cannot replace Core's pages by reusing its key.
     *
     * @return array<string, SearchProvider>
     */
    public static function providers(): array
    {
        $providers = ['page' => new PageSearchProvider()];

        foreach (ModuleRegistry::collectMap('searchProviders') as $type => $provider) {
            if ($provider instanceof SearchProvider && !isset($providers[$type])) {
                $providers[$type] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @param array<string, SearchProvider>|null $providers null: providers()
     */
    public static function search(SearchQuery $query, string $language, int $page = 1, int $perPage = self::PER_PAGE, ?array $providers = null): SearchResults
    {
        $perPage = max(1, min(50, $perPage));

        if (!$query->isSearchable()) {
            return new SearchResults($query, [], 0, 1, $perPage, []);
        }

        $folded = $query->folded();
        $terms = $query->foldedTerms();
        $scored = [];
        $failed = [];
        $typeOrder = 0;

        foreach ($providers ?? self::providers() as $type => $provider) {
            $typeOrder++;

            try {
                $documents = $provider->documents($query, $language, self::MAX_PER_PROVIDER);
                $label = $provider->label($language);
            } catch (\Throwable $e) {
                error_log(sprintf('[SearchService] provider "%s" failed: %s', $type, $e->getMessage()));
                $failed[] = (string) $type;
                continue;
            }

            foreach (array_slice($documents, 0, self::MAX_PER_PROVIDER) as $position => $document) {
                if (!$document instanceof SearchDocument || !self::isSafeUrl($document->url)) {
                    continue;
                }

                $score = SearchText::score($folded, $document->title, $document->text, $terms);
                if ($score === 0) {
                    continue;
                }

                $scored[] = [
                    'hit' => new SearchHit(
                        (string) $type,
                        $label,
                        $document->title,
                        SearchText::excerpt($document->text, $folded, SearchText::EXCERPT_LENGTH, $terms),
                        $document->url,
                        $document->thumbnail !== null && self::isSafeUrl($document->thumbnail) ? $document->thumbnail : null,
                        $score
                    ),
                    'type' => $typeOrder,
                    'position' => $position,
                ];
            }
        }

        usort($scored, static fn (array $a, array $b): int => [$b['hit']->score, $a['type'], $a['position']] <=> [$a['hit']->score, $b['type'], $b['position']]);

        $total = count($scored);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $hits = array_map(
            static fn (array $row): SearchHit => $row['hit'],
            array_slice($scored, ($page - 1) * $perPage, $perPage)
        );

        return new SearchResults($query, $hits, $total, $page, $perPage, $failed);
    }

    /**
     * A result links only to an address on this site: root-relative, no
     * protocol-relative "//host", no scheme, no backslash trick. Every
     * provider builds its URLs with the site's own URL builders, so this is
     * a second line, not the first.
     */
    public static function isSafeUrl(string $url): bool
    {
        return $url !== ''
            && $url[0] === '/'
            && !str_starts_with($url, '//')
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x20"<>]/', $url) !== 1;
    }
}
