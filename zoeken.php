<?php

declare(strict_types=1);

/**
 * The site search's results page (SEARCH.md): /zoeken?q=…, /en/search?q=….
 * RouteTable's `core.search`, its words in RouteSegments.
 *
 * The page that works for everyone: without JavaScript (the header's
 * magnifier is a link here, its form a GET form here), for a shared link,
 * and after Enter in the header's field. The live list under that field is
 * the first few results of this very search (api/search.php).
 *
 * Answers 404 while search is switched off (Navigatie → "Zoeken tonen"), so
 * an update exposes no new page on a site that never turned it on.
 *
 * NOT INDEXABLE and canonical to the bare route: a results page is not
 * content, and every `?q=` would otherwise be a page of its own. The query
 * is view state (Tests\Service\Routing\QueryIdentityRoutesTest), so the
 * language switch leads to the other language's results page without it.
 *
 * Every word the visitor typed is shown only through htmlspecialchars(); the
 * results are plain text from SearchService.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/partials/public-request.php';

use App\Service\Breadcrumbs\BreadcrumbItem;
use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Language\SiteLanguages;
use App\Service\Language\SiteText;
use App\Service\Routing\LanguageAlternates;
use App\Service\Routing\LocalizedUrl;
use App\Service\Routing\RequestLanguage;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;

if (!SearchService::isEnabled()) {
    http_response_code(404);
    require __DIR__ . '/partials/route-not-found-page.php';
    exit;
}

require_once __DIR__ . '/partials/breadcrumb.php';

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$language = RequestLanguage::current();
$query = SearchQuery::fromInput($_GET['q'] ?? null);
$requestedPage = filter_var($_GET[SearchService::PAGE_PARAM] ?? null, FILTER_VALIDATE_INT);

$failed = false;
try {
    $results = SearchService::search($query, $language, is_int($requestedPage) && $requestedPage > 0 ? $requestedPage : 1);
} catch (\Throwable $e) {
    error_log('[zoeken.php] ' . $e->getMessage());
    $results = SearchService::search(SearchQuery::fromInput(''), $language);
    $failed = true;
}

$searchPath = SearchService::path($language);
$pageUrl = static function (int $page) use ($searchPath, $query): string {
    $parameters = ['q' => $query->text];
    if ($page > 1) {
        $parameters[SearchService::PAGE_PARAM] = $page;
    }

    return $searchPath . '?' . http_build_query($parameters);
};

$versions = [];
foreach (SiteLanguages::activeCodes() as $code) {
    $versions[$code] = SearchService::path($code);
}
LanguageAlternates::declareVersions($versions);

$heading = SiteText::pick(['nl' => 'Zoeken', 'en' => 'Search']);
$seoMetadata = \App\Service\SeoMetadata::create(
    title: \App\Service\Seo::routeTitle($query->isSearchable()
        ? sprintf(SiteText::pick(['nl' => 'Zoekresultaten voor “%s”', 'en' => 'Search results for “%s”']), $query->text)
        : $heading),
    description: SiteText::pick(['nl' => 'Zoek op deze website.', 'en' => 'Search this website.']),
    // $searchPath already carries the language prefix (SearchService::path()).
    canonical: \App\Service\AppUrl::canonical($searchPath),
    indexable: false,
);

?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>" data-url-prefix="<?= $h(LocalizedUrl::prefix()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require __DIR__ . '/partials/seo-head.php'; ?>
<?php require __DIR__ . '/partials/page-assets.php'; ?>
</head>
<body>
<?php require __DIR__ . '/partials/header.php'; ?>

<main id="main" class="search-page">
  <?php render_breadcrumb(BreadcrumbTrail::home()->to(BreadcrumbItem::current($heading))); ?>

  <section class="page-hero">
    <div class="container">
      <h1><?= $h($heading) ?></h1>
      <form class="search-page__form" role="search" action="<?= $h($searchPath) ?>" method="get" aria-label="<?= SiteText::escaped(['nl' => 'Zoeken op deze website', 'en' => 'Search this website']) ?>">
        <label class="visually-hidden" for="search-page-input"><?= SiteText::escaped(['nl' => 'Zoek op deze website', 'en' => 'Search this website']) ?></label>
        <input class="site-search__input" id="search-page-input" type="search" name="q" value="<?= $h($query->text) ?>" maxlength="<?= SearchQuery::MAX_LENGTH ?>" autocomplete="off" spellcheck="false" enterkeyhint="search" placeholder="<?= SiteText::escaped(['nl' => 'Waar ben je naar op zoek?', 'en' => 'What are you looking for?']) ?>">
        <button class="site-search__submit" type="submit"><?= SiteText::escaped(['nl' => 'Zoeken', 'en' => 'Search']) ?></button>
      </form>
    </div>
  </section>

  <section class="search-page__section">
    <div class="container search-page__body">
      <div role="status" aria-live="polite">
      <?php if ($failed): ?>
        <p class="search-page__empty"><?= SiteText::escaped(['nl' => 'Zoeken lukt op dit moment niet. Probeer het later nog eens.', 'en' => 'Search is not available right now. Please try again later.']) ?></p>
      <?php elseif ($query->isEmpty()): ?>
        <p class="search-page__empty"><?= SiteText::escaped(['nl' => 'Typ hierboven waar je naar zoekt: een pagina, een product, een project of een onderwerp.', 'en' => 'Type what you are looking for above: a page, a product, a project or a topic.']) ?></p>
      <?php elseif ($query->isTooShort()): ?>
        <p class="search-page__empty"><?= $h(sprintf(SiteText::pick(['nl' => 'Typ minstens %d tekens om te zoeken.', 'en' => 'Type at least %d characters to search.']), SearchQuery::MIN_LENGTH)) ?></p>
      <?php elseif ($results->total === 0): ?>
        <p class="search-page__empty"><?= $h(sprintf(SiteText::pick(['nl' => 'Geen resultaten voor “%s”. Probeer een ander of korter woord.', 'en' => 'No results for “%s”. Try another or a shorter word.']), $query->text)) ?></p>
      <?php else: ?>
        <p class="search-page__summary"><?= $h(sprintf(
            $results->total === 1
                ? SiteText::pick(['nl' => '1 resultaat voor “%2$s”', 'en' => '1 result for “%2$s”'])
                : SiteText::pick(['nl' => '%1$d resultaten voor “%2$s”', 'en' => '%1$d results for “%2$s”']),
            $results->total,
            $query->text
        )) ?></p>
      <?php endif; ?>
      <?php if (!$failed && $results->failedTypes !== []): ?>
        <p class="search-page__empty"><?= SiteText::escaped(['nl' => 'Niet alles kon worden doorzocht; de resultaten hieronder zijn misschien niet compleet.', 'en' => 'Not everything could be searched; the results below may be incomplete.']) ?></p>
      <?php endif; ?>
      </div>

      <?php if ($results->hits !== []): ?>
        <ol class="search-page__results" start="<?= ($results->page - 1) * $results->perPage + 1 ?>">
          <?php foreach ($results->hits as $hit): ?>
            <li>
              <a class="search-hit<?= $hit->thumbnail === null ? ' search-hit--no-picture' : '' ?>" href="<?= $h($hit->url) ?>">
                <?php if ($hit->thumbnail !== null): ?>
                  <img class="search-hit__picture" src="<?= $h($hit->thumbnail) ?>" alt="" width="72" height="72" loading="lazy" decoding="async">
                <?php endif; ?>
                <span class="search-hit__body">
                  <span class="search-hit__type"><?= $h($hit->typeLabel) ?></span>
                  <span class="search-hit__title"><?= $h($hit->title) ?></span>
                  <?php if ($hit->excerpt !== ''): ?>
                    <span class="search-hit__excerpt"><?= $h($hit->excerpt) ?></span>
                  <?php endif; ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <?php /* Previous/next plus "pagina X van Y", as blog.php's pager. */ ?>
      <?php if ($results->pages() > 1): ?>
        <nav class="search-pager" aria-label="<?= SiteText::escaped(['nl' => 'Paginering', 'en' => 'Pagination']) ?>">
          <?php if ($results->hasPrevious()): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl($results->page - 1)) ?>" rel="prev"><?= SiteText::escaped(['nl' => 'Vorige', 'en' => 'Previous']) ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
          <p class="search-pager__status"><?= $h(sprintf(SiteText::pick(['nl' => 'Pagina %d van %d', 'en' => 'Page %d of %d']), $results->page, $results->pages())) ?></p>
          <?php if ($results->hasNext()): ?>
            <a class="btn btn--ghost btn--sm" href="<?= $h($pageUrl($results->page + 1)) ?>" rel="next"><?= SiteText::escaped(['nl' => 'Volgende', 'en' => 'Next']) ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php require __DIR__ . '/partials/page-scripts.php'; ?>
</body>
</html>
