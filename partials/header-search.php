<?php

/**
 * The site search in the shared header (SEARCH.md), rendered by
 * partials/header.php into .header-actions — only while search is switched
 * on (App\Service\Search\SearchService::isEnabled(), Navigatie → "Zoeken
 * tonen"). Off, nothing of it is printed, and its CSS and JS are not loaded
 * (App\Service\PageAssets), so an existing site's header does not change.
 *
 * ONE CONTROL FOR BOTH WIDTHS. On a wide screen the magnifier opens a
 * compact panel under the header; inside the phone menu (the same
 * .main-nav, see core.css) the panel is simply shown as a search field at
 * the top of the menu and the magnifier is hidden (search.css).
 *
 * WITHOUT JAVASCRIPT the magnifier is an ordinary link to the results page,
 * and the form inside the panel is an ordinary GET form to that page — the
 * page works without the script (zoeken.php). assets/js/search.js turns the
 * link into a disclosure button and adds the live results.
 *
 * Every visitor-facing word is here, in the page's language, and reaches the
 * script as data-* text: the script never builds a sentence of its own.
 */

use App\Service\Language\SiteText;
use App\Service\Routing\RequestLanguage;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchService;

if (!SearchService::isEnabled()) {
    return;
}

$searchH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$searchPath = SearchService::path();
$searchText = [
    'short' => SiteText::pick(['nl' => 'Typ minstens %d tekens.', 'en' => 'Type at least %d characters.']),
    'loading' => SiteText::pick(['nl' => 'Zoeken…', 'en' => 'Searching…']),
    'none' => SiteText::pick(['nl' => 'Geen resultaten voor “%s”.', 'en' => 'No results for “%s”.']),
    'one' => SiteText::pick(['nl' => '1 resultaat', 'en' => '1 result']),
    'many' => SiteText::pick(['nl' => '%d resultaten', 'en' => '%d results']),
    'error' => SiteText::pick(['nl' => 'Zoeken lukt nu niet. Druk op Enter om het op de zoekpagina te proberen.', 'en' => 'Search is not available right now. Press Enter to try the search page.']),
];
?>
        <div class="site-search" data-site-search
             data-search-endpoint="/api/search.php"
             data-search-lang="<?= $searchH(RequestLanguage::current()) ?>"
             data-search-min="<?= SearchQuery::MIN_LENGTH ?>"
<?php foreach ($searchText as $searchKey => $searchValue): ?>
             data-text-<?= $searchH($searchKey) ?>="<?= $searchH($searchValue) ?>"
<?php endforeach; ?>
        >
          <a class="site-search__toggle" href="<?= $searchH($searchPath) ?>" data-site-search-toggle aria-controls="site-search-panel" aria-label="<?= SiteText::escaped(['nl' => 'Zoeken', 'en' => 'Search']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5"/></svg>
          </a>
          <div class="site-search__panel" id="site-search-panel" data-site-search-panel>
            <form class="site-search__form" role="search" action="<?= $searchH($searchPath) ?>" method="get" aria-label="<?= SiteText::escaped(['nl' => 'Zoeken op deze website', 'en' => 'Search this website']) ?>">
              <label class="visually-hidden" for="site-search-input"><?= SiteText::escaped(['nl' => 'Zoek op deze website', 'en' => 'Search this website']) ?></label>
              <input class="site-search__input" id="site-search-input" type="search" name="q" maxlength="<?= SearchQuery::MAX_LENGTH ?>" autocomplete="off" spellcheck="false" enterkeyhint="search" placeholder="<?= SiteText::escaped(['nl' => 'Zoeken…', 'en' => 'Search…']) ?>" aria-describedby="site-search-status" data-site-search-input>
              <button class="site-search__submit" type="submit"><?= SiteText::escaped(['nl' => 'Zoeken', 'en' => 'Search']) ?></button>
              <button class="site-search__close" type="button" data-site-search-close aria-label="<?= SiteText::escaped(['nl' => 'Zoeken sluiten', 'en' => 'Close search']) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
              </button>
            </form>
            <p class="site-search__status" id="site-search-status" role="status" aria-live="polite" data-site-search-status></p>
            <ul class="site-search__results" data-site-search-results aria-label="<?= SiteText::escaped(['nl' => 'Zoekresultaten', 'en' => 'Search results']) ?>" hidden></ul>
            <a class="site-search__all" href="<?= $searchH($searchPath) ?>" data-site-search-all hidden><?= SiteText::escaped(['nl' => 'Alle resultaten bekijken', 'en' => 'View all results']) ?></a>
          </div>
        </div>
