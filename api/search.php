<?php

/**
 * GET /api/search.php?q=<query>[&lang=<code>]
 *
 * The live results under the header's search field (assets/js/search.js,
 * SEARCH.md): the first SearchService::LIVE_LIMIT results of the same search
 * the results page (zoeken.php) runs, as JSON, plus the total and the
 * address of that page for "Alle resultaten bekijken".
 *
 * Read-only and public, like api/products.php. It answers 404 while search is
 * switched off, so a site that never switched it on exposes nothing new.
 * `q` is normalised by SearchQuery (length, Unicode, control characters) and
 * never reaches SQL other than as an escaped LIKE parameter; `lang` is a
 * language code validated by ApiLanguage. Everything in the answer is plain
 * text and a root-relative URL; the script puts it on the page with
 * textContent. Nothing is stored: a search term is not logged, counted or
 * sent anywhere (no analytics — PageViewTracker only counts page views).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\Routing\ApiLanguage;
use App\Service\Search\SearchHit;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

const SEARCH_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Method not allowed'], SEARCH_JSON);
    exit;
}

if (!SearchService::isEnabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found'], SEARCH_JSON);
    exit;
}

$language = ApiLanguage::apply($_GET['lang'] ?? null);
$query = SearchQuery::fromInput($_GET['q'] ?? null);

$status = match (true) {
    $query->isEmpty() => 'empty',
    $query->isTooShort() => 'too_short',
    default => 'ok',
};

try {
    $results = SearchService::search($query, $language, 1, SearchService::LIVE_LIMIT);
} catch (\Throwable $e) {
    error_log('[api/search.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search failed'], SEARCH_JSON);
    exit;
}

echo json_encode([
    'query' => $query->text,
    'status' => $status,
    'total' => $results->total,
    'results' => array_map(static fn (SearchHit $hit): array => $hit->toArray(), $results->hits),
    'all_url' => SearchService::path($language) . ($query->isSearchable() ? '?' . http_build_query(['q' => $query->text]) : ''),
    'incomplete' => $results->failedTypes !== [],
], SEARCH_JSON);
